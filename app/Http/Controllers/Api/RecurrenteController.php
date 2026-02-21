<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MembershipPlan;
use App\Models\RecurrentePayment;
use App\Models\RecurrenteSubscription;
use App\Services\RecurrenteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * RecurrenteController
 *
 * Gestiona checkouts, cobros directos y suscripciones vía Recurrente.
 *
 * Rutas:
 *   POST  /api/pagos/checkout         → createCheckout()
 *   POST  /api/pagos/cobrar            → chargeCard()
 *   POST  /api/suscripciones/crear     → createSubscription()
 *   DELETE /api/suscripciones/{id}     → cancelSubscription()
 *   GET   /api/pagos/historial/{clientId} → paymentHistory()
 */
class RecurrenteController extends Controller
{
    public function __construct(private RecurrenteService $recurrente)
    {}

    // ─────────────────────────────────────────────
    //  FASE 3 — Checkout hosteado (link de pago)
    // ─────────────────────────────────────────────

    /**
     * Crear un checkout en Recurrente para pago de plan.
     *
     * Request: { client_id, plan_id }
     * Response: { checkout_url, checkout_id }
     *
     * Flujo:
     * 1. Validar cliente y plan
     * 2. Si el cliente no tiene recurrente_user_id → crearlo ahora
     * 3. Si el plan no tiene recurrente_product_id → error (sync pendiente)
     * 4. POST /api/checkouts → URL de pago
     * 5. Devolver URL al frontend para redirigir al cliente
     */
    public function createCheckout(Request $request)
    {
        $data = $request->validate([
            'client_id'   => 'required|exists:clients,id',
            'plan_id'     => 'required|exists:membership_plans,id',
            'success_url' => 'nullable|url',
            'cancel_url'  => 'nullable|url',
        ]);

        $client = Client::findOrFail($data['client_id']);
        $plan   = MembershipPlan::findOrFail($data['plan_id']);

        // Asegurar que el plan esté sincronizado
        if (! $plan->recurrente_product_id) {
            return response()->json([
                'error' => 'El plan no está sincronizado con Recurrente. Ejecuta: php artisan recurrente:sync-products',
            ], 422);
        }

        // Si el cliente no tiene usuario en Recurrente, crearlo on-the-fly
        if (! $client->recurrente_user_id) {
            $nameParts = explode(' ', trim($client->name), 2);
            $userRes   = $this->recurrente->createUser([
                'first_name' => $nameParts[0],
                'last_name'  => $nameParts[1] ?? '-',
                'email'      => $client->email ?? "client{$client->id}@gymflow.local",
                'phone'      => $client->phone ?? null,
            ]);
            $client->update(['recurrente_user_id' => $userRes['id']]);
        }

        // URLs de retorno
        $frontendUrl = env('APP_FRONTEND_URL', 'http://localhost:5173');
        $successUrl  = $data['success_url'] ?? "{$frontendUrl}/pagos/exitoso";
        $cancelUrl   = $data['cancel_url']  ?? "{$frontendUrl}/pagos/cancelado";

        // Crear checkout en Recurrente
        $checkout = $this->recurrente->createCheckout([
            'user_id'     => $client->recurrente_user_id,
            'items'       => [[
                'product_id' => $plan->recurrente_product_id,
                'quantity'   => 1,
            ]],
            'success_url' => $successUrl . "?client_id={$client->id}&plan_id={$plan->id}",
            'cancel_url'  => $cancelUrl,
        ]);

        // Registrar checkout pendiente
        RecurrentePayment::create([
            'client_id'              => $client->id,
            'membership_plan_id'     => $plan->id,
            'recurrente_checkout_id' => $checkout['id'] ?? null,
            'type'                   => 'checkout',
            'amount_in_cents'        => (int) round(floatval($plan->price) * 100),
            'currency'               => 'GTQ',
            'status'                 => 'pending',
            'concept'                => "Membresía: {$plan->name}",
        ]);

        return response()->json([
            'checkout_url' => $checkout['checkout_url'] ?? $checkout['url'] ?? null,
            'checkout_id'  => $checkout['id'] ?? null,
        ]);
    }

    // ─────────────────────────────────────────────
    //  FASE 4 — Cobro directo con tarjeta guardada
    // ─────────────────────────────────────────────

    /**
     * Cobrar a un cliente con su tarjeta guardada (tokenizada).
     *
     * Request: { client_id, amount_in_cents, concept }
     * Response: { payment_id, status }
     *
     * Requisitos:
     * - El cliente debe tener recurrente_payment_method_id
     *   (se obtiene del webhook checkout.succeeded)
     */
    public function chargeCard(Request $request)
    {
        $data = $request->validate([
            'client_id'     => 'required|exists:clients,id',
            'amount_in_cents' => 'required|integer|min:100', // mínimo Q1.00
            'concept'       => 'required|string|max:200',
            'plan_id'       => 'nullable|exists:membership_plans,id',
        ]);

        $client = Client::findOrFail($data['client_id']);

        if (! $client->recurrente_payment_method_id) {
            return response()->json([
                'error' => 'El cliente no tiene método de pago guardado. Usa el checkout primero.',
            ], 422);
        }

        // POST /api/one_time_payments
        $payment = $this->recurrente->createOneTimePayment([
            'payment_method_id' => $client->recurrente_payment_method_id,
            'items' => [[
                'name'            => $data['concept'],
                'amount_in_cents' => $data['amount_in_cents'],
                'currency'        => 'GTQ',
            ]],
        ]);

        // Registrar en BD local
        $record = RecurrentePayment::create([
            'client_id'          => $client->id,
            'membership_plan_id' => $data['plan_id'] ?? null,
            'recurrente_payment_id' => $payment['id'] ?? null,
            'type'               => 'one_time',
            'amount_in_cents'    => $data['amount_in_cents'],
            'currency'           => 'GTQ',
            'status'             => $payment['status'] ?? 'pending',
            'concept'            => $data['concept'],
            'metadata'           => $payment,
            'paid_at'            => ($payment['status'] ?? '') === 'succeeded' ? now() : null,
        ]);

        return response()->json([
            'payment_id' => $record->id,
            'status'     => $record->status,
            'message'    => $record->status === 'succeeded' ? 'Cobro exitoso' : 'Pago procesándose',
        ]);
    }

    // ─────────────────────────────────────────────
    //  FASE 5 — Suscripciones recurrentes
    // ─────────────────────────────────────────────

    /**
     * Crear suscripción mensual/anual en Recurrente.
     *
     * Request: { client_id, plan_id }
     * Response: { subscription_id, status }
     */
    public function createSubscription(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'plan_id'   => 'required|exists:membership_plans,id',
        ]);

        $client = Client::findOrFail($data['client_id']);
        $plan   = MembershipPlan::findOrFail($data['plan_id']);

        if (! $client->recurrente_user_id) {
            return response()->json(['error' => 'Cliente no sincronizado con Recurrente'], 422);
        }
        if (! $plan->recurrente_product_id) {
            return response()->json(['error' => 'Plan no sincronizado con Recurrente'], 422);
        }

        // POST /api/subscriptions
        $sub = $this->recurrente->createSubscription([
            'user_id'    => $client->recurrente_user_id,
            'product_id' => $plan->recurrente_product_id,
        ]);

        $record = RecurrenteSubscription::create([
            'client_id'                   => $client->id,
            'membership_plan_id'          => $plan->id,
            'recurrente_subscription_id'  => $sub['id'],
            'recurrente_product_id'       => $plan->recurrente_product_id,
            'status'                      => $sub['status'] ?? 'active',
            'metadata'                    => $sub,
        ]);

        return response()->json([
            'subscription_id'            => $record->id,
            'recurrente_subscription_id' => $record->recurrente_subscription_id,
            'status'                     => $record->status,
        ], 201);
    }

    /**
     * Cancelar una suscripción activa.
     * DELETE /api/suscripciones/{id}
     */
    public function cancelSubscription(int $id)
    {
        $sub = RecurrenteSubscription::findOrFail($id);

        $this->recurrente->cancelSubscription($sub->recurrente_subscription_id);

        $sub->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Suscripción cancelada correctamente',
            'id'      => $sub->id,
        ]);
    }

    /**
     * Historial de pagos de un cliente.
     * GET /api/pagos/historial/{clientId}
     */
    public function paymentHistory(int $clientId)
    {
        $payments = RecurrentePayment::where('client_id', $clientId)
            ->with('membershipPlan')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($payments);
    }
}
