<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\MembershipPause;
use App\Services\PausarMembresiaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MembresiaLifecycleController
 *
 * Endpoints del ciclo de vida avanzado de membresías.
 *
 * POST /api/membresias/pausar               → Pausar (Caso 2)
 * POST /api/membresias/pausar/{id}/cancelar → Cancelar pausa antes de tiempo
 * GET  /api/membresias/pausar/impacto       → Preview del impacto sin aplicar
 * POST /api/membresias/reactivar-tarjeta    → Volver a tarjeta tras efectivo (Caso 4)
 * GET  /api/membresias/riesgo               → Membresías at_risk + venciendo (Dashboard)
 * POST /api/membresias/{id}/reactivar       → Reactivar manualmente una at_risk
 * PUT  /api/membresias/{id}/wants-renewal   → Toggle wants_auto_renewal
 */
class MembresiaLifecycleController extends Controller
{
    public function __construct(private PausarMembresiaService $pausarService) {}

    // ─────────────────────────────────────────────────────────────────
    //  POST /api/membresias/pausar
    // ─────────────────────────────────────────────────────────────────

    public function pausar(Request $request)
    {
        $validated = $request->validate([
            'membership_id'  => 'required|exists:memberships,id',
            'pause_start'    => 'required|date|after_or_equal:today',
            'pause_end'      => 'required|date|after:pause_start',
            'reason'         => 'required|in:travel,injury,other',
            'notes'          => 'nullable|string|max:500',
        ]);

        try {
            $resultado = $this->pausarService->pausar(
                membershipId: $validated['membership_id'],
                pauseStart:   $validated['pause_start'],
                pauseEnd:     $validated['pause_end'],
                reason:       $validated['reason'],
                notes:        $validated['notes'] ?? '',
                adminId:      $request->user()->id,
            );

            return response()->json($resultado);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  GET /api/membresias/pausar/impacto?membership_id=X&start=Y&end=Z
    // ─────────────────────────────────────────────────────────────────

    public function calcularImpacto(Request $request)
    {
        $validated = $request->validate([
            'membership_id' => 'required|exists:memberships,id',
            'pause_start'   => 'required|date|after_or_equal:today',
            'pause_end'     => 'required|date|after:pause_start',
        ]);

        try {
            $impacto = $this->pausarService->calcularImpacto(
                membershipId: $validated['membership_id'],
                pauseStart:   $validated['pause_start'],
                pauseEnd:     $validated['pause_end'],
            );
            return response()->json($impacto);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  POST /api/membresias/pausar/{id}/cancelar
    // ─────────────────────────────────────────────────────────────────

    public function cancelarPausa(Request $request, int $pauseId)
    {
        $validated = $request->validate([
            'motivo' => 'nullable|string|max:300',
        ]);

        try {
            $resultado = $this->pausarService->cancelarPausa(
                pauseId: $pauseId,
                adminId: $request->user()->id,
                motivo:  $validated['motivo'] ?? '',
            );
            return response()->json($resultado);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  POST /api/membresias/reactivar-tarjeta
    // ─────────────────────────────────────────────────────────────────

    public function reactivarTarjeta(Request $request)
    {
        $validated = $request->validate([
            'client_id'          => 'required|exists:clients,id',
            'payment_method_id'  => 'required|string',
            'from_installment_id' => 'required|exists:payment_installments,id',
        ]);

        try {
            $resultado = $this->pausarService->reactivarTarjeta(
                clientId:          $validated['client_id'],
                paymentMethodId:   $validated['payment_method_id'],
                fromInstallmentId: $validated['from_installment_id'],
                adminId:           $request->user()->id,
            );
            return response()->json($resultado);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  GET /api/membresias/riesgo — Dashboard de membresías en riesgo
    // ─────────────────────────────────────────────────────────────────

    public function riesgo(Request $request)
    {
        $atRisk = Membership::atRisk()
            ->with(['client', 'plan'])
            ->get()
            ->map(fn ($m) => [
                'id'                    => $m->id,
                'client_id'             => $m->client_id,
                'client_name'           => $m->client?->first_name . ' ' . $m->client?->last_name,
                'client_email'          => $m->client?->email,
                'plan_name'             => $m->plan?->name,
                'status'                => $m->status,
                'reactivation_error'    => $m->reactivation_error,
                'reactivation_error_at' => $m->reactivation_error_at?->format('d/m/Y H:i'),
                'advance_end_date'      => $m->advance_end_date?->format('d/m/Y'),
                'since_days'            => $m->reactivation_error_at
                    ? $m->reactivation_error_at->diffInDays(now())
                    : null,
            ]);

        $expiring = Membership::advancedExpiring(7)
            ->with(['client', 'plan'])
            ->get()
            ->map(fn ($m) => [
                'id'               => $m->id,
                'client_id'        => $m->client_id,
                'client_name'      => $m->client?->first_name . ' ' . $m->client?->last_name,
                'plan_name'        => $m->plan?->name,
                'status'           => $m->status,
                'advance_end_date' => $m->advance_end_date?->format('d/m/Y'),
                'days_left'        => $m->days_until_advance_ends,
                'wants_auto_renewal' => $m->wants_auto_renewal,
            ]);

        $paused = Membership::where('status', Membership::STATUS_PAUSED)
            ->with(['client', 'plan', 'activePause'])
            ->get()
            ->map(fn ($m) => [
                'id'            => $m->id,
                'client_id'     => $m->client_id,
                'client_name'   => $m->client?->first_name . ' ' . $m->client?->last_name,
                'plan_name'     => $m->plan?->name,
                'status'        => $m->status,
                'pause_end'     => $m->activePause?->pause_end?->format('d/m/Y'),
                'pause_reason'  => $m->activePause?->reason,
                'days_left'     => $m->days_until_pause_ends,
                'pause_id'      => $m->activePause?->id,
            ]);

        return response()->json([
            'summary' => [
                'at_risk'  => $atRisk->count(),
                'expiring' => $expiring->count(),
                'paused'   => $paused->count(),
                'date'     => now()->format('d/m/Y'),
            ],
            'at_risk'  => $atRisk,
            'expiring' => $expiring,
            'paused'   => $paused,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  POST /api/membresias/{id}/reactivar  (manual, admin)
    // ─────────────────────────────────────────────────────────────────

    /**
     * El admin reactiva manualmente una membresía at_risk.
     * Útil cuando el Job falló y el admin lo resuelve mismo.
     */
    public function reactivarManual(Request $request, int $id)
    {
        $membership = Membership::where('id', $id)
            ->where('status', Membership::STATUS_AT_RISK)
            ->with(['client', 'plan'])
            ->firstOrFail();

        try {
            // Reusar el Job despachado inmediatamente (no esperar al día siguiente)
            \App\Jobs\ReactivarSuscripcionesJob::dispatchSync();

            return response()->json([
                'message' => 'Job de reactivación disparado. Revisa el estado en unos segundos.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  PUT /api/membresias/{id}/wants-renewal
    // ─────────────────────────────────────────────────────────────────

    public function toggleWantsRenewal(Request $request, int $id)
    {
        $membership = Membership::findOrFail($id);

        $validated = $request->validate([
            'wants_auto_renewal' => 'required|boolean',
        ]);

        $membership->update(['wants_auto_renewal' => $validated['wants_auto_renewal']]);

        return response()->json([
            'wants_auto_renewal' => $membership->wants_auto_renewal,
            'message'            => $validated['wants_auto_renewal']
                ? '✅ El cobro automático se reactivará cuando venza el adelanto.'
                : '⚠️ El cobro automático NO se reactivará. El admin deberá hacerlo manualmente.',
        ]);
    }
}
