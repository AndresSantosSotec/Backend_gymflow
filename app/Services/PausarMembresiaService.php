<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Membership;
use App\Models\MembershipPause;
use App\Models\RecurrenteSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PausarMembresiaService
 *
 * Gestiona el ciclo de vida completo de pausas de membresía:
 *
 * ─── pausar()       → Pausa Recurrente, empuja fechas de cuotas
 * ─── cancelarPausa() → Reactiva Recurrente antes de tiempo
 * ─── calcularImpacto() → Preview: muestra fechas nuevas sin aplicar
 * ─── reactivarTarjeta() → Cliente vuelve a tarjeta tras efectivo
 *
 * Reglas de negocio:
 * - Max pause_days configurable por cliente (default 60)
 * - Durante pausa, Recurrente NO cobra
 * - Las cuotas se empujan hacia adelante por los días pausados
 * - Al reanudar, se crea nueva suscripción desde pause_end
 * - Admin puede aprobar/rechazar pausas> X días (configurable)
 */
class PausarMembresiaService
{
    public function __construct(
        private RecurrenteService $recurrente,
        private PagoAdelantoService $adelantoService,
    ) {}

    // ─────────────────────────────────────────────────────────────────
    //  CASO 2 — PAUSAR MEMBRESÍA
    // ─────────────────────────────────────────────────────────────────

    /**
     * Pausar membresía: cancela Recurrente, empuja cuotas, programa reanudación.
     *
     * @param int    $membershipId
     * @param string $pauseStart    'Y-m-d'
     * @param string $pauseEnd      'Y-m-d'
     * @param string $reason        travel|injury|other
     * @param string $notes
     * @param int    $adminId       Quién aprueba
     * @return array                Resumen con nuevas fechas de cuotas
     */
    public function pausar(
        int    $membershipId,
        string $pauseStart,
        string $pauseEnd,
        string $reason,
        string $notes,
        int    $adminId,
    ): array {
        return DB::transaction(function () use ($membershipId, $pauseStart, $pauseEnd, $reason, $notes, $adminId) {

            $membership = Membership::where('id', $membershipId)
                ->whereNotIn('status', [Membership::STATUS_CANCELLED, Membership::STATUS_EXPIRED])
                ->with(['client', 'plan', 'installments'])
                ->lockForUpdate()
                ->firstOrFail();

            $start    = Carbon::parse($pauseStart);
            $end      = Carbon::parse($pauseEnd);
            $pauseDays = $start->diffInDays($end);

            // ── Validaciones ───────────────────────────────────────────

            if ($start->lt(today())) {
                throw new \Exception("La fecha de inicio de pausa no puede ser en el pasado.");
            }
            if ($end->lte($start)) {
                throw new \Exception("La fecha de regreso debe ser posterior al inicio de la pausa.");
            }
            if ($membership->activePause()->exists()) {
                throw new \Exception("Esta membresía ya tiene una pausa activa. Cancélala primero.");
            }

            // ANOMALÍA D — Verificar límite de días
            if (! $membership->canPause($pauseDays)) {
                $disponibles = $membership->max_pause_days - $membership->total_paused_days;
                throw new \Exception(
                    "Límite de días de pausa excedido. Disponibles: {$disponibles} días. " .
                    "Solicitados: {$pauseDays} días. Requiere aprobación especial de admin."
                );
            }

            // ── 1. Cancelar suscripción activa en Recurrente ──────────
            $subCancelada = null;
            $subActiva = RecurrenteSubscription::where('client_id', $membership->client_id)
                ->where('status', 'active')
                ->first();

            if ($subActiva) {
                try {
                    $this->recurrente->cancelSubscription($subActiva->recurrente_subscription_id);
                    $subActiva->update(['status' => 'cancelled']);
                    $subCancelada = $subActiva->recurrente_subscription_id;
                    Log::info("[PausarMembresiaService] Sub cancelada: {$subCancelada}");
                } catch (\Exception $e) {
                    // Si ya estaba cancelada en Recurrente, no es error fatal
                    Log::warning("[PausarMembresiaService] No se pudo cancelar sub (posible ya cancelada): " . $e->getMessage());
                    $subActiva->update(['status' => 'cancelled']);
                    $subCancelada = $subActiva->recurrente_subscription_id;
                }
            }

            // ── 2. Empujar fechas de cuotas futuras ───────────────────
            $cuotasAntes = $membership->installments()
                ->where('status', '!=', 'paid')
                ->where('due_date', '>=', $start)
                ->orderBy('due_date')
                ->get();

            $membership->pushFutureInstallmentDates($pauseDays);

            $cuotasDespues = $membership->fresh()->installments()
                ->where('status', '!=', 'paid')
                ->orderBy('due_date')
                ->get();

            // ── 3. Actualizar advance_end_date si corresponde ─────────
            if ($membership->advance_end_date && $membership->advance_end_date->gte($start)) {
                $membership->advance_end_date = Carbon::parse($membership->advance_end_date)->addDays($pauseDays);
            }

            // ── 4. Actualizar estado de membresía ─────────────────────
            $membership->update([
                'status'                => Membership::STATUS_PAUSED,
                'total_paused_days'     => $membership->total_paused_days + $pauseDays,
                'recurrente_status'     => 'paused',
                'advance_end_date'      => $membership->advance_end_date,
            ]);

            // ── 5. Registrar la pausa ─────────────────────────────────
            $pausa = MembershipPause::create([
                'membership_id'            => $membership->id,
                'client_id'                => $membership->client_id,
                'approved_by'              => $adminId,
                'pause_start'              => $start->toDateString(),
                'pause_end'                => $end->toDateString(),
                'pause_days'               => $pauseDays,
                'reason'                   => $reason,
                'notes'                    => $notes,
                'recurrente_sub_cancelled' => $subCancelada,
                'status'                   => 'active',
            ]);

            // ── 6. Audit log ──────────────────────────────────────────
            $this->adelantoService->writeAuditLog(
                clientId:        $membership->client_id,
                localSubId:      $subActiva?->id,
                recurrenteSubId: $subCancelada,
                accion:          'pausa_creada',
                estadoAnterior:  Membership::STATUS_ACTIVE,
                estadoNuevo:     Membership::STATUS_PAUSED,
                motivo:          "{$reason}: {$notes}",
                userId:          $adminId,
                metadata:        [
                    'pause_id'   => $pausa->id,
                    'pause_days' => $pauseDays,
                    'pause_end'  => $end->toDateString(),
                ],
            );

            // ── 7. Construir resumen con impacto ─────────────────────
            $comparacion = [];
            foreach ($cuotasAntes as $index => $cuotaAntes) {
                $cuotaDespues = $cuotasDespues->get($index);
                $comparacion[] = [
                    'installment_number' => $cuotaAntes->installment_number,
                    'date_before'        => $cuotaAntes->due_date->format('d/m/Y'),
                    'date_after'         => $cuotaDespues?->due_date->format('d/m/Y'),
                ];
            }

            Log::info("[PausarMembresiaService] ✅ Pausa #{$pausa->id} creada. Membresía #{$membership->id}, {$pauseDays} días.");

            return [
                'pause_id'          => $pausa->id,
                'pause_days'        => $pauseDays,
                'pause_start'       => $start->format('d/m/Y'),
                'pause_end'         => $end->format('d/m/Y'),
                'proximo_cobro'     => $end->format('d/m/Y'),
                'sub_cancelada'     => $subCancelada,
                'cuotas_ajustadas'  => count($comparacion),
                'comparacion_cuotas' => $comparacion,
                'message'           => "✅ Pausa registrada. Recurrente pausado. Las cuotas se extendieron {$pauseDays} días.",
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  CANCELAR PAUSA (reactivar antes de tiempo)
    // ─────────────────────────────────────────────────────────────────

    public function cancelarPausa(int $pauseId, int $adminId, string $motivo = ''): array
    {
        return DB::transaction(function () use ($pauseId, $adminId, $motivo) {

            $pausa = MembershipPause::where('id', $pauseId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();

            $membership = $pausa->membership;
            $client     = $membership->client;
            $plan       = $membership->plan;

            // Calcular días que NO se usaron de la pausa
            $diasNoUsados = today()->diffInDays(Carbon::parse($pausa->pause_end), false);

            // Empujar cuotas HACIA ATRÁS (revertir parcialmente)
            if ($diasNoUsados > 0) {
                $membership->installments()
                    ->where('status', '!=', 'paid')
                    ->where('due_date', '>=', today())
                    ->orderBy('due_date')
                    ->get()
                    ->each(function ($inst) use ($diasNoUsados) {
                        $inst->update([
                            'due_date' => $inst->due_date->subDays($diasNoUsados),
                            'notes'    => ($inst->notes ?? '') . " [Pausa cancelada antes: -{$diasNoUsados}d]",
                        ]);
                    });
            }

            // Crear nueva suscripción en Recurrente desde HOY
            $nuevaSub = null;
            if ($client?->recurrente_user_id && $plan?->recurrente_product_id) {
                $nuevaSub = $this->recurrente->createSubscription([
                    'user_id'    => $client->recurrente_user_id,
                    'product_id' => $plan->recurrente_product_id,
                ]);

                RecurrenteSubscription::create([
                    'client_id'                  => $membership->client_id,
                    'membership_plan_id'         => $membership->plan_id,
                    'recurrente_subscription_id' => $nuevaSub['id'],
                    'recurrente_product_id'      => $plan->recurrente_product_id,
                    'status'                     => 'active',
                    'idempotency_key'            => 'cancel_pausa_' . $pauseId . '_' . today()->format('Ymd'),
                    'creation_status'            => 'created',
                    'metadata'                   => $nuevaSub,
                ]);
            }

            // Actualizar pausa
            $pausa->update([
                'status'               => 'cancelled',
                'cancelled_at'         => now(),
                'cancellation_reason'  => $motivo,
                'recurrente_sub_new'   => $nuevaSub['id'] ?? null,
            ]);

            // Actualizar membresía
            $membership->update([
                'status'             => Membership::STATUS_ACTIVE,
                'recurrente_status'  => 'active',
                'reactivated_at'     => now(),
                // Reducir días pausados por los que no se usaron
                'total_paused_days'  => max(0, $membership->total_paused_days - $diasNoUsados),
            ]);

            return [
                'dias_no_usados'   => $diasNoUsados,
                'nueva_sub'        => $nuevaSub['id'] ?? null,
                'message'          => "✅ Pausa cancelada. Recurrente reactivado desde hoy.",
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  CALCULAR IMPACTO (preview — no aplica cambios)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Devuelve las fechas nuevas sin modificar nada en BD.
     * Usa en el frontend para mostrar el impacto antes de confirmar.
     */
    public function calcularImpacto(int $membershipId, string $pauseStart, string $pauseEnd): array
    {
        $membership = Membership::with('installments')->findOrFail($membershipId);
        $start      = Carbon::parse($pauseStart);
        $end        = Carbon::parse($pauseEnd);
        $pauseDays  = $start->diffInDays($end);

        $cuotasPendientes = $membership->installments()
            ->where('status', '!=', 'paid')
            ->where('due_date', '>=', $start)
            ->orderBy('due_date')
            ->get();

        $comparacion = $cuotasPendientes->map(fn ($c) => [
            'installment_number' => $c->installment_number,
            'amount'             => $c->amount,
            'date_before'        => $c->due_date->format('d/m/Y'),
            'date_after'         => $c->due_date->addDays($pauseDays)->format('d/m/Y'),
        ]);

        $puedePausar = $membership->canPause($pauseDays);

        return [
            'pause_days'             => $pauseDays,
            'pause_start_fmt'        => $start->format('d/m/Y'),
            'pause_end_fmt'          => $end->format('d/m/Y'),
            'proximo_cobro'          => $end->format('d/m/Y'),
            'cuotas_afectadas'       => $comparacion->count(),
            'comparacion'            => $comparacion,
            'dias_disponibles'       => $membership->max_pause_days - $membership->total_paused_days,
            'puede_pausar'           => $puedePausar,
            'mensaje_limite'         => ! $puedePausar
                ? "Excede el límite de " . ($membership->max_pause_days - $membership->total_paused_days) . " días disponibles"
                : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  CASO 4 — REACTIVAR TARJETA (efectivo → tarjeta)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Cliente decide volver a pagar con tarjeta desde la próxima cuota.
     *
     * @param int    $clientId
     * @param string $paymentMethodId  Token de tarjeta (existente o nuevo)
     * @param int    $fromInstallmentId  Desde qué cuota empezar con Recurrente
     * @param int    $adminId
     */
    public function reactivarTarjeta(
        int    $clientId,
        string $paymentMethodId,
        int    $fromInstallmentId,
        int    $adminId,
    ): array {
        return DB::transaction(function () use ($clientId, $paymentMethodId, $fromInstallmentId, $adminId) {

            $client = Client::findOrFail($clientId);

            // ── 1. Verificar cuotas anteriores pagadas ────────────────
            $cuotaDesdeDonde = \App\Models\PaymentInstallment::where('id', $fromInstallmentId)
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->firstOrFail();

            $cuotasAnterioresNoPagadas = \App\Models\PaymentInstallment::where('client_id', $clientId)
                ->where('installment_number', '<', $cuotaDesdeDonde->installment_number)
                ->where('status', '!=', 'paid')
                ->count();

            if ($cuotasAnterioresNoPagadas > 0) {
                throw new \Exception("Hay {$cuotasAnterioresNoPagadas} cuotas anteriores sin pagar. Deben estar pagadas antes de reactivar tarjeta.");
            }

            // ── 2. Verificar payment_method en Recurrente ─────────────
            if ($client->recurrente_user_id) {
                $methods = $this->recurrente->getPaymentMethods($client->recurrente_user_id);
                $valid   = collect($methods)->firstWhere('id', $paymentMethodId);

                if (! $valid) {
                    throw new \Exception("El método de pago {$paymentMethodId} no existe en Recurrente para este cliente.");
                }
            }

            // ── 3. Cancelar suscripción activa si existe ──────────────
            $subActiva = RecurrenteSubscription::where('client_id', $clientId)
                ->where('status', 'active')
                ->first();

            if ($subActiva) {
                $this->recurrente->cancelSubscription($subActiva->recurrente_subscription_id);
                $subActiva->update(['status' => 'cancelled']);
            }

            // ── 4. Actualizar payment_method_id del cliente ───────────
            $client->update(['recurrente_payment_method_id' => $paymentMethodId]);

            // ── 5. Crear suscripción desde la fecha de la cuota objetivo
            $membership = Membership::where('client_id', $clientId)
                ->where('status', '!=', Membership::STATUS_CANCELLED)
                ->first();

            $plan = $membership?->plan;

            if (! $client->recurrente_user_id || ! $plan?->recurrente_product_id) {
                throw new \Exception("Faltan datos de Recurrente en cliente o plan.");
            }

            $nuevaSub = $this->recurrente->createSubscription([
                'user_id'    => $client->recurrente_user_id,
                'product_id' => $plan->recurrente_product_id,
                // billing_start = fecha de la cuota objetivo
                'billing_start_date' => $cuotaDesdeDonde->due_date->toDateString(),
            ]);

            RecurrenteSubscription::create([
                'client_id'                  => $clientId,
                'membership_plan_id'         => $membership->plan_id,
                'recurrente_subscription_id' => $nuevaSub['id'],
                'recurrente_product_id'      => $plan->recurrente_product_id,
                'status'                     => 'active',
                'idempotency_key'            => 'reactivar_tarjeta_' . $clientId . '_' . today()->format('Ymd'),
                'creation_status'            => 'created',
                'metadata'                   => $nuevaSub,
            ]);

            // ── 6. Marcar cuotas futuras como programadas en tarjeta ──
            \App\Models\PaymentInstallment::where('client_id', $clientId)
                ->where('installment_number', '>=', $cuotaDesdeDonde->installment_number)
                ->where('status', '!=', 'paid')
                ->update(['payment_method' => 'tarjeta_programada']);

            // ── 7. Actualizar membresía ───────────────────────────────
            $membership?->update([
                'status'            => Membership::STATUS_ACTIVE,
                'advance_end_date'  => null,
                'wants_auto_renewal' => true,
                'recurrente_status' => 'active',
            ]);

            // Audit log
            $this->adelantoService->writeAuditLog(
                clientId:        $clientId,
                localSubId:      null,
                recurrenteSubId: $nuevaSub['id'],
                accion:          'reactivar_tarjeta',
                estadoAnterior:  Membership::STATUS_ADVANCE_ACTIVE,
                estadoNuevo:     Membership::STATUS_ACTIVE,
                motivo:          "Cliente vuelve a cobro automático desde cuota #{$cuotaDesdeDonde->installment_number}",
                userId:          $adminId,
                metadata:        ['nueva_sub' => $nuevaSub['id'], 'desde_cuota' => $fromInstallmentId],
            );

            return [
                'nueva_sub'       => $nuevaSub['id'],
                'desde_cuota'     => $cuotaDesdeDonde->installment_number,
                'primer_cobro'    => $cuotaDesdeDonde->due_date->format('d/m/Y'),
                'payment_method'  => $paymentMethodId,
                'message'         => "✅ Cobro automático reactivado desde la cuota #{$cuotaDesdeDonde->installment_number} ({$cuotaDesdeDonde->due_date->format('d/m/Y')}).",
            ];
        });
    }
}
