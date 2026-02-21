<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Receipt;
use App\Models\RecurrentePayment;
use App\Models\RecurrenteSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * RecurrenteWebhookController
 *
 * Recibe eventos POST desde Recurrente y actúa en consecuencia.
 *
 * Ruta: POST /webhooks/recurrente   (excluida de CSRF en bootstrap/app.php)
 *
 * Eventos manejados:
 * ┌──────────────────────────────┬──────────────────────────────────────────────┐
 * │ checkout.succeeded           │ Activar membresía + guardar payment_method_id │
 * │ one_time_payment.succeeded   │ Marcar pago como pagado + generar recibo      │
 * │ subscription.paid            │ Renovar membresía + registrar pago            │
 * │ subscription.cancelled       │ Desactivar suscripción local                 │
 * │ payment.failed               │ Marcar cuota vencida + log                   │
 * └──────────────────────────────┴──────────────────────────────────────────────┘
 */
class RecurrenteWebhookController extends Controller
{
    /**
     * Punto de entrada único para todos los webhooks.
     *
     * Recurrente envía:
     * {
     *   "event": "checkout.succeeded",
     *   "data": { ... payload específico del evento ... }
     * }
     */
    public function handle(Request $request)
    {
        $payload = $request->all();
        $event   = $payload['event'] ?? 'unknown';

        Log::info("[Webhook Recurrente] Evento recibido: {$event}", ['payload' => $payload]);

        try {
            match ($event) {
                'checkout.succeeded'          => $this->handleCheckoutSucceeded($payload['data'] ?? []),
                'one_time_payment.succeeded'  => $this->handleOneTimePaymentSucceeded($payload['data'] ?? []),
                'subscription.paid'           => $this->handleSubscriptionPaid($payload['data'] ?? []),
                'subscription.cancelled'      => $this->handleSubscriptionCancelled($payload['data'] ?? []),
                'payment.failed'              => $this->handlePaymentFailed($payload['data'] ?? []),
                default => Log::info("[Webhook Recurrente] Evento no manejado: {$event}"),
            };
        } catch (\Throwable $e) {
            // Registrar error pero devolver 200 para que Recurrente no reintente
            Log::error("[Webhook Recurrente] Error procesando {$event}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Siempre retornar 200: Recurrente reintentará si recibe error
        return response()->json(['received' => true]);
    }

    // ─────────────────────────────────────────────────────────────
    //  checkout.succeeded
    //  Payload esperado: { id, user_id, payment_method_id, items[] }
    // ─────────────────────────────────────────────────────────────
    private function handleCheckoutSucceeded(array $data): void
    {
        $recurrenteUserId      = $data['user_id']          ?? null;
        $paymentMethodId       = $data['payment_method_id'] ?? null;
        $checkoutId            = $data['id']                ?? null;
        $recurrentePaymentId   = $data['payment_id']        ?? null;

        if (! $recurrenteUserId) {
            Log::warning('[Webhook] checkout.succeeded sin user_id');
            return;
        }

        // Buscar cliente por su recurrente_user_id
        $client = Client::where('recurrente_user_id', $recurrenteUserId)->first();
        if (! $client) {
            Log::warning("[Webhook] checkout.succeeded: cliente con recurrente_user_id={$recurrenteUserId} no encontrado");
            return;
        }

        // ① Guardar payment_method_id (tarjeta tokenizada para futuros cobros)
        if ($paymentMethodId && ! $client->recurrente_payment_method_id) {
            $client->update(['recurrente_payment_method_id' => $paymentMethodId]);
            Log::info("[Webhook] payment_method guardado para cliente #{$client->id}");
        }

        // ② Marcar el RecurrentePayment pendiente como exitoso
        $localPayment = RecurrentePayment::where('recurrente_checkout_id', $checkoutId)
            ->orWhere('recurrente_payment_id', $recurrentePaymentId)
            ->first();

        if ($localPayment) {
            $localPayment->update([
                'status'               => 'succeeded',
                'recurrente_payment_id' => $recurrentePaymentId,
                'paid_at'              => now(),
                'metadata'             => $data,
            ]);

            // ③ Generar recibo automático si hay un membership vinculado
            $this->generateReceiptForPayment($localPayment, $client);

            // ④ Activar membresía del plan
            if ($localPayment->membership_plan_id) {
                $this->activateMembership($client, $localPayment->membership_plan_id);
            }
        }

        Log::info("[Webhook] checkout.succeeded procesado para cliente #{$client->id}");
    }

    // ─────────────────────────────────────────────────────────────
    //  one_time_payment.succeeded
    //  Payload: { id, payment_method_id, user_id, status, items[] }
    // ─────────────────────────────────────────────────────────────
    private function handleOneTimePaymentSucceeded(array $data): void
    {
        $paymentId             = $data['id']                ?? null;
        $paymentMethodId       = $data['payment_method_id'] ?? null;
        $recurrenteUserId      = $data['user_id']           ?? null;

        // Actualizar pago local
        $localPayment = RecurrentePayment::where('recurrente_payment_id', $paymentId)->first();

        if ($localPayment) {
            $localPayment->update([
                'status'   => 'succeeded',
                'paid_at'  => now(),
                'metadata' => $data,
            ]);

            $client = $localPayment->client;

            // Actualizar payment_method si llegó uno nuevo
            if ($client && $paymentMethodId && ! $client->recurrente_payment_method_id) {
                $client->update(['recurrente_payment_method_id' => $paymentMethodId]);
            }

            $this->generateReceiptForPayment($localPayment, $client);
        } else {
            // Pago que no iniciamos nosotros (raro): registrarlo igual
            $client = $recurrenteUserId
                ? Client::where('recurrente_user_id', $recurrenteUserId)->first()
                : null;

            RecurrentePayment::create([
                'client_id'            => $client?->id,
                'recurrente_payment_id' => $paymentId,
                'type'                 => 'one_time',
                'amount_in_cents'      => $data['amount_in_cents'] ?? 0,
                'currency'             => $data['currency'] ?? 'GTQ',
                'status'               => 'succeeded',
                'concept'              => 'Pago vía Recurrente',
                'metadata'             => $data,
                'paid_at'              => now(),
            ]);
        }

        Log::info("[Webhook] one_time_payment.succeeded procesado: {$paymentId}");
    }

    // ─────────────────────────────────────────────────────────────
    //  subscription.paid
    //  Payload: { id, user_id, product_id, period_start, period_end }
    // ─────────────────────────────────────────────────────────────
    private function handleSubscriptionPaid(array $data): void
    {
        $subscriptionId = $data['id']      ?? null;
        $userId         = $data['user_id'] ?? null;

        $sub = RecurrenteSubscription::where('recurrente_subscription_id', $subscriptionId)->first();

        if ($sub) {
            // Renovar periodo de membresía
            $sub->update([
                'status'                => 'active',
                'current_period_start'  => $data['current_period_start'] ?? now(),
                'current_period_end'    => $data['current_period_end']   ?? now()->addMonth(),
                'metadata'              => $data,
            ]);

            // Registrar el pago de la cuota
            $payment = RecurrentePayment::create([
                'client_id'                  => $sub->client_id,
                'membership_plan_id'         => $sub->membership_plan_id,
                'recurrente_subscription_id' => $subscriptionId,
                'recurrente_payment_id'      => $data['payment_id'] ?? null,
                'type'                       => 'subscription',
                'amount_in_cents'            => $data['amount_in_cents'] ?? 0,
                'currency'                   => 'GTQ',
                'status'                     => 'succeeded',
                'concept'                    => "Pago suscripción: {$sub->membershipPlan?->name}",
                'metadata'                   => $data,
                'paid_at'                    => now(),
            ]);

            // Extender membresía en el sistema
            if ($sub->membership_plan_id) {
                $this->renewMembership($sub->client, $sub->membership_plan_id);
            }

            $this->generateReceiptForPayment($payment, $sub->client);
        }

        Log::info("[Webhook] subscription.paid procesado: {$subscriptionId}");
    }

    // ─────────────────────────────────────────────────────────────
    //  subscription.cancelled
    // ─────────────────────────────────────────────────────────────
    private function handleSubscriptionCancelled(array $data): void
    {
        $subscriptionId = $data['id'] ?? null;

        RecurrenteSubscription::where('recurrente_subscription_id', $subscriptionId)
            ->update([
                'status'   => 'cancelled',
                'metadata' => $data,
            ]);

        Log::info("[Webhook] subscription.cancelled: {$subscriptionId}");
    }

    // ─────────────────────────────────────────────────────────────
    //  payment.failed
    // ─────────────────────────────────────────────────────────────
    private function handlePaymentFailed(array $data): void
    {
        $paymentId      = $data['id']            ?? null;
        $subscriptionId = $data['subscription_id'] ?? null;

        // Marcar pago como fallido
        RecurrentePayment::where('recurrente_payment_id', $paymentId)
            ->update([
                'status'   => 'failed',
                'metadata' => $data,
            ]);

        // Si es de una suscripción, marcarla como past_due
        if ($subscriptionId) {
            RecurrenteSubscription::where('recurrente_subscription_id', $subscriptionId)
                ->update(['status' => 'past_due']);
        }

        Log::warning("[Webhook] payment.failed: {$paymentId}", ['data' => $data]);
    }

    // ─────────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────────

    /**
     * Genera un recibo automático después de un pago exitoso.
     */
    private function generateReceiptForPayment(RecurrentePayment $payment, ?Client $client): void
    {
        if (! $client) return;

        try {
            // Evitar duplicados
            if ($payment->receipt_id ?? null) return;

            $receipt = Receipt::create([
                'client_id'     => $client->id,
                'receipt_number' => Receipt::generateReceiptNumber(),
                'type'          => 'receipt',
                'payment_type'  => $payment->type === 'subscription' ? 'subscription' : 'individual_payment',
                'subtotal'      => $payment->amount_in_cents / 100,
                'tax'           => 0,
                'discount'      => 0,
                'total'         => $payment->amount_in_cents / 100,
                'status'        => 'paid',
                'paid_at'       => now(),
                'description'   => $payment->concept ?? 'Pago Recurrente',
            ]);

            Log::info("[Webhook] Recibo #{$receipt->receipt_number} generado para pago Recurrente #{$payment->id}");
        } catch (\Throwable $e) {
            Log::error("[Webhook] Error generando recibo: " . $e->getMessage());
        }
    }

    /**
     * Activa o crea una membresía para el cliente.
     */
    private function activateMembership(Client $client, int $planId): void
    {
        try {
            $plan = MembershipPlan::find($planId);
            if (! $plan) return;

            Membership::create([
                'client_id'            => $client->id,
                'plan_id'              => $plan->id,
                'start_date'           => now()->toDateString(),
                'end_date'             => now()->addDays($plan->duration_days ?? 30)->toDateString(),
                'status'               => 'active',
                'payment_status'       => 'paid',
                'amount_paid'          => $plan->price,
                'payment_method'       => 'recurrente',
            ]);

            $client->update(['status' => 'ACTIVE']);
            Log::info("[Webhook] Membresía activada para cliente #{$client->id}, plan #{$planId}");
        } catch (\Throwable $e) {
            Log::error("[Webhook] Error activando membresía: " . $e->getMessage());
        }
    }

    /**
     * Renueva (extiende) la membresía activa del cliente.
     */
    private function renewMembership(Client $client, int $planId): void
    {
        try {
            $plan = MembershipPlan::find($planId);
            if (! $plan) return;

            $active = Membership::where('client_id', $client->id)
                ->where('status', 'active')
                ->latest()
                ->first();

            if ($active) {
                $newEnd = max(now(), \Carbon\Carbon::parse($active->end_date))
                    ->addDays($plan->duration_days ?? 30);
                $active->update(['end_date' => $newEnd->toDateString()]);
            } else {
                $this->activateMembership($client, $planId);
            }
        } catch (\Throwable $e) {
            Log::error("[Webhook] Error renovando membresía: " . $e->getMessage());
        }
    }
}
