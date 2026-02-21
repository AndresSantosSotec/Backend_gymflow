<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\PaymentInstallment;
use App\Models\RecurrenteSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PagoAdelantoReversionService
 *
 * FIX 4.1 — Anular un pago adelantado ya registrado.
 *
 * Flujo de reversión:
 * 1. Validar que el log no haya sido revertido ya
 * 2. Desmarcar cuotas (solo las que pagó este log concreto)
 * 3. Si Recurrente fue modificado:
 *    a. Cancelar la nueva suscripción programada
 *    b. Reactivar la original (o crear una nueva desde ahora)
 * 4. Requerir motivo y autorización de admin
 * 5. Registrar en audit log
 *
 * FIX 4.2 — Cálculo de reembolso para pagos en efectivo.
 * FIX 4.3 — Upgrade/downgrade de plan con crédito restante.
 */
class PagoAdelantoReversionService
{
    public function __construct(
        private RecurrenteService   $recurrente,
        private PagoAdelantoService $adelantoService
    ) {}

    // ─────────────────────────────────────────────────────────────
    //  FIX 4.1 — ANULAR PAGO ADELANTADO
    // ─────────────────────────────────────────────────────────────

    /**
     * Anula un pago adelantado previamente registrado.
     *
     * @param int    $advanceLogId      ID en advance_payment_logs
     * @param int    $adminId           Quien autoriza la reversión
     * @param string $motivo            Razón de la reversión
     * @return array                    Resumen de la operación
     * @throws \Exception               Si ya fue revertido o no es reversible
     */
    public function anularPagoAdelantado(int $advanceLogId, int $adminId, string $motivo): array
    {
        return DB::transaction(function () use ($advanceLogId, $adminId, $motivo) {

            // ── 1. Obtener y validar el log ───────────────────────────────
            $log = DB::table('advance_payment_logs')->where('id', $advanceLogId)->lockForUpdate()->first();

            if (! $log) {
                throw new \Exception("Log de pago adelantado #{$advanceLogId} no encontrado.");
            }
            if ($log->reversed) {
                throw new \Exception("Este pago adelantado ya fue revertido el " .
                    Carbon::parse($log->reversed_at)->format('d/m/Y H:i') . ".");
            }
            if (! $log->success) {
                throw new \Exception("Este pago adelantado falló en su registro original y no requiere reversión.");
            }

            $installmentIds = json_decode($log->installment_ids, true);

            // ── 2. Verificar que las cuotas no hayan sido consumidas ──────
            // (el cliente ya asistió esos meses — no se puede revertir fácilmente)
            $cuotasConsumidas = PaymentInstallment::whereIn('id', $installmentIds)
                ->where('due_date', '<', now())
                ->where('status', 'paid')
                ->whereNotNull('is_advance_payment')
                ->count();

            if ($cuotasConsumidas > 0) {
                Log::warning("[Reversion] Intentando revertir {$cuotasConsumidas} cuotas ya consumidas (fecha pasada)");
                // No bloquear, pero advertir — el admin decide
            }

            // ── 3. Desmarcar cuotas (lockForUpdate para thread safety) ────
            $cuotas = PaymentInstallment::whereIn('id', $installmentIds)
                ->lockForUpdate()
                ->get();

            $cuotasRevertidas = 0;
            foreach ($cuotas as $cuota) {
                if ($cuota->status === 'paid' && $cuota->is_advance_payment) {
                    $cuota->update([
                        'status'             => 'pending',
                        'amount_paid'        => 0,
                        'paid_at'            => null,
                        'payment_method'     => null,
                        'is_advance_payment' => false,
                        'transfer_reference' => null,
                        'precio_pagado'      => null,
                        'notes'              => "[REVERTIDO #{$advanceLogId}] " . ($cuota->notes ?? ''),
                    ]);
                    $cuotasRevertidas++;
                }
            }

            // Recalcular estado de membresía
            if ($cuotas->isNotEmpty() && $cuotas->first()->membership_id) {
                $membership = Membership::find($cuotas->first()->membership_id);
                $membership?->recalculatePaymentStatus();
            }

            // ── 4. Revertir cambios en Recurrente si los hubo ─────────────
            $recurrenteAccion = $log->recurrente_action;
            $recurrenteResult = ['action' => 'none'];

            if (in_array($recurrenteAccion, ['cancelar', 'reprogramar'])) {
                $recurrenteResult = $this->revertirCambioRecurrente($log, $adminId);
            }

            // ── 5. Marcar el log como revertido ───────────────────────────
            $reversalLogId = DB::table('advance_payment_logs')->insertGetId([
                'client_id'          => $log->client_id,
                'membership_id'      => $log->membership_id,
                'registered_by'      => $adminId,
                'installment_ids'    => json_encode($installmentIds),
                'payment_method'     => 'reversion',
                'total_amount'       => -$log->total_amount, // Negativo = revertido
                'notes'              => "REVERSIÓN del log #{$advanceLogId}: {$motivo}",
                'recurrente_action'  => "revert_{$recurrenteAccion}",
                'old_subscription_id' => $log->new_subscription_id,
                'new_subscription_id' => $log->old_subscription_id,
                'success'            => true,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            DB::table('advance_payment_logs')->where('id', $advanceLogId)->update([
                'reversed'         => true,
                'reversed_at'      => now(),
                'reversed_by'      => $adminId,
                'reversal_reason'  => $motivo,
                'reversal_log_id'  => $reversalLogId,
            ]);

            // Audit log
            $this->adelantoService->writeAuditLog(
                clientId:        $log->client_id,
                localSubId:      null,
                recurrenteSubId: $log->old_subscription_id,
                accion:          'reversion_pago_adelantado',
                estadoAnterior:  $log->recurrente_action,
                estadoNuevo:     'revertido',
                motivo:          $motivo,
                userId:          $adminId,
                metadata:        ['advance_log_id' => $advanceLogId, 'cuotas_revertidas' => $cuotasRevertidas],
            );

            Log::info("[Reversion] ✅ Log #{$advanceLogId} revertido. Cuotas: {$cuotasRevertidas}");

            return [
                'cuotas_revertidas' => $cuotasRevertidas,
                'cuotas_consumidas' => $cuotasConsumidas,
                'recurrente'        => $recurrenteResult,
                'reversal_log_id'   => $reversalLogId,
                'message'           => "✅ Pago adelantado revertido. {$cuotasRevertidas} cuotas vuelven a 'pendiente'." .
                    ($cuotasConsumidas > 0 ? " ⚠ {$cuotasConsumidas} cuotas corresponden a meses ya pasados." : ''),
            ];
        });
    }

    private function revertirCambioRecurrente(object $log, int $adminId): array
    {
        $resultado = ['action' => 'none', 'errors' => []];

        // ── Cancelar la nueva suscripción programada ──────────────────────
        if ($log->new_subscription_id) {
            try {
                $this->recurrente->cancelSubscription($log->new_subscription_id);

                RecurrenteSubscription::where('recurrente_subscription_id', $log->new_subscription_id)
                    ->update(['status' => 'cancelled']);

                $resultado['cancelled_new'] = $log->new_subscription_id;
                Log::info("[Reversion] Nueva suscripción {$log->new_subscription_id} cancelada.");
            } catch (\Exception $e) {
                Log::warning("[Reversion] No se pudo cancelar nueva suscripción: " . $e->getMessage());
                $resultado['errors'][] = "Nueva suscripción: " . $e->getMessage();
            }
        }

        // ── Si había suscripción original cancelada, crear nueva desde HOY ─
        // (No podemos "re-activar" en Recurrente, creamos una nueva)
        if ($log->old_subscription_id && $log->client_id) {
            $client = \App\Models\Client::find($log->client_id);
            $sub    = RecurrenteSubscription::where('recurrente_subscription_id', $log->old_subscription_id)->first();

            if ($client && $sub && $client->recurrente_user_id && $sub->recurrente_product_id) {
                try {
                    $nuevaSub = $this->recurrente->createSubscription([
                        'user_id'    => $client->recurrente_user_id,
                        'product_id' => $sub->recurrente_product_id,
                    ]);

                    RecurrenteSubscription::create([
                        'client_id'                  => $client->id,
                        'membership_plan_id'         => $sub->membership_plan_id,
                        'recurrente_subscription_id' => $nuevaSub['id'],
                        'recurrente_product_id'      => $sub->recurrente_product_id,
                        'status'                     => 'active',
                        'idempotency_key'            => 'reversion_' . $log->id . '_' . date('Ymd'),
                        'creation_status'            => 'created',
                        'metadata'                   => array_merge($nuevaSub, ['reversion_of_log' => $log->id]),
                    ]);

                    $resultado['reactivated_new_id'] = $nuevaSub['id'];
                    Log::info("[Reversion] Nueva suscripción desde hoy: {$nuevaSub['id']}");
                } catch (\Exception $e) {
                    Log::error("[Reversion] No se pudo reactivar suscripción: " . $e->getMessage());
                    $resultado['errors'][] = "Reactivación: " . $e->getMessage();
                }
            }
        }

        $resultado['action'] = 'cancelled_new_reactivated_sub';
        return $resultado;
    }

    // ─────────────────────────────────────────────────────────────
    //  FIX 4.2 — CÁLCULO DE REEMBOLSO
    // ─────────────────────────────────────────────────────────────

    /**
     * Calcula el monto a reembolsar cuando el cliente cancela
     * antes de consumir todos sus meses prepagados.
     *
     * Regla: Solo se reembolsan los meses NO consumidos.
     * Un mes se considera consumido si su due_date ya pasó.
     */
    public function calcularReembolso(int $clientId, int $advanceLogId): array
    {
        $log = DB::table('advance_payment_logs')->where('id', $advanceLogId)->first();

        if (! $log || $log->client_id !== $clientId) {
            throw new \Exception("Log de pago #{$advanceLogId} no encontrado para cliente #{$clientId}.");
        }

        $installmentIds = json_decode($log->installment_ids, true);
        $cuotas = PaymentInstallment::whereIn('id', $installmentIds)->get();

        $cuotasConsumidas   = $cuotas->filter(fn ($c) => $c->due_date->lt(now()));
        $cuotasNoConsumidas = $cuotas->filter(fn ($c) => $c->due_date->gte(now()));

        $montoConsumido   = RecurrenteService::toQuetzales(
            $cuotasConsumidas->reduce(fn ($c, $i) => $c + RecurrenteService::toCents((float) $i->amount), 0)
        );
        $montoAReembolsar = RecurrenteService::toQuetzales(
            $cuotasNoConsumidas->reduce(fn ($c, $i) => $c + RecurrenteService::toCents((float) $i->amount), 0)
        );

        return [
            'log_id'              => $advanceLogId,
            'monto_original'      => $log->total_amount,
            'meses_pagados'       => $cuotas->count(),
            'meses_consumidos'    => $cuotasConsumidas->count(),
            'meses_no_consumidos' => $cuotasNoConsumidas->count(),
            'monto_consumido'     => $montoConsumido,
            'monto_a_reembolsar'  => $montoAReembolsar,
            'descuento_original'  => 0, // TODO: sumar descuentos si se implementa
            'metodo_pago_original' => $log->payment_method,
            'nota_reembolso'      => $log->payment_method === 'efectivo'
                ? '💵 El reembolso debe realizarse en efectivo por el administrador.'
                : '🏦 El reembolso debe coordinarse según el método de pago original.',
        ];
    }

    // ─────────────────────────────────────────────────────────────
    //  FIX 4.3 — UPGRADE / DOWNGRADE CON MESES PREPAGADOS
    // ─────────────────────────────────────────────────────────────

    /**
     * Cambia el plan de un cliente que tiene meses prepagados.
     *
     * Regla de negocio:
     * - Calcular el crédito restante (meses no consumidos × precio old)
     * - Aplicar ese crédito al nuevo plan
     * - Cancelar suscripción vieja en Recurrente
     * - Crear nueva suscripción con el nuevo plan
     * - Ajustar las cuotas restantes al precio nuevo
     *
     * @param int $clientId
     * @param int $newPlanId
     * @param int $adminId
     */
    public function cambiarPlanConMesesPrepagados(int $clientId, int $newPlanId, int $adminId): array
    {
        return DB::transaction(function () use ($clientId, $newPlanId, $adminId) {

            $client  = \App\Models\Client::findOrFail($clientId);
            $newPlan = MembershipPlan::findOrFail($newPlanId);

            // ── Membresía activa con cuotas prepagadas ────────────────────
            $membership = Membership::where('client_id', $clientId)
                ->where('status', 'active')
                ->with(['plan', 'installments' => fn ($q) => $q->where('status', 'pending')->orderBy('installment_number')])
                ->firstOrFail();

            $cuotasPendientes = $membership->installments;

            if ($cuotasPendientes->isEmpty()) {
                return ['error' => 'No hay cuotas pendientes para este cambio de plan.'];
            }

            $oldPlan = $membership->plan;

            // ── Calcular crédito restante ─────────────────────────────────
            $creditoCents = $cuotasPendientes->reduce(function ($carry, $cuota) {
                // FIX 3.2 — Usar precio_pagado si existe (snapshot), si no amount
                $precioPagado = $cuota->precio_pagado ?? $cuota->amount;
                return $carry + RecurrenteService::toCents((float) $precioPagado);
            }, 0);

            $creditoQ          = RecurrenteService::toQuetzales($creditoCents);
            $newPricePerMonth   = RecurrenteService::toCents((float) $newPlan->price);
            $mesesQueCubreCredito = floor($creditoCents / $newPricePerMonth);
            $creditoExcedente   = RecurrenteService::toQuetzales($creditoCents % $newPricePerMonth);

            Log::info("[Upgrade] Cliente #{$clientId}: {$oldPlan?->name} → {$newPlan->name}. " .
                      "Crédito Q{$creditoQ} cubre {$mesesQueCubreCredito} meses del nuevo plan.");

            // ── Actualizar cuotas pendientes al precio nuevo ──────────────
            foreach ($cuotasPendientes as $index => $cuota) {
                if ($index < $mesesQueCubreCredito) {
                    // FIX 3.4 — Último mes absorbe el residuo de centavos
                    $montoNuevo = ($index === (int)$mesesQueCubreCredito - 1 && $creditoExcedente > 0)
                        ? RecurrenteService::toQuetzales($newPricePerMonth) - $creditoExcedente
                        : RecurrenteService::toQuetzales($newPricePerMonth);

                    $cuota->update([
                        'amount'  => $montoNuevo,
                        'notes'   => "Ajustado por cambio de plan {$oldPlan?->name} → {$newPlan->name}",
                    ]);
                }
            }

            // ── Actualizar membresía ──────────────────────────────────────
            $membership->update([
                'plan_id'           => $newPlan->id,
                'previous_plan_id'  => $oldPlan?->id,
                'credito_restante'  => $creditoExcedente,
                'upgraded_at'       => now(),
                'total_amount'      => $newPlan->price * $cuotasPendientes->count(),
            ]);
            $membership->recalculatePaymentStatus();

            // ── Actualizar suscripción en Recurrente ──────────────────────
            $subVieja = RecurrenteSubscription::where('client_id', $clientId)
                ->where('status', 'active')
                ->first();

            $recurrenteResult = ['action' => 'none'];

            if ($subVieja && $newPlan->recurrente_product_id && $client->recurrente_user_id) {
                try {
                    $this->recurrente->cancelSubscription($subVieja->recurrente_subscription_id);
                    $subVieja->update(['status' => 'cancelled']);

                    $nuevaSub = $this->recurrente->createSubscription([
                        'user_id'    => $client->recurrente_user_id,
                        'product_id' => $newPlan->recurrente_product_id,
                    ]);

                    RecurrenteSubscription::create([
                        'client_id'                  => $clientId,
                        'membership_plan_id'         => $newPlan->id,
                        'recurrente_subscription_id' => $nuevaSub['id'],
                        'recurrente_product_id'      => $newPlan->recurrente_product_id,
                        'status'                     => 'active',
                        'idempotency_key'            => 'upgrade_' . $clientId . '_' . $newPlanId . '_' . date('Ymd'),
                        'creation_status'            => 'created',
                        'metadata'                   => $nuevaSub,
                    ]);

                    $recurrenteResult = [
                        'action'         => 'upgraded',
                        'old_sub'        => $subVieja->recurrente_subscription_id,
                        'new_sub'        => $nuevaSub['id'],
                        'new_product_id' => $newPlan->recurrente_product_id,
                    ];
                } catch (\Exception $e) {
                    Log::error("[Upgrade] Error en Recurrente al cambiar plan: " . $e->getMessage());
                    $recurrenteResult = ['action' => 'failed', 'error' => $e->getMessage()];
                }
            }

            // FIX 5.3 — Audit log del cambio de plan
            $this->adelantoService->writeAuditLog(
                clientId:        $clientId,
                localSubId:      $subVieja?->id,
                recurrenteSubId: $subVieja?->recurrente_subscription_id,
                accion:          'cambio_de_plan',
                estadoAnterior:  $oldPlan?->name,
                estadoNuevo:     $newPlan->name,
                motivo:          "Upgrade/Downgrade con crédito de Q{$creditoQ}",
                userId:          $adminId,
                metadata:        [
                    'old_plan_id'       => $oldPlan?->id,
                    'new_plan_id'       => $newPlan->id,
                    'credito_q'         => $creditoQ,
                    'meses_cubiertos'   => $mesesQueCubreCredito,
                    'credito_excedente' => $creditoExcedente,
                ],
            );

            return [
                'plan_anterior'        => $oldPlan?->name,
                'plan_nuevo'           => $newPlan->name,
                'cuotas_ajustadas'     => $cuotasPendientes->count(),
                'credito_aplicado_q'   => $creditoQ,
                'meses_cubiertos'      => $mesesQueCubreCredito,
                'credito_excedente_q'  => $creditoExcedente,
                'recurrente'           => $recurrenteResult,
                'message'              => "✅ Plan actualizado a {$newPlan->name}. " .
                    "Crédito de Q{$creditoQ} aplicado ({$mesesQueCubreCredito} meses cubiertos).",
            ];
        });
    }
}
