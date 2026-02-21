<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Membership;
use App\Models\MembershipPause;
use App\Models\RecurrenteSubscription;
use App\Services\PagoAdelantoService;
use App\Services\RecurrenteService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * ReactivarSuscripcionesJob
 *
 * Job diario que corre a las 00:01 y:
 *
 * 1. Alerta (status advance_expiring) — membresías que vencen en 7 días
 * 2. Reactiva Recurrente — membresías cuyo adelanto vence HOY
 * 3. Reanuda pausas — pausas activas cuya fecha_fin llegó hoy
 *
 * Anomalías cubiertas:
 *   A — Cliente canceló manualmente → canBeReactivated() guard
 *   B — payment_method_id expirado → GET validación antes de crear sub
 *   C — Cuota ya pagada + Recurrente cobra → conciliation job lo detecta
 *   D — Pausa excede límite → PausarMembresiaService valida antes
 *   E — Job falla → retry 3× cada 1h. Si falla definitivo → admin alert
 *   F — Cliente intenta suscribirse con adelanto activo → isInAdvanceMode()
 */
class ReactivarSuscripcionesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 3600; // 1 hora entre reintentos

    // Resumen del día para reporte al admin
    private int $reactivadas  = 0;
    private int $enRiesgo     = 0;
    private int $alertas      = 0;
    private int $pausasReanudadas = 0;
    private array $errores    = [];

    public function handle(RecurrenteService $recurrente, PagoAdelantoService $adelantoService): void
    {
        Log::info('[ReactivarSuscripciones] ▶ Iniciando job diario ' . now()->toDateTimeString());

        // ─── FASE 1: Alertas 7 días antes ─────────────────────────────
        $this->procesarAlertas($adelantoService);

        // ─── FASE 2: Reactivaciones del día ───────────────────────────
        $this->procesarReactivaciones($recurrente, $adelantoService);

        // ─── FASE 3: Reanudar pausas que terminan hoy ─────────────────
        $this->procesarPausasVencidas($recurrente, $adelantoService);

        // ─── FASE 4: Enviar resumen al admin ─────────────────────────
        $this->enviarResumenAdmin();

        Log::info('[ReactivarSuscripciones] ✅ Job completado', [
            'reactivadas'       => $this->reactivadas,
            'en_riesgo'         => $this->enRiesgo,
            'alertas'           => $this->alertas,
            'pausas_reanudadas' => $this->pausasReanudadas,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  FASE 1 — Alerta previa: vence en 7 días
    // ─────────────────────────────────────────────────────────────────

    private function procesarAlertas(PagoAdelantoService $adelantoService): void
    {
        $proximas = Membership::advancedExpiring(Membership::EXPIRING_ALERT_DAYS)
            ->with(['client', 'plan'])
            ->get();

        foreach ($proximas as $membership) {
            try {
                $membership->update(['status' => Membership::STATUS_ADVANCE_EXPIRING]);

                // Notificar al cliente
                $this->notificarCliente($membership, 'advance_expiring');

                // Notify admin (silent, no email separado — va en el resumen)
                $adelantoService->writeAuditLog(
                    clientId:        $membership->client_id,
                    localSubId:      null,
                    recurrenteSubId: null,
                    accion:          'alerta_vencimiento_adelanto',
                    estadoAnterior:  Membership::STATUS_ADVANCE_ACTIVE,
                    estadoNuevo:     Membership::STATUS_ADVANCE_EXPIRING,
                    motivo:          'Adelanto vence en ' . Membership::EXPIRING_ALERT_DAYS . ' días',
                    userId:          null,
                    metadata:        ['advance_end_date' => $membership->advance_end_date?->toDateString()],
                );

                $this->alertas++;
                Log::info("[ReactivarSuscripciones] ⚠ Alerta emitida: membresía #{$membership->id} cliente #{$membership->client_id}");

            } catch (\Throwable $e) {
                Log::warning("[ReactivarSuscripciones] Error en alerta #{$membership->id}: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  FASE 2 — Reactivar Recurrente (adelanto vence HOY)
    // ─────────────────────────────────────────────────────────────────

    private function procesarReactivaciones(RecurrenteService $recurrente, PagoAdelantoService $adelantoService): void
    {
        $membresiasPorVencer = Membership::advancedExpiringToday()
            ->with(['client', 'plan'])
            ->get();

        foreach ($membresiasPorVencer as $membership) {
            // ── ANOMALÍA A — Ya cancelada manualmente ─────────────────
            if (! $membership->canBeReactivated()) {
                Log::info("[ReactivarSuscripciones] Skip #{$membership->id}: status={$membership->status} (no reactivable)");
                continue;
            }

            // ── Verificar que no haya ya una suscripción activa ────────
            $subActiva = RecurrenteSubscription::where('client_id', $membership->client_id)
                ->where('status', 'active')
                ->first();

            if ($subActiva) {
                Log::warning("[ReactivarSuscripciones] Cliente #{$membership->client_id} ya tiene sub activa {$subActiva->recurrente_subscription_id}. Skip.");
                $membership->update(['status' => Membership::STATUS_ACTIVE]);
                continue;
            }

            try {
                DB::transaction(function () use ($membership, $recurrente, $adelantoService) {

                    $client = $membership->client;
                    $plan   = $membership->plan;

                    if (! $client?->recurrente_user_id || ! $plan?->recurrente_product_id) {
                        throw new \Exception("Cliente sin recurrente_user_id o plan sin recurrente_product_id");
                    }

                    // ── ANOMALÍA B — Validar que el payment_method sigue vigente
                    if ($client->recurrente_payment_method_id) {
                        $this->validarPaymentMethod($recurrente, $client);
                    }

                    // Idempotency key única para este intento
                    $idempotencyKey = 'reactivar_' . $membership->id . '_' . today()->format('Ymd');

                    // Verificar si ya se creó la suscripción (retry del Job)
                    $subExistente = RecurrenteSubscription::where('idempotency_key', $idempotencyKey)
                        ->whereIn('creation_status', ['created', 'pending_confirmation'])
                        ->first();

                    if ($subExistente) {
                        Log::info("[ReactivarSuscripciones] Suscripción ya existe (idempotency): {$subExistente->recurrente_subscription_id}");
                        $membership->update([
                            'status'         => Membership::STATUS_ACTIVE,
                            'reactivated_at' => now(),
                        ]);
                        return;
                    }

                    // Guardar idempotency_key ANTES de hacer el request
                    $subLocal = RecurrenteSubscription::create([
                        'client_id'                  => $membership->client_id,
                        'membership_plan_id'         => $membership->plan_id,
                        'recurrente_subscription_id' => 'pending_' . $idempotencyKey,
                        'recurrente_product_id'      => $plan->recurrente_product_id,
                        'status'                     => 'active',
                        'idempotency_key'            => $idempotencyKey,
                        'creation_status'            => 'pending_confirmation',
                    ]);

                    // Crear la suscripción en Recurrente
                    $nuevaSub = $recurrente->createSubscription([
                        'user_id'    => $client->recurrente_user_id,
                        'product_id' => $plan->recurrente_product_id,
                        // billing_start = hoy (ya es el nuevo ciclo)
                    ]);

                    // Actualizar con el ID real
                    $subLocal->update([
                        'recurrente_subscription_id' => $nuevaSub['id'],
                        'creation_status'            => 'created',
                        'metadata'                   => $nuevaSub,
                    ]);

                    // Actualizar membresía
                    $membership->update([
                        'status'             => Membership::STATUS_ACTIVE,
                        'advance_end_date'   => null,
                        'reactivated_at'     => now(),
                        'reactivation_error' => null,
                        'reactivation_error_at' => null,
                        'recurrente_status'  => 'active',
                    ]);

                    // Notificar al cliente exitosamente reactivado
                    $this->notificarCliente($membership, 'reactivated');

                    // Audit log
                    $adelantoService->writeAuditLog(
                        clientId:        $membership->client_id,
                        localSubId:      $subLocal->id,
                        recurrenteSubId: $nuevaSub['id'],
                        accion:          'reactivacion_automatica',
                        estadoAnterior:  Membership::STATUS_ADVANCE_EXPIRING,
                        estadoNuevo:     Membership::STATUS_ACTIVE,
                        motivo:          'Adelanto vencido — reactivación automática por Job',
                        userId:          null,
                        metadata:        ['nueva_sub' => $nuevaSub['id']],
                    );

                    $this->reactivadas++;
                    Log::info("[ReactivarSuscripciones] ✅ Reactivada membresía #{$membership->id} → sub {$nuevaSub['id']}");
                });

            } catch (\Throwable $e) {
                // ── ANOMALÍA E — Si falla, marcar en riesgo (no alertar al cliente aún)
                $membership->update([
                    'status'                => Membership::STATUS_AT_RISK,
                    'reactivation_error'    => $e->getMessage(),
                    'reactivation_error_at' => now(),
                ]);

                $this->enRiesgo++;
                $this->errores[] = "Membresía #{$membership->id} (cliente #{$membership->client_id}): " . $e->getMessage();

                Log::error("[ReactivarSuscripciones] ❌ Falla en #{$membership->id}: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  FASE 3 — Reanudar pausas que terminan hoy
    // ─────────────────────────────────────────────────────────────────

    private function procesarPausasVencidas(RecurrenteService $recurrente, PagoAdelantoService $adelantoService): void
    {
        $pausasVencidas = MembershipPause::where('status', 'active')
            ->whereDate('pause_end', '<=', today())
            ->with(['membership.client', 'membership.plan'])
            ->get();

        foreach ($pausasVencidas as $pausa) {
            $membership = $pausa->membership;

            if (! $membership || ! $membership->canBeReactivated()) {
                $pausa->update(['status' => 'completed', 'completed_at' => now()]);
                continue;
            }

            try {
                DB::transaction(function () use ($pausa, $membership, $recurrente, $adelantoService) {

                    $client = $membership->client;
                    $plan   = $membership->plan;

                    if (! $client?->recurrente_user_id || ! $plan?->recurrente_product_id) {
                        throw new \Exception("Faltan datos de Recurrente en cliente/plan");
                    }

                    $nuevaSub = $recurrente->createSubscription([
                        'user_id'    => $client->recurrente_user_id,
                        'product_id' => $plan->recurrente_product_id,
                    ]);

                    RecurrenteSubscription::create([
                        'client_id'                  => $membership->client_id,
                        'membership_plan_id'         => $membership->plan_id,
                        'recurrente_subscription_id' => $nuevaSub['id'],
                        'recurrente_product_id'      => $plan->recurrente_product_id,
                        'status'                     => 'active',
                        'idempotency_key'            => 'pausa_end_' . $pausa->id . '_' . today()->format('Ymd'),
                        'creation_status'            => 'created',
                        'metadata'                   => $nuevaSub,
                    ]);

                    $pausa->update([
                        'status'            => 'completed',
                        'completed_at'      => now(),
                        'recurrente_sub_new' => $nuevaSub['id'],
                    ]);

                    $membership->update([
                        'status'         => Membership::STATUS_ACTIVE,
                        'reactivated_at' => now(),
                    ]);

                    $this->notificarCliente($membership, 'pause_ended');
                    $this->pausasReanudadas++;

                    Log::info("[ReactivarSuscripciones] ▶️ Pausa #{$pausa->id} finalizada, nueva sub: {$nuevaSub['id']}");
                });

            } catch (\Throwable $e) {
                Log::error("[ReactivarSuscripciones] Error reanudando pausa #{$pausa->id}: " . $e->getMessage());
                $membership->update([
                    'status'                => Membership::STATUS_AT_RISK,
                    'reactivation_error'    => "Pausa #{$pausa->id} no pudo reanudar: " . $e->getMessage(),
                    'reactivation_error_at' => now(),
                ]);
                $this->enRiesgo++;
                $this->errores[] = "Pausa #{$pausa->id}: " . $e->getMessage();
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  ANOMALÍA B — Validar payment_method_id
    // ─────────────────────────────────────────────────────────────────

    private function validarPaymentMethod(RecurrenteService $recurrente, Client $client): void
    {
        try {
            $methods = $recurrente->getPaymentMethods($client->recurrente_user_id);
            $valid   = collect($methods)->firstWhere('id', $client->recurrente_payment_method_id);

            if (! $valid) {
                // Token expirado → limpiar del cliente
                $client->update(['recurrente_payment_method_id' => null]);
                throw new \Exception("payment_method_id {$client->recurrente_payment_method_id} ya no es válido en Recurrente. Se limpió del cliente.");
            }
        } catch (\Exception $e) {
            // Si GET falla por otro motivo, no bloquear la reactivación
            Log::warning("[ReactivarSuscripciones] No se pudo validar payment_method: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  NOTIFICACIONES
    // ─────────────────────────────────────────────────────────────────

    private function notificarCliente(Membership $membership, string $tipo): void
    {
        try {
            $client = $membership->client;
            if (! $client?->email) return;

            $subject = match($tipo) {
                'advance_expiring' => '⚠️ Tu membresía cambia en 7 días — Gymflow',
                'reactivated'      => '✅ Tu cobro automático se ha reactivado — Gymflow',
                'pause_ended'      => '▶️ Tu membresía ha sido reactivada — Gymflow',
                default            => 'Actualización de membresía — Gymflow',
            };

            $html = match($tipo) {
                'advance_expiring' => "
                    <h2>⚠️ Hola {$client->first_name}</h2>
                    <p>Tus meses prepagados terminan el <strong>{$membership->advance_end_date?->format('d/m/Y')}</strong>.</p>
                    <p>A partir de esa fecha, tu cobro automático con tarjeta se reactivará normalmente.</p>
                    <p>Si tienes preguntas, contacta al equipo del gimnasio.</p>
                ",
                'reactivated'      => "
                    <h2>✅ Hola {$client->first_name}</h2>
                    <p>Tus meses prepagados han concluido. Tu suscripción automática fue <strong>reactivada hoy</strong>.</p>
                    <p>Tu próximo cobro será el próximo mes según el plan <strong>{$membership->plan?->name}</strong>.</p>
                ",
                'pause_ended'      => "
                    <h2>▶️ Hola {$client->first_name}</h2>
                    <p>Tu pausa ha finalizado. Tu membresía está <strong>activa nuevamente</strong>.</p>
                    <p>El cobro automático se retoma a partir de hoy.</p>
                ",
                default => "<p>Tu membresía ha sido actualizada.</p>",
            };

            Mail::send([], [], function ($m) use ($client, $subject, $html) {
                $m->to($client->email, $client->first_name . ' ' . $client->last_name)
                  ->subject($subject)
                  ->html($html);
            });

            DB::table('notification_log')->insert([
                'client_id'  => $client->id,
                'type'       => $tipo,
                'channel'    => 'email',
                'status'     => 'sent',
                'payload'    => json_encode(['membership_id' => $membership->id]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        } catch (\Throwable $e) {
            Log::warning("[ReactivarSuscripciones] Email no enviado ({$tipo}): " . $e->getMessage());
            DB::table('notification_log')->insert([
                'client_id'     => $membership->client_id,
                'type'          => $tipo,
                'channel'       => 'email',
                'status'        => 'failed',
                'error_message' => substr($e->getMessage(), 0, 500),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    private function enviarResumenAdmin(): void
    {
        $adminEmail = config('mail.admin_email', 'admin@gymflow.com');

        $resumen = "
            <h2>📊 Resumen diario de membresías — " . today()->format('d/m/Y') . "</h2>
            <table border='1' cellpadding='8' style='border-collapse:collapse'>
              <tr><td>✅ Reactivadas automáticamente</td><td><strong>{$this->reactivadas}</strong></td></tr>
              <tr><td>⚠️ Alertas emitidas (vencen en 7d)</td><td><strong>{$this->alertas}</strong></td></tr>
              <tr><td>▶️ Pausas finalizadas</td><td><strong>{$this->pausasReanudadas}</strong></td></tr>
              <tr><td>🔴 En riesgo (fallo de reactivación)</td><td><strong>{$this->enRiesgo}</strong></td></tr>
            </table>
        ";

        if (! empty($this->errores)) {
            $resumen .= "<h3>🔴 Errores que requieren atención:</h3><ul>";
            foreach ($this->errores as $error) {
                $resumen .= "<li>{$error}</li>";
            }
            $resumen .= "</ul><p><strong>Ir a: /admin/memberships/risk</strong></p>";
        }

        try {
            Mail::send([], [], function ($m) use ($adminEmail, $resumen) {
                $m->to($adminEmail)
                  ->subject('📊 Gymflow — Resumen de membresías ' . today()->format('d/m/Y'))
                  ->html($resumen);
            });
        } catch (\Throwable $e) {
            Log::warning("[ReactivarSuscripciones] No se pudo enviar resumen al admin: " . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('[ReactivarSuscripciones] ❌❌ JOB FALLIDO DEFINITIVAMENTE: ' . $exception->getMessage());

        // Intentar notificar al admin aunque el job haya muerto
        try {
            $adminEmail = config('mail.admin_email', 'admin@gymflow.com');
            Mail::send([], [], function ($m) use ($adminEmail, $exception) {
                $m->to($adminEmail)
                  ->subject('🚨 CRÍTICO — ReactivarSuscripcionesJob falló ' . now()->format('d/m/Y H:i'))
                  ->html("
                    <h2>🚨 El Job de reactivación automática falló definitivamente</h2>
                    <p>Error: {$exception->getMessage()}</p>
                    <p>Las membresías que vencían hoy NO fueron reactivadas automáticamente.</p>
                    <p><strong>Acción requerida:</strong> Reactivar manualmente desde /admin/memberships/risk</p>
                  ");
            });
        } catch (\Throwable) {}
    }
}
