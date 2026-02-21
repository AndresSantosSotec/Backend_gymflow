<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\NotificarPagoAdelantado;
use App\Models\Client;
use App\Models\Membership;
use App\Models\PaymentInstallment;
use App\Models\RecurrenteSubscription;
use App\Services\PagoAdelantoReversionService;
use App\Services\PagoAdelantoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PagoAdelantoController — v2 con todos los fixes de anomalías
 *
 * Rutas:
 *   POST  /api/pagos/adelanto                        → registrarPagoAdelanto
 *   GET   /api/pagos/adelanto/client/{id}            → estadoCuotas
 *   POST  /api/pagos/adelanto/reactivar              → reactivarSuscripcion  [Edge 5]
 *   POST  /api/pagos/adelanto/{logId}/revertir       → anularPagoAdelantado  [Fix 4.1]
 *   GET   /api/pagos/adelanto/{logId}/reembolso      → calcularReembolso     [Fix 4.2]
 *   POST  /api/pagos/adelanto/upgrade-plan           → cambiarPlan           [Fix 4.3]
 *   GET   /api/pagos/adelanto/alertas                → listarAlertas         [Fix 5.2]
 *   PATCH /api/pagos/adelanto/alertas/{id}           → resolverAlerta        [Fix 5.2]
 */
class PagoAdelantoController extends Controller
{
    public function __construct(
        private PagoAdelantoService          $service,
        private PagoAdelantoReversionService $reversionService,
    ) {}

    // ─────────────────────────────────────────────────────────────
    //  POST /api/pagos/adelanto
    // ─────────────────────────────────────────────────────────────

    /**
     * Registrar pagos adelantados en efectivo/transferencia/combinado.
     *
     * FIX 3.1 - descuento_aplicado requiere descuento_autorizado_por
     * FIX 3.2 - precio_pagado guardado como snapshot en BD
     * FIX 3.3 - membresía vencida se reactiva desde hoy
     * FIX 3.4 - redondeo estandarizado via RecurrenteService::toCents()
     * FIX 5.1 - NotificarPagoAdelantado job despachado post-transacción
     */
    public function registrarPagoAdelanto(Request $request)
    {
        $validated = $request->validate([
            'client_id'                 => 'required|exists:clients,id',
            'installment_ids'           => 'required|array|min:1',
            'installment_ids.*'         => 'integer|exists:payment_installments,id',
            'payment_method'            => 'required|in:efectivo,transferencia,combinado',
            'total_amount'              => 'required|numeric|min:0.01',
            'transfer_reference'        => 'nullable|string|max:100',
            'notas'                     => 'nullable|string|max:500',
            // FIX 3.1 — Descuento con autorización
            'descuento_aplicado'        => 'nullable|numeric|min:0',
            'descuento_motivo'          => 'nullable|string|max:200',
            'descuento_autorizado_por'  => 'nullable|exists:users,id',
        ]);

        // ── Validación de negocio: cuotas pertenecen al cliente ──────────
        $cuotasDelCliente = PaymentInstallment::whereIn('id', $validated['installment_ids'])
            ->where('client_id', $validated['client_id'])
            ->count();

        if ($cuotasDelCliente !== count($validated['installment_ids'])) {
            return response()->json(['error' => 'Una o más cuotas no pertenecen a este cliente.'], 422);
        }

        // Cuotas con Recurrente ID no se pueden tocar
        $conRecurrente = PaymentInstallment::whereIn('id', $validated['installment_ids'])
            ->whereNotNull('recurrente_payment_id')->exists();

        if ($conRecurrente) {
            return response()->json([
                'error' => 'Una o más cuotas ya fueron cobradas por Recurrente y no pueden modificarse.',
                'tip'   => 'Solo puedes pagar adelantado cuotas pendientes.',
            ], 422);
        }

        // Cuotas ya pagadas
        $yaPagadas = PaymentInstallment::whereIn('id', $validated['installment_ids'])
            ->where('status', 'paid')
            ->pluck('installment_number')->toArray();

        if (! empty($yaPagadas)) {
            return response()->json([
                'error'            => 'Las siguientes cuotas ya están pagadas: #' . implode(', #', $yaPagadas),
                'cuotas_ya_pagas' => $yaPagadas,
            ], 422);
        }

        // FIX 3.1 — Descuento requiere autorización
        if (! empty($validated['descuento_aplicado']) && $validated['descuento_aplicado'] > 0
            && empty($validated['descuento_autorizado_por'])) {
            return response()->json([
                'error' => "Un descuento de Q{$validated['descuento_aplicado']} requiere campo descuento_autorizado_por (ID del admin que autoriza).",
            ], 422);
        }

        // Advertencia de cuotas no consecutivas
        $cuotas  = PaymentInstallment::whereIn('id', $validated['installment_ids'])->orderBy('installment_number')->get();
        $nums    = $cuotas->pluck('installment_number')->toArray();
        $warnings = [];
        if (count($nums) > 1) {
            $faltantes = array_values(array_diff(range(min($nums), max($nums)), $nums));
            if (! empty($faltantes)) {
                $warnings[] = "Las cuotas seleccionadas no son consecutivas. Faltantes en el rango: " . implode(', ', $faltantes) .
                              ". Recurrente retomará después de la última cuota seleccionada.";
            }
        }

        try {
            $resultado = $this->service->procesarPagoAdelantado(
                clientId:       $validated['client_id'],
                installmentIds: $validated['installment_ids'],
                metodoPago:     $validated['payment_method'],
                montoTotal:     $validated['total_amount'],
                registeredBy:   $request->user()->id,
                extras:         [
                    'transfer_reference'       => $validated['transfer_reference'] ?? null,
                    'notas'                    => $validated['notas'] ?? null,
                    'descuento_aplicado'       => $validated['descuento_aplicado'] ?? 0,
                    'descuento_motivo'         => $validated['descuento_motivo'] ?? null,
                    'descuento_autorizado_por' => $validated['descuento_autorizado_por'] ?? null,
                ],
            );

            // FIX 5.1 — Despachar notificación DESPUÉS del commit, no dentro de la transacción
            NotificarPagoAdelantado::dispatch($validated['client_id'], $resultado, $request->user()->id)
                ->delay(now()->addSeconds(5));

            return response()->json([
                ...$resultado,
                'warnings' => $warnings,
            ]);

        } catch (\Exception $e) {
            Log::error('[PagoAdelantoController] Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage(), 'success' => false], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  GET /api/pagos/adelanto/client/{clientId}
    // ─────────────────────────────────────────────────────────────

    public function estadoCuotas(int $clientId)
    {
        $client = Client::findOrFail($clientId);

        $memberships = Membership::where('client_id', $clientId)
            ->where('status', 'active')
            ->with(['plan', 'installments' => fn ($q) => $q->orderBy('installment_number')])
            ->get();

        $suscripcion = RecurrenteSubscription::where('client_id', $clientId)
            ->where('status', 'active')
            ->with('membershipPlan')
            ->first();

        $cuotas = $memberships->flatMap(function ($membership) {
            return $membership->installments->map(fn ($i) => [
                'id'                   => $i->id,
                'installment_number'   => $i->installment_number,
                'amount'               => $i->amount,
                'precio_pagado'        => $i->precio_pagado,          // FIX 3.2
                'descuento_aplicado'   => $i->descuento_aplicado,     // FIX 3.1
                'due_date'             => $i->due_date->toDateString(),
                'status'               => $i->status,
                'payment_method'       => $i->payment_method,
                'is_advance_payment'   => (bool) $i->is_advance_payment,
                'paid_by_recurrente'   => ! is_null($i->recurrente_payment_id),
                'available_for_advance' => $i->status !== 'paid' && is_null($i->recurrente_payment_id),
                'membership_id'        => $i->membership_id,
                'plan_name'            => $membership->plan?->name,
            ]);
        });

        return response()->json([
            'client_name'           => $client->full_name,
            'has_recurrente_sub'    => ! is_null($suscripcion),
            'recurrente_sub'        => $suscripcion,
            'memberships'           => $memberships->count(),
            'cuotas'                => $cuotas,
            'cuotas_pendientes'     => $cuotas->where('status', '!=', 'paid')->values(),
            'cuotas_adelantables'   => $cuotas->where('available_for_advance', true)->values(),
            'total_pendiente'       => $cuotas->where('status', '!=', 'paid')->sum('amount'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  FIX 4.1 — POST /api/pagos/adelanto/{logId}/revertir
    // ─────────────────────────────────────────────────────────────

    public function anularPagoAdelantado(Request $request, int $logId)
    {
        $validated = $request->validate([
            'motivo' => 'required|string|min:10|max:500',
        ]);

        try {
            $resultado = $this->reversionService->anularPagoAdelantado(
                advanceLogId: $logId,
                adminId:      $request->user()->id,
                motivo:       $validated['motivo'],
            );

            return response()->json($resultado);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  FIX 4.2 — GET /api/pagos/adelanto/{logId}/reembolso
    // ─────────────────────────────────────────────────────────────

    public function calcularReembolso(Request $request, int $logId)
    {
        $clientId = $request->query('client_id');

        try {
            $reembolso = $this->reversionService->calcularReembolso((int) $clientId, $logId);
            return response()->json($reembolso);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  FIX 4.3 — POST /api/pagos/adelanto/upgrade-plan
    // ─────────────────────────────────────────────────────────────

    public function cambiarPlan(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'plan_id'   => 'required|exists:membership_plans,id',
        ]);

        try {
            $resultado = $this->reversionService->cambiarPlanConMesesPrepagados(
                clientId:  $validated['client_id'],
                newPlanId: $validated['plan_id'],
                adminId:   $request->user()->id,
            );

            return response()->json($resultado);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Edge 5 — POST /api/pagos/adelanto/reactivar
    // ─────────────────────────────────────────────────────────────

    public function reactivarSuscripcion(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'plan_id'   => 'required|exists:membership_plans,id',
        ]);

        $client = Client::findOrFail($validated['client_id']);

        if (! $client->recurrente_user_id) {
            return response()->json(['error' => 'El cliente no tiene cuenta en Recurrente. Usa el checkout primero.'], 422);
        }

        $existing = RecurrenteSubscription::where('client_id', $client->id)->where('status', 'active')->first();
        if ($existing) {
            return response()->json(['error' => 'El cliente ya tiene una suscripción activa.', 'subscription_id' => $existing->id], 409);
        }

        return app(RecurrenteController::class)->createSubscription($request);
    }

    // ─────────────────────────────────────────────────────────────
    //  FIX 5.2 — GET /api/pagos/adelanto/alertas
    // ─────────────────────────────────────────────────────────────

    public function listarAlertas(Request $request)
    {
        $status = $request->query('status', 'nueva');

        $alertas = \Illuminate\Support\Facades\DB::table('recurrente_conciliation_alerts')
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($alertas);
    }

    public function resolverAlerta(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:revisada,resuelta,ignorada',
        ]);

        \Illuminate\Support\Facades\DB::table('recurrente_conciliation_alerts')
            ->where('id', $id)
            ->update([
                'status'      => $validated['status'],
                'resuelta_por' => $request->user()->id,
                'resuelta_at' => now(),
                'updated_at'  => now(),
            ]);

        return response()->json(['message' => "Alerta #{$id} marcada como {$validated['status']}."]);
    }
}
