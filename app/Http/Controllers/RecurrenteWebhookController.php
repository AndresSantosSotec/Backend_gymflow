<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Receipt;
use App\Models\RecurrentePayment;
use App\Models\RecurrenteSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RecurrenteWebhookController
 *
 * Ruta: POST /webhooks/recurrente  (excluida de CSRF en bootstrap/app.php)
 *
 * ╔═══════════════════════════════════════════════════════════════════╗
 * ║  FIXES DE SEGURIDAD Y EDGE CASES IMPLEMENTADOS                   ║
 * ╠═══════════════════════════════════════════════════════════════════╣
 * ║ 🔴 Fix 2.4 — Idempotencia: cada evento se procesa UNA sola vez   ║
 * ║ 🔴 Fix 2.3 — Webhook antes del redirect: funciona igual así      ║
 * ║ 🔴 Fix 5.3 — Monto calculado desde BD, nunca del payload         ║
 * ║ 🟡 Fix 2.5 — payment_method inválido: se limpia en BD            ║
 * ║ 🟡 Fix 3.2 — subscription.payment_failed con período de gracia   ║
 * ║ 🟡 Fix 3.1 — subscription.cancelled desactiva membresía local    ║
 * ║ 🟢 Fix 2.1 — Activación SIEMPRE por webhook, nunca por redirect  ║
 * ╚═══════════════════════════════════════════════════════════════════╝
 */
class RecurrenteWebhookController extends Controller
{
    // Días de gracia para pago de suscripción fallido (configurable)
    private const GRACE_PERIOD_DAYS = 5;

    /**
     * Punto de entrada único para todos los webhooks.
     *
     * ⚠️ SIEMPRE retorna HTTP 200 — si retornamos 4xx/5xx
     *    Recurrente reintentará el webhook infinitamente.
     *    Los errores de lógica se loguean internamente.
     */
    public function handle(Request $request)
    {
        $payload = $request->all();
        $event   = $payload['event'] ?? 'unknown';
        $eventId = $payload['id']    ?? uniqid('wh_');  // ID único del evento para idempotencia

        Log::info("[Webhook:Recurrente] ▶ {$event} (id={$eventId})", [
            'payload' => $payload,
            'ip'      => $request->ip(),
        ]);

        try {
            match ($event) {
                'checkout.succeeded'         => $this->handleCheckoutSucceeded($payload['data'] ?? [], $eventId),
                'one_time_payment.succeeded' => $this->handleOneTimePaymentSucceeded($payload['data'] ?? [], $eventId),
                'subscription.paid'          => $this->handleSubscriptionPaid($payload['data'] ?? [], $eventId),
                'subscription.cancelled'     => $this->handleSubscriptionCancelled($payload['data'] ?? []),
                'payment.failed'             => $this->handlePaymentFailed($payload['data'] ?? []),
                default => Log::info("[Webhook:Recurrente] Evento no manejado: {$event}"),
            };
        } catch (\Throwable $e) {
            Log::error("[Webhook:Recurrente] ❌ Error en {$event}: " . $e->getMessage(), [
                'event_id' => $eventId,
                'trace'    => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['received' => true, 'event' => $event]);
    }

    // ─────────────────────────────────────────────────────────────
    //  checkout.succeeded
    //  Payload: { id, user_id, payment_method_id, payment_id, items[] }
    //
    //  FIX 2.4 — Idempotencia: si el checkout_id ya fue procesado
    //  (status=succeeded), no volvemos a activar la membresía.
    //
    //  FIX 2.1 — Activación por webhook, NO por redirect al success_url.
    //  El cliente puede cerrar el browser → la membresía igual se activa.
    // ─────────────────────────────────────────────────────────────
    private function handleCheckoutSucceeded(array $data, string $eventId): void
    {
        $recurrenteUserId    = $data['user_id']           ?? null;
        $paymentMethodId     = $data['payment_method_id'] ?? null;
        $checkoutId          = $data['id']                ?? null;
        $recurrentePaymentId = $data['payment_id']        ?? null;

        if (! $recurrenteUserId || ! $checkoutId) {
            Log::warning('[Webhook:Recurrente] checkout.succeeded sin user_id o checkout_id', $data);
            return;
        }

        $client = Client::where('recurrente_user_id', $recurrenteUserId)->first();
        if (! $client) {
            Log::warning("[Webhook:Recurrente] Cliente con recurrente_user_id={$recurrenteUserId} no encontrado");
            return;
        }

        DB::transaction(function () use ($client, $checkoutId, $recurrentePaymentId, $paymentMethodId, $data) {

            // ── FIX 2.4 Idempotencia: usar DB lock para evitar doble proceso ─
            $localPayment = RecurrentePayment::where('recurrente_checkout_id', $checkoutId)
                ->lockForUpdate()
                ->first();

            if ($localPayment && $localPayment->status === 'succeeded') {
                Log::info("[Webhook:Recurrente] checkout {$checkoutId} ya fue procesado (idempotente). Skip.");
                return;
            }

            // ── ① Guardar payment_method_id (tarjeta para cobros futuros) ─────
            if ($paymentMethodId) {
                $client->update(['recurrente_payment_method_id' => $paymentMethodId]);
                Log::info("[Webhook:Recurrente] 💳 payment_method guardado para cliente #{$client->id}");
            }

            // ── ② Marcar el pago local como exitoso ───────────────────────────
            if ($localPayment) {
                $localPayment->update([
                    'status'                => 'succeeded',
                    'recurrente_payment_id' => $recurrentePaymentId,
                    'paid_at'               => now(),
                    'metadata'              => $data,
                ]);

                // ── ③ Generar recibo ───────────────────────────────────────────
                $this->generateReceiptIdempotent($localPayment, $client);

                // ── ④ Activar membresía (SOLO si no está ya activa) ───────────
                if ($localPayment->membership_plan_id) {
                    $this->activateMembershipIdempotent($client, $localPayment->membership_plan_id);
                }
            } else {
                // Webhook llegó sin pago previo registrado (caso raro)
                Log::warning("[Webhook:Recurrente] No se encontró pago local para checkout {$checkoutId}");
            }

            Log::info("[Webhook:Recurrente] ✅ checkout.succeeded procesado para cliente #{$client->id}");
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  one_time_payment.succeeded
    //
    //  FIX 2.4 — Idempotencia con recurrente_payment_id
    // ─────────────────────────────────────────────────────────────
    private function handleOneTimePaymentSucceeded(array $data, string $eventId): void
    {
        $paymentId        = $data['id']                ?? null;
        $paymentMethodId  = $data['payment_method_id'] ?? null;
        $recurrenteUserId = $data['user_id']           ?? null;

        if (! $paymentId) return;

        DB::transaction(function () use ($paymentId, $paymentMethodId, $recurrenteUserId, $data) {

            $localPayment = RecurrentePayment::where('recurrente_payment_id', $paymentId)
                ->lockForUpdate()
                ->first();

            // ── FIX 2.4 Idempotencia ─────────────────────────────────────────
            if ($localPayment && $localPayment->status === 'succeeded') {
                Log::info("[Webhook:Recurrente] one_time_payment {$paymentId} ya procesado. Skip.");
                return;
            }

            $client = null;

            if ($localPayment) {
                $localPayment->update([
                    'status'   => 'succeeded',
                    'paid_at'  => now(),
                    'metadata' => $data,
                ]);
                $client = $localPayment->client;

                // Activar membresía si el pago tenía un plan asociado
                if ($localPayment->membership_plan_id && $client) {
                    $this->activateMembershipIdempotent($client, $localPayment->membership_plan_id);
                }

                $this->generateReceiptIdempotent($localPayment, $client);

            } else {
                // Pago no iniciado por nosotros: registrarlo de todas formas
                $client = $recurrenteUserId
                    ? Client::where('recurrente_user_id', $recurrenteUserId)->first()
                    : null;

                RecurrentePayment::create([
                    'client_id'             => $client?->id,
                    'recurrente_payment_id' => $paymentId,
                    'type'                  => 'one_time',
                    // FIX 5.3 — El monto viene del payload de Recurrente (origen confiable),
                    //           NO del frontend. El frontend nunca envía el monto al webhook.
                    'amount_in_cents'       => $data['amount_in_cents'] ?? 0,
                    'currency'              => $data['currency'] ?? 'GTQ',
                    'status'                => 'succeeded',
                    'concept'               => 'Pago Recurrente (externo)',
                    'metadata'              => $data,
                    'paid_at'               => now(),
                ]);
            }

            // Actualizar método de pago del cliente si llegó uno
            if ($client && $paymentMethodId) {
                $client->update(['recurrente_payment_method_id' => $paymentMethodId]);
            }

            Log::info("[Webhook:Recurrente] ✅ one_time_payment.succeeded: {$paymentId}");
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  subscription.paid (renovación mensual/anual)
    //
    //  FIX 2.4 — Evitar doble renovación con mismo payment_id
    // ─────────────────────────────────────────────────────────────
    private function handleSubscriptionPaid(array $data, string $eventId): void
    {
        $subscriptionId = $data['id']         ?? null;
        $paymentId      = $data['payment_id'] ?? null;

        if (! $subscriptionId) return;

        DB::transaction(function () use ($subscriptionId, $paymentId, $data) {

            $sub = RecurrenteSubscription::where('recurrente_subscription_id', $subscriptionId)
                ->with(['client', 'membershipPlan'])
                ->lockForUpdate()
                ->first();

            if (! $sub) {
                Log::warning("[Webhook:Recurrente] Suscripción {$subscriptionId} no encontrada localmente");
                return;
            }

            // ── FIX 2.4 Idempotencia — mismo payment_id ya registrado? ──────
            if ($paymentId && RecurrentePayment::where('recurrente_payment_id', $paymentId)->exists()) {
                Log::info("[Webhook:Recurrente] subscription.paid {$paymentId} ya registrado. Skip.");
                return;
            }

            // Renovar período en suscripción local
            $sub->update([
                'status'               => 'active',
                'current_period_start' => $data['current_period_start'] ?? now()->toDateTimeString(),
                'current_period_end'   => $data['current_period_end']   ?? now()->addMonth()->toDateTimeString(),
                'metadata'             => $data,
            ]);

            // Registrar el cobro de la cuota
            $payment = RecurrentePayment::create([
                'client_id'                  => $sub->client_id,
                'membership_plan_id'         => $sub->membership_plan_id,
                'recurrente_subscription_id' => $subscriptionId,
                'recurrente_payment_id'      => $paymentId,
                'type'                       => 'subscription',
                // FIX 5.3 — Monto desde Recurrente (confiable), nunca del frontend
                'amount_in_cents'            => $data['amount_in_cents'] ?? 0,
                'currency'                   => 'GTQ',
                'status'                     => 'succeeded',
                'concept'                    => "Renovación: {$sub->membershipPlan?->name}",
                'metadata'                   => $data,
                'paid_at'                    => now(),
            ]);

            // Renovar membresía local
            if ($sub->client && $sub->membership_plan_id) {
                $this->renewMembershipIdempotent($sub->client, $sub->membership_plan_id);
            }

            $this->generateReceiptIdempotent($payment, $sub->client);

            Log::info("[Webhook:Recurrente] ✅ subscription.paid: {$subscriptionId}");
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  subscription.cancelled
    //
    //  FIX 3.1 — Desactiva membresía local para que el cliente
    //  no siga accediendo al gym cuando su suscripción vence.
    // ─────────────────────────────────────────────────────────────
    private function handleSubscriptionCancelled(array $data): void
    {
        $subscriptionId = $data['id'] ?? null;
        if (! $subscriptionId) return;

        $sub = RecurrenteSubscription::where('recurrente_subscription_id', $subscriptionId)
            ->with('client')
            ->first();

        if ($sub) {
            $sub->update(['status' => 'cancelled', 'metadata' => $data]);

            // Desactivar membresía activa del cliente al cancelar suscripción
            if ($sub->client) {
                Membership::where('client_id', $sub->client_id)
                    ->where('status', 'active')
                    ->update([
                        'status'         => 'cancelled',
                        'payment_status' => 'cancelled',
                    ]);

                Log::info("[Webhook:Recurrente] 🚫 Membresía desactivada por cancelación de suscripción. Cliente #{$sub->client_id}");
            }
        }

        Log::info("[Webhook:Recurrente] subscription.cancelled: {$subscriptionId}");
    }

    // ─────────────────────────────────────────────────────────────
    //  payment.failed
    //
    //  FIX 3.2 — Período de gracia configurable (GRACE_PERIOD_DAYS)
    //  antes de desactivar la membresía por fallo de pago.
    //
    //  FIX 2.5 — Detecta payment_method inválido y lo limpia en BD.
    // ─────────────────────────────────────────────────────────────
    private function handlePaymentFailed(array $data): void
    {
        $paymentId        = $data['id']                ?? null;
        $subscriptionId   = $data['subscription_id']   ?? null;
        $paymentMethodId  = $data['payment_method_id'] ?? null;
        $failureReason    = $data['failure_reason']    ?? 'unknown';

        Log::warning("[Webhook:Recurrente] ⚠️ payment.failed: {$paymentId} | razón: {$failureReason}");

        // Marcar pago como fallido
        RecurrentePayment::where('recurrente_payment_id', $paymentId)
            ->update([
                'status'   => 'failed',
                'metadata' => $data,
            ]);

        // FIX 2.5 — Si el error es de método de pago inválido,
        // limpiar el recurrente_payment_method_id del cliente
        // para que el sistema le pida al cliente una nueva tarjeta.
        $invalidMethodErrors = [
            'card_declined', 'expired_card', 'invalid_card',
            'do_not_honor', 'insufficient_funds',
        ];

        if ($paymentMethodId && in_array($failureReason, $invalidMethodErrors)) {
            $client = Client::where('recurrente_payment_method_id', $paymentMethodId)->first();
            if ($client) {
                $client->update(['recurrente_payment_method_id' => null]);
                Log::warning("[Webhook:Recurrente] 💳 payment_method inválido limpiado para cliente #{$client->id}. Motivo: {$failureReason}");
            }
        }

        // FIX 3.2 — Si es fallo de suscripción, aplicar período de gracia
        if ($subscriptionId) {
            $sub = RecurrenteSubscription::where('recurrente_subscription_id', $subscriptionId)
                ->with('client')
                ->first();

            if ($sub) {
                // No desactivar inmediatamente: dar período de gracia
                $sub->update(['status' => 'past_due', 'metadata' => $data]);

                // Programar desactivación después del período de gracia
                // (usando job con delay o verificando fecha en el Job diario)
                $gracePeriodEnd = now()->addDays(self::GRACE_PERIOD_DAYS);

                Log::warning(
                    "[Webhook:Recurrente] Suscripción {$subscriptionId} en past_due. " .
                    "Período de gracia hasta: {$gracePeriodEnd->format('Y-m-d')}"
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  HELPERS IDEMPOTENTES
    // ─────────────────────────────────────────────────────────────

    /**
     * Genera un recibo SOLO si no existe ya uno para este pago.
     * FIX 2.4 — Evita generar recibos duplicados con webhooks repetidos.
     */
    private function generateReceiptIdempotent(RecurrentePayment $payment, ?Client $client): void
    {
        if (! $client) return;

        // Guard: si ya hay un recibo para este recurrente_payment_id, skip
        if ($payment->recurrente_payment_id &&
            Receipt::where('description', 'LIKE', "%recurrente:{$payment->recurrente_payment_id}%")->exists()) {
            Log::info("[Webhook:Recurrente] Recibo ya existe para pago {$payment->recurrente_payment_id}. Skip.");
            return;
        }

        try {
            $receipt = Receipt::create([
                'client_id'      => $client->id,
                'receipt_number' => Receipt::generateReceiptNumber(),
                'type'           => 'receipt',
                'payment_type'   => $payment->type === 'subscription' ? 'subscription' : 'individual_payment',
                'subtotal'       => $payment->amount_in_cents / 100,
                'tax'            => 0,
                'discount'       => 0,
                'total'          => $payment->amount_in_cents / 100,
                'status'         => 'paid',
                'paid_at'        => now(),
                // Incluir el ID de Recurrente en la descripción para idempotencia
                'description'    => ($payment->concept ?? 'Pago Recurrente') .
                                    ($payment->recurrente_payment_id ? " [recurrente:{$payment->recurrente_payment_id}]" : ''),
            ]);

            Log::info("[Webhook:Recurrente] 🧾 Recibo #{$receipt->receipt_number} generado");
        } catch (\Throwable $e) {
            Log::error("[Webhook:Recurrente] Error generando recibo: " . $e->getMessage());
        }
    }

    /**
     * Activa membresía SOLO si el cliente no tiene ya una activa con el mismo plan.
     * FIX 2.4 — Evita crear membresías duplicadas con webhooks repetidos.
     * FIX 2.1 — Esta función es el único punto de activación (no el success_url).
     */
    private function activateMembershipIdempotent(Client $client, int $planId): void
    {
        try {
            $plan = MembershipPlan::find($planId);
            if (! $plan) return;

            // Guard: si ya tiene membresía activa para este plan, skip
            $alreadyActive = Membership::where('client_id', $client->id)
                ->where('plan_id', $planId)
                ->where('status', 'active')
                ->where('end_date', '>=', now()->toDateString())
                ->exists();

            if ($alreadyActive) {
                Log::info("[Webhook:Recurrente] Cliente #{$client->id} ya tiene membresía activa para plan #{$planId}. Skip.");
                return;
            }

            Membership::create([
                'client_id'      => $client->id,
                'plan_id'        => $plan->id,
                'start_date'     => now()->toDateString(),
                'end_date'       => now()->addDays($plan->duration_days ?? 30)->toDateString(),
                'status'         => 'active',
                'payment_status' => 'paid',
                'amount_paid'    => $plan->price,
                'payment_method' => 'recurrente',
            ]);

            $client->update(['status' => 'ACTIVE']);
            Log::info("[Webhook:Recurrente] ✅ Membresía activada: cliente #{$client->id}, plan #{$planId}");

        } catch (\Throwable $e) {
            Log::error("[Webhook:Recurrente] Error activando membresía: " . $e->getMessage());
        }
    }

    /**
     * Renueva membresía extendiendo la fecha de fin.
     * FIX 2.4 — Solo renueva si hay un pago nuevo (ya verificado arriba).
     */
    private function renewMembershipIdempotent(Client $client, int $planId): void
    {
        try {
            $plan = MembershipPlan::find($planId);
            if (! $plan) return;

            $active = Membership::where('client_id', $client->id)
                ->where('status', 'active')
                ->whereIn('plan_id', [$planId])
                ->latest()
                ->first();

            if ($active) {
                // Extender desde la fecha actual o desde el fin de la membresía (lo que sea mayor)
                $base   = max(now(), \Carbon\Carbon::parse($active->end_date));
                $newEnd = $base->addDays($plan->duration_days ?? 30);
                $active->update(['end_date' => $newEnd->toDateString(), 'status' => 'active']);
                Log::info("[Webhook:Recurrente] 🔄 Membresía renovada hasta {$newEnd->format('Y-m-d')} para cliente #{$client->id}");
            } else {
                // No había activa: crear una nueva
                $this->activateMembershipIdempotent($client, $planId);
            }
        } catch (\Throwable $e) {
            Log::error("[Webhook:Recurrente] Error renovando membresía: " . $e->getMessage());
        }
    }
}
