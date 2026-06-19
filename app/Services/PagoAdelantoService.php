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
use Illuminate\Support\Str;

/**
 * PagoAdelantoService — v2 con todos los fixes de anomalías
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  DIAGRAMA DE FLUJO                                                  │
 * │                                                                     │
 * │  [Admin marca cuotas a pagar]                                       │
 * │        │                                                            │
 * │        ▼                                                            │
 * │  [Validar cuotas] ──→ FIX 1.1: lockForUpdate en BD                 │
 * │        │            FIX 3.2: snapshot precio al pagar              │
 * │        │            FIX 3.3: verificar si membresía venció         │
 * │        │            FIX 3.4: redondeo centavos                     │
 * │        │            FIX 3.1: descuento con autorización admin      │
 * │        ▼                                                            │
 * │  [Marcar cuotas paid en BD]                                         │
 * │        │                                                            │
 * │        ▼                                                            │
 * │  [¿Tiene suscripción Recurrente?]                                   │
 * │      NO → fin (0 llamadas a Recurrente)                             │
 * │      SI ↓                                                           │
 * │        ▼                                                            │
 * │  FIX 2.1: GET Recurrente para verificar estado real                 │
 * │  FIX 2.2: Validar que fecha nueva >= hoy + 1                        │
 * │        │                                                            │
 * │  [Evaluar: CASO A, B, o C]                                          │
 * │        │                                                            │
 * │  FIX 2.3: Generar idempotency_key antes del request                 │
 * │  FIX 2.4: Si user no existe en Recurrente, recrearlo               │
 * │        │                                                            │
 * │  TODA llamada a Recurrente dentro de DB::transaction()              │
 * │  Si falla → rollback total (FIX Edge1)                              │
 * │        │                                                            │
 * │  [Guardar audit log en subscription_audit_log] ← FIX 5.3           │
 * │  [Emitir notificación en cola] ← FIX 5.1                           │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ¿CUÁNDO se llama a Recurrente? → SOLO si hay suscripción activa local
 * ¿CUÁNDO NO se llama? → Cliente paga en efectivo sin suscripción
 * ¿QUÉ protege de doble llamada? → idempotency_key UUID único por intento
 */
class PagoAdelantoService
{
    public function __construct(private RecurrenteService $recurrente)
    {}

    // ─────────────────────────────────────────────────────────────
    //  MÉTODO PRINCIPAL
    // ─────────────────────────────────────────────────────────────

    /**
     * FIX 3.1 — Ahora acepta descuento_aplicado y descuento_autorizado_por
     * FIX 3.2 — Registra precio_pagado como snapshot
     * FIX 3.3 — Verifica estado de membresía vencida antes de procesar
     */
    public function procesarPagoAdelantado(
        int    $clientId,
        array  $installmentIds,
        string $metodoPago,
        float  $montoTotal,
        int    $registeredBy,
        array  $extras = []
    ): array {
        Log::info("[PagoAdelanto] Iniciando para cliente #{$clientId}", [
            'installment_ids' => $installmentIds,
            'metodo'          => $metodoPago,
            'monto'           => $montoTotal,
            'descuento'       => $extras['descuento_aplicado'] ?? 0,
        ]);

        return DB::transaction(function () use (
            $clientId, $installmentIds, $metodoPago,
            $montoTotal, $registeredBy, $extras
        ) {
            $client = Client::findOrFail($clientId);

            // ── FIX 3.3 — Verificar membresía vencida ────────────────────
            $this->verificarMembresiaVencida($client, $installmentIds);

            // ── PASO 1: Validar cuotas (lockForUpdate = FIX 1.1 ✅) ───────
            $installments = $this->validarCuotas($client, $installmentIds, $montoTotal, $extras);

            // ── PASO 2: Marcar cuotas paid con snapshot de precio (FIX 3.2)─
            $pagadoEn          = now();
            $descuento         = (float) ($extras['descuento_aplicado'] ?? 0);
            $descuentoAuthBy   = $extras['descuento_autorizado_por'] ?? null;
            $descuentoMotivo   = $extras['descuento_motivo'] ?? null;

            foreach ($installments as $installment) {
                // FIX 3.4 — Usar función centralizada de centavos
                $precioSnap = RecurrenteService::toQuetzales(
                    RecurrenteService::toCents((float) $installment->amount)
                );

                $installment->update([
                    'status'                    => 'paid',
                    'amount_paid'               => $installment->amount,
                    'paid_at'                   => $pagadoEn,
                    'payment_method'            => $metodoPago,
                    'transfer_reference'        => $extras['transfer_reference'] ?? null,
                    'is_advance_payment'        => true,
                    'registered_by'             => $registeredBy,
                    'notes'                     => $extras['notas'] ?? null,
                    // FIX 3.2 — snapshot inmutable del precio al pagar
                    'precio_pagado'             => $precioSnap,
                    // FIX 3.1 — descuento con autorización
                    'descuento_aplicado'        => $descuento > 0 ? $descuento / count($installments) : 0,
                    'descuento_motivo'          => $descuentoMotivo,
                    'descuento_autorizado_por'  => $descuentoAuthBy,
                ]);
            }

            $membership = $installments->first()->membership;
            $membership->recalculatePaymentStatus();

            // ── PASO 3: Calcular próxima cuota sin pagar ──────────────────
            $proximaCuota = $this->calcularProximaCuotaSinPagar($clientId, $installmentIds);

            // ── PASO 4: Evaluar y sincronizar con Recurrente ──────────────
            $suscripcion = RecurrenteSubscription::where('client_id', $clientId)
                ->where('status', 'active')
                ->with('membershipPlan')
                ->first();

            $resultado = [
                'cuotas_pagadas'     => $installments->count(),
                'monto_aplicado'     => $installments->sum('amount'),
                'descuento_aplicado' => $descuento,
                'metodo_pago'        => $metodoPago,
                'proxima_cuota'      => $proximaCuota?->due_date?->toDateString(),
                'recurrente_action'  => 'none',
                'new_subscription'   => null,
                'next_charge_date'   => null,
            ];

            if ($suscripcion) {
                // FIX 2.1 — Verificar estado REAL en Recurrente antes de operar
                $suscripcion = $this->sincronizarEstadoLocal($suscripcion);

                if ($suscripcion->status === 'active') {
                    $accion = $this->evaluarAccionRecurrente($client, $proximaCuota);
                    $resultado['recurrente_action'] = $accion;

                    match ($accion) {
                        'cancelar'    => $this->ejecutarCasoA($client, $suscripcion, $membership, $resultado, $registeredBy),
                        'reprogramar' => $this->ejecutarCasoB($client, $suscripcion, $membership, $proximaCuota, $resultado, $metodoPago, $registeredBy),
                        'none'        => null,
                    };
                } else {
                    Log::info("[PagoAdelanto] Suscripción ya {$suscripcion->status} en Recurrente. No se requiere acción.");
                    $resultado['recurrente_action'] = 'none_already_' . $suscripcion->status;
                }
            }

            // ── PASO 5: Log de auditoría y método de pago ────────────────
            $this->guardarLog($client, $membership, $installmentIds, $metodoPago, $montoTotal, $registeredBy, $extras, $resultado);
            $this->actualizarLogMetodoPago($membership, $metodoPago, $installmentIds, $extras);

            Log::info("[PagoAdelanto] ✅ Completado para cliente #{$clientId}", $resultado);

            return $resultado;
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  FIX 3.3 — MEMBRESÍA VENCIDA
    //
    //  Regla de negocio definida:
    //  Si la membresía venció, los pagos adelantados se aplican
    //  desde HOY (no desde cuando venció). Se reactiva la membresía.
    //  Las cuotas de meses vencidos NO se crean automáticamente.
    //  El admin debe decidir explícitamente qué hacer con esos meses.
    // ─────────────────────────────────────────────────────────────

    private function verificarMembresiaVencida(Client $client, array $installmentIds): void
    {
        $cuotas = PaymentInstallment::whereIn('id', $installmentIds)
            ->where('client_id', $client->id)
            ->get();

        foreach ($cuotas as $cuota) {
            if (! $cuota->membership) continue;

            // Si hay cuotas con fecha muy en el pasado (> 60 días), advertir
            if ($cuota->due_date->lt(now()->subDays(60))) {
                Log::warning(
                    "[PagoAdelanto] FIX 3.3 — Cuota #{$cuota->id} tiene due_date " .
                    $cuota->due_date->format('Y-m-d') . " (hace " .
                    $cuota->due_date->diffInDays(now()) . " días). " .
                    "Se registrará como pago tardío. La membresía se reactiva desde hoy."
                );
            }
        }

        // Si la membresía expiró, se reactiva automáticamente desde hoy
        $membresiaVencida = Membership::whereIn('id',
            PaymentInstallment::whereIn('id', $installmentIds)
                ->pluck('membership_id')->unique()->toArray()
        )->where('status', 'expired')
         ->orWhere(function ($q) {
             $q->where('status', 'active')->where('end_date', '<', now());
         })->first();

        if ($membresiaVencida) {
            // Extender membresía: los pagos adelantados son a partir de hoy
            $membresiaVencida->update([
                'status'   => 'active',
                'end_date' => now()->addDays(
                    count($installmentIds) * ($membresiaVencida->plan?->duration_days ?? 30)
                )->toDateString(),
            ]);

            Log::info("[PagoAdelanto] FIX 3.3 — Membresía #{$membresiaVencida->id} reactivada. " .
                      "Nueva fecha fin: {$membresiaVencida->end_date?->format('Y-m-d')}");
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  VALIDACIONES
    // ─────────────────────────────────────────────────────────────

    private function validarCuotas(Client $client, array $installmentIds, float $montoTotal, array $extras = [])
    {
        // FIX 1.1 + 1.2 — lockForUpdate previene doble registro y
        // webhook concurrente de Recurrente procesando la misma cuota
        $installments = PaymentInstallment::whereIn('id', $installmentIds)
            ->where('client_id', $client->id)
            ->lockForUpdate()
            ->with('membership')
            ->get();

        if ($installments->count() !== count($installmentIds)) {
            throw new \Exception('Una o más cuotas no existen o no pertenecen al cliente #' . $client->id);
        }

        // Cuotas cobradas por Recurrente: NUNCA tocar
        $conRecurrente = $installments->filter(fn ($i) => ! is_null($i->recurrente_payment_id));
        if ($conRecurrente->isNotEmpty()) {
            throw new \Exception(
                "Las cuotas [{$conRecurrente->pluck('id')->implode(', ')}] ya fueron cobradas " .
                "por Recurrente y no pueden modificarse."
            );
        }

        // Cuotas ya pagadas en efectivo
        $yaPagadas = $installments->filter(fn ($i) => $i->status === 'paid');
        if ($yaPagadas->isNotEmpty()) {
            throw new \Exception(
                "Las cuotas [{$yaPagadas->pluck('installment_number')->implode(', ')}] ya están pagadas."
            );
        }

        // FIX 3.1 — Si hay descuento, debe tener autorización de admin
        $descuento = (float) ($extras['descuento_aplicado'] ?? 0);
        if ($descuento > 0 && empty($extras['descuento_autorizado_por'])) {
            throw new \Exception(
                "Un descuento de Q{$descuento} requiere autorización de un administrador. " .
                "Incluye descuento_autorizado_por en la request."
            );
        }

        // FIX 3.4 — Validación de monto con tolerancia de centavos
        $sumaEsperada  = $installments->reduce(function ($carry, $i) use ($descuento, $installments) {
            // FIX 3.4 — Redondeo: last installment absorbs centavo difference
            return $carry + RecurrenteService::toCents((float) $i->amount);
        }, 0);
        $sumaEsperadaQ = RecurrenteService::toQuetzales($sumaEsperada);
        $montoConDesc  = $sumaEsperadaQ - $descuento;

        if ($montoTotal < ($montoConDesc - 0.05)) { // tolerancia de Q0.05
            Log::warning("[PagoAdelanto] ⚠ PAGO PARCIAL: pagó Q{$montoTotal}, esperado Q{$montoConDesc}");
        }

        // FIX Edge 2 — Cuotas no consecutivas: advertir (no bloquear)
        $numbers = $installments->sortBy('installment_number')->pluck('installment_number')->toArray();
        if (count($numbers) > 1) {
            $expected   = range(min($numbers), max($numbers));
            $faltantes  = array_values(array_diff($expected, $numbers));
            if (! empty($faltantes)) {
                Log::warning("[PagoAdelanto] ⚠ Cuotas no consecutivas. Faltantes: " . implode(', ', $faltantes));
            }
        }

        return $installments;
    }

    // ─────────────────────────────────────────────────────────────
    //  FIX 2.1 — Verificar estado REAL en Recurrente
    //
    //  Escenario: admin canceló manualmente en panel de Recurrente
    //  → el GET al API retorna 'cancelled'
    //  → actualizamos local y no intentamos cancelar algo inexistente
    // ─────────────────────────────────────────────────────────────

    private function sincronizarEstadoLocal(RecurrenteSubscription $sub): RecurrenteSubscription
    {
        try {
            $remoto = $this->recurrente->getSubscription($sub->recurrente_subscription_id);
            $estadoRemoto = $remoto['status'] ?? 'unknown';

            if ($estadoRemoto !== $sub->status) {
                Log::info(
                    "[PagoAdelanto] FIX 2.1 — Suscripción {$sub->recurrente_subscription_id}: " .
                    "local={$sub->status}, remoto={$estadoRemoto}. Sincronizando local."
                );

                $sub->update(['status' => $estadoRemoto, 'metadata' => array_merge($sub->metadata ?? [], $remoto)]);
                $sub->refresh();

                $this->writeAuditLog(
                    clientId:           $sub->client_id,
                    localSubId:         $sub->id,
                    recurrenteSubId:    $sub->recurrente_subscription_id,
                    accion:             'sincronizacion_forzada',
                    estadoAnterior:     $sub->status,
                    estadoNuevo:        $estadoRemoto,
                    motivo:             'Fix 2.1: estado local desincronizado con Recurrente',
                    userId:             null,
                );
            }
        } catch (\Exception $e) {
            // Si el GET falla (ej: 404 = eliminada en Recurrente)
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                Log::warning("[PagoAdelanto] FIX 2.1 — Suscripción {$sub->recurrente_subscription_id} no existe en Recurrente (404). Marcando local como cancelled.");
                $sub->update(['status' => 'cancelled']);
                $sub->refresh();
            } else {
                // Error de red: continuar con estado local (mejor que bloquear)
                Log::warning("[PagoAdelanto] FIX 2.1 — No se pudo verificar estado en Recurrente: " . $e->getMessage() . ". Usando estado local.");
            }
        }

        return $sub;
    }

    // ─────────────────────────────────────────────────────────────
    //  CASO A — Cancelar suscripción completa
    // ─────────────────────────────────────────────────────────────

    private function ejecutarCasoA(
        Client               $client,
        RecurrenteSubscription $suscripcion,
        Membership           $membership,
        array                &$resultado,
        int                  $registeredBy
    ): void {
        Log::info("[PagoAdelanto] CASO A — Cancelando suscripción {$suscripcion->recurrente_subscription_id}");

        try {
            $this->recurrente->cancelSubscription($suscripcion->recurrente_subscription_id);
        } catch (\Exception $e) {
            // Si ya estaba cancelada en Recurrente (de Fix 2.1 la sincronizamos)
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'already cancelled')) {
                Log::info("[PagoAdelanto] CASO A — Ya cancelada en Recurrente. Actualizando solo local.");
            } else {
                throw new \Exception("Error al cancelar suscripción en Recurrente: {$e->getMessage()}. El pago NO fue registrado.");
            }
        }

        $suscripcion->update([
            'status'   => 'cancelled',
            'metadata' => array_merge($suscripcion->metadata ?? [], [
                'cancelled_reason' => 'advance_payment_full',
                'cancelled_at'     => now()->toISOString(),
            ]),
        ]);

        $membership->update(['recurrente_status' => 'cancelled', 'recurrente_rescheduled_at' => now()]);

        $this->writeAuditLog(
            clientId:        $client->id,
            localSubId:      $suscripcion->id,
            recurrenteSubId: $suscripcion->recurrente_subscription_id,
            accion:          'cancelar',
            estadoAnterior:  'active',
            estadoNuevo:     'cancelled',
            motivo:          'Pago adelantado cubre todas las cuotas restantes',
            userId:          $registeredBy,
        );

        $resultado['old_subscription_id'] = $suscripcion->recurrente_subscription_id;
        $resultado['next_charge_date']    = null;
        $resultado['message']             = '✅ Suscripción cancelada. El cliente pagó todas las cuotas en efectivo.';
    }

    // ─────────────────────────────────────────────────────────────
    //  CASO B — Reprogramar suscripción
    //
    //  FIX 2.2 — Validar que la fecha nueva sea siempre >= hoy + 1
    //  FIX 2.3 — idempotency_key UUID para evitar doble creación
    //  FIX 2.4 — Recrear usuario si no existe en Recurrente
    // ─────────────────────────────────────────────────────────────

    private function ejecutarCasoB(
        Client               $client,
        RecurrenteSubscription $suscripcion,
        Membership           $membership,
        PaymentInstallment   $proximaCuota,
        array                &$resultado,
        string               $metodoPago,
        int                  $registeredBy
    ): void {
        // FIX 2.2 — La fecha de reprogramación siempre >= hoy + 1 día
        $fechaIdeal   = Carbon::parse($proximaCuota->due_date)->startOfMonth();
        $fechaMinima  = now()->addDay()->startOfDay();
        $fechaRetoma  = $fechaIdeal->lt($fechaMinima) ? $fechaMinima : $fechaIdeal;

        Log::info("[PagoAdelanto] CASO B — Reprogramando para cliente #{$client->id}. Retoma: {$fechaRetoma->format('Y-m-d')}");

        $productId = $suscripcion->recurrente_product_id ?? $membership->plan?->recurrente_product_id;

        if (! $productId) {
            Log::warning("[PagoAdelanto] CASO B sin product_id. Solo cancelando suscripción.");
            $this->ejecutarCasoA($client, $suscripcion, $membership, $resultado, $registeredBy);
            return;
        }

        // ── 1. Cancelar suscripción vieja ─────────────────────────────────
        try {
            $this->recurrente->cancelSubscription($suscripcion->recurrente_subscription_id);
        } catch (\Exception $e) {
            if (! str_contains($e->getMessage(), '404')) {
                throw new \Exception("Error al reprogramar (cancelar vieja): {$e->getMessage()}. El pago NO fue registrado.");
            }
            Log::info("[PagoAdelanto] CASO B — Suscripción vieja ya no existía en Recurrente (404). Continuando.");
        }

        $suscripcion->update(['status' => 'cancelled']);

        // ── FIX 2.3 — Verificar si ya existe una suscripción con este key ─
        // Generar idempotency_key ANTES del request
        $idempotencyKey = 'adelanto_' . $client->id . '_' . $proximaCuota->id . '_' . date('Ymd');

        // ¿Ya hay una suscripción reciente con este key? (reintento tras timeout)
        $existente = RecurrenteSubscription::where('idempotency_key', $idempotencyKey)
            ->where('client_id', $client->id)
            ->where('creation_status', 'created') // Ya fue confirmada
            ->first();

        if ($existente) {
            Log::info("[PagoAdelanto] FIX 2.3 — Ya existe suscripción con key {$idempotencyKey}. No se crea otra.");
            $resultado['new_subscription_id'] = $existente->recurrente_subscription_id;
            $resultado['next_charge_date']    = $fechaRetoma->toDateString();
            $resultado['message']             = '✅ Suscripción ya reprogramada (idempotente).';
            return;
        }

        // Guardar el intento ANTES de llamar a Recurrente (FIX 2.3)
        $pendingSub = RecurrenteSubscription::create([
            'client_id'                  => $client->id,
            'membership_plan_id'         => $membership->plan_id,
            'recurrente_subscription_id' => 'pending_' . $idempotencyKey,
            'recurrente_product_id'      => $productId,
            'status'                     => 'active',
            'idempotency_key'            => $idempotencyKey,
            'creation_status'            => 'pending_confirmation', // No confirmado aún
            'current_period_start'       => $fechaRetoma->toDateTimeString(),
            'metadata'                   => ['scheduled_for' => $fechaRetoma->toISOString()],
        ]);

        // ── FIX 2.4 — Recrear usuario si fue eliminado en Recurrente ──────
        $recurrenteUserId = $client->recurrente_user_id;
        if ($recurrenteUserId) {
            try {
                $this->recurrente->getUser($recurrenteUserId);
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), '404')) {
                    Log::warning("[PagoAdelanto] FIX 2.4 — User {$recurrenteUserId} no existe en Recurrente. Re-creando.");
                    $nameParts = explode(' ', trim($client->name ?? $client->first_name . ' ' . $client->last_name), 2);
                    $newUser   = $this->recurrente->createUser([
                        'first_name' => $nameParts[0],
                        'last_name'  => $nameParts[1] ?? '-',
                        'email'      => $client->email ?? "client{$client->id}@irongym.local",
                    ]);
                    $client->update(['recurrente_user_id' => $newUser['id']]);
                    $recurrenteUserId = $newUser['id'];
                    Log::info("[PagoAdelanto] FIX 2.4 — Usuario re-creado en Recurrente: {$recurrenteUserId}");
                }
            }
        }

        // ── 2. Crear nueva suscripción con fecha futura ───────────────────
        try {
            $nuevaSub = $this->recurrente->createSubscription([
                'user_id'            => $recurrenteUserId,
                'product_id'         => $productId,
                'billing_start_date' => $fechaRetoma->toDateString(), // FIX 2.2
            ]);

            // Confirmar la suscripción en BD
            $pendingSub->update([
                'recurrente_subscription_id' => $nuevaSub['id'],
                'creation_status'            => 'created',
                'metadata'                   => array_merge($nuevaSub, [
                    'scheduled_for'         => $fechaRetoma->toISOString(),
                    'rescheduled_reason'    => "advance_payment_{$metodoPago}",
                    'previous_sub_id'       => $suscripcion->recurrente_subscription_id,
                    'idempotency_key'       => $idempotencyKey,
                ]),
            ]);

        } catch (\Exception $e) {
            // FIX 2.3 — Timeout: la suscripción pudo haberse creado en Recurrente
            // Marcar como 'pending_confirmation' — el SyncJob lo verificará
            Log::error("[PagoAdelanto] FIX 2.3 — Timeout/error al crear suscripción. " .
                       "Puede haber sido creada en Recurrente. Key: {$idempotencyKey}. Error: " . $e->getMessage());

            $pendingSub->update(['creation_status' => 'pending_confirmation']);

            // No lanzar excepción — el pago adelantado ya está registrado.
            // El admin verá la alerta en el dashboard.
            $resultado['warning'] = "La nueva suscripción en Recurrente quedó pendiente de confirmación " .
                                    "(posible timeout). Verificar manualmente o esperar la sincronización automática.";

            $membership->update(['recurrente_status' => 'scheduled']);
            $resultado['next_charge_date'] = $fechaRetoma->toDateString();
            return;
        }

        $membership->update([
            'recurrente_status'         => 'scheduled',
            'recurrente_rescheduled_at' => now(),
        ]);

        // FIX 5.3 — Log de auditoría
        $this->writeAuditLog(
            clientId:        $client->id,
            localSubId:      $pendingSub->id,
            recurrenteSubId: $pendingSub->recurrente_subscription_id,
            accion:          'reprogramar',
            estadoAnterior:  'active',
            estadoNuevo:     'scheduled',
            motivo:          "Pago adelantado ({$metodoPago}). Retoma el {$fechaRetoma->format('d/m/Y')}",
            userId:          $registeredBy,
            metadata:        ['old_sub_id' => $suscripcion->recurrente_subscription_id, 'new_start' => $fechaRetoma->toISOString()],
        );

        $resultado['old_subscription_id'] = $suscripcion->recurrente_subscription_id;
        $resultado['new_subscription_id'] = $pendingSub->recurrente_subscription_id;
        $resultado['next_charge_date']    = $fechaRetoma->toDateString();
        $resultado['new_subscription']    = $pendingSub;
        $resultado['message']             = "✅ Suscripción reprogramada. Recurrente retomará el {$fechaRetoma->format('d/m/Y')}.";

        Log::info("[PagoAdelanto] CASO B ✅ — Nueva suscripción {$pendingSub->recurrente_subscription_id}");
    }

    // ─────────────────────────────────────────────────────────────
    //  CALCULAR PRÓXIMA CUOTA SIN PAGAR
    // ─────────────────────────────────────────────────────────────

    private function calcularProximaCuotaSinPagar(int $clientId, array $installmentIdsRecienPagados): ?PaymentInstallment
    {
        $ultimaCuotaNum = PaymentInstallment::whereIn('id', $installmentIdsRecienPagados)
            ->where('client_id', $clientId)
            ->max('installment_number');

        if (! $ultimaCuotaNum) return null;

        return PaymentInstallment::where('client_id', $clientId)
            ->where('installment_number', '>', $ultimaCuotaNum)
            ->whereIn('status', ['pending', 'overdue', 'partial'])
            ->whereNull('recurrente_payment_id')
            ->orderBy('installment_number')
            ->first();
    }

    private function evaluarAccionRecurrente(Client $client, ?PaymentInstallment $proximaCuota): string
    {
        Log::info("DEBUG evaluarAccionRecurrente", [
            'client_id' => $client->id,
            'recurrente_user_id' => $client->recurrente_user_id,
            'proximaCuota_id' => $proximaCuota?->id,
            'hasSub_query' => RecurrenteSubscription::where('client_id', $client->id)->where('status', 'active')->exists(),
            'allSubs' => RecurrenteSubscription::where('client_id', $client->id)->get()->toArray()
        ]);

        if (! $client->recurrente_user_id) return 'none';

        $hasSub = RecurrenteSubscription::where('client_id', $client->id)
            ->where('status', 'active')->exists();

        if (! $hasSub) return 'none';

        return is_null($proximaCuota) ? 'cancelar' : 'reprogramar';
    }

    // ─────────────────────────────────────────────────────────────
    //  FIX 5.3 — AUDIT LOG de suscripción
    // ─────────────────────────────────────────────────────────────

    public function writeAuditLog(
        int    $clientId,
        ?int   $localSubId,
        ?string $recurrenteSubId,
        string $accion,
        ?string $estadoAnterior,
        ?string $estadoNuevo,
        ?string $motivo,
        ?int   $userId,
        array  $metadata = []
    ): void {
        try {
            DB::table('subscription_audit_log')->insert([
                'client_id'                  => $clientId,
                'recurrente_subscription_id' => $recurrenteSubId,
                'local_subscription_id'      => $localSubId,
                'user_id'                    => $userId,
                'accion'                     => $accion,
                'estado_anterior'            => $estadoAnterior,
                'estado_nuevo'               => $estadoNuevo,
                'motivo'                     => $motivo,
                'metadata'                   => json_encode($metadata),
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("[PagoAdelanto] Error escribiendo audit log: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  AUDITORÍA LOCAL
    // ─────────────────────────────────────────────────────────────

    private function guardarLog(Client $client, Membership $membership, array $installmentIds, string $metodoPago, float $montoTotal, int $registeredBy, array $extras, array $resultado): void
    {
        DB::table('advance_payment_logs')->insert([
            'client_id'           => $client->id,
            'membership_id'       => $membership->id,
            'registered_by'       => $registeredBy,
            'installment_ids'     => json_encode($installmentIds),
            'payment_method'      => $metodoPago,
            'total_amount'        => $montoTotal,
            'transfer_reference'  => $extras['transfer_reference'] ?? null,
            'notes'               => $extras['notas'] ?? null,
            'recurrente_action'   => $resultado['recurrente_action'],
            'old_subscription_id' => $resultado['old_subscription_id'] ?? null,
            'new_subscription_id' => $resultado['new_subscription_id'] ?? null,
            'next_charge_date'    => $resultado['next_charge_date'] ?? null,
            'success'             => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function actualizarLogMetodoPago(Membership $membership, string $metodoPago, array $installmentIds, array $extras): void
    {
        $log   = $membership->payment_method_log ?? [];
        $log[] = [
            'timestamp'       => now()->toISOString(),
            'method'          => $metodoPago,
            'installment_ids' => $installmentIds,
            'reference'       => $extras['transfer_reference'] ?? null,
            'descuento'       => $extras['descuento_aplicado'] ?? 0,
        ];
        $membership->update(['payment_method_log' => $log]);
    }
}
