<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MembershipPlan;
use App\Models\RecurrentePayment;
use App\Models\RecurrenteSubscription;
use App\Services\RecurrenteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RecurrenteController
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  FIXES DE QA IMPLEMENTADOS                                   ║
 * ╠══════════════════════════════════════════════════════════════╣
 * ║ 🔴 Fix 5.3 — Monto calculado desde BD si hay plan_id        ║
 * ║ 🔴 Fix 2.2 — Anti doble-click: check pago duplicado 60s     ║
 * ║ 🔴 Fix 3.3 — Bloquear suscripción duplicada                 ║
 * ╚══════════════════════════════════════════════════════════════╝
 */
class RecurrenteController extends Controller
{
    public function __construct(private RecurrenteService $recurrente)
    {}

    // ─────────────────────────────────────────────────────────────
    //  CASO 1 — Checkout hosteado (inscripción / primera vez)
    // ─────────────────────────────────────────────────────────────

    /**
     * Crear checkout hosteado en Recurrente.
     *
     * Request:  { client_id, plan_id, success_url?, cancel_url? }
     * Response: { checkout_url, checkout_id }
     *
     * FIX 5.3 — El monto nunca viene del frontend: se obtiene
     * del plan en BD vía plan_id.
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

        if (! $plan->recurrente_product_id) {
            return response()->json([
                'error' => 'El plan no está sincronizado con Recurrente. Ejecuta: php artisan recurrente:sync-products',
            ], 422);
        }

        // Crear usuario en Recurrente si no existe
        if (! $client->recurrente_user_id) {
            $userRes   = $this->recurrente->createUser([
                'first_name' => $client->first_name ?: 'Cliente',
                'last_name'  => $client->last_name ?: '-',
                'email'      => $client->email ?? "client{$client->id}@irongym.local",
                'phone'      => $client->phone ?? null,
            ]);
            $client->update(['recurrente_user_id' => $userRes['id']]);
        }

        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $successUrl  = $data['success_url'] ?? "{$frontendUrl}/pagos/exitoso?client_id={$client->id}&plan_id={$plan->id}";
        $cancelUrl   = $data['cancel_url']  ?? "{$frontendUrl}/pagos/cancelado";

        $checkout = $this->recurrente->createCheckout([
            'user_id'     => $client->recurrente_user_id,
            'items'       => [[
                'product_id' => $plan->recurrente_product_id,
                'quantity'   => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
        ]);

        RecurrentePayment::create([
            'client_id'              => $client->id,
            'membership_plan_id'     => $plan->id,
            'recurrente_checkout_id' => $checkout['id'] ?? null,
            'type'                   => 'checkout',
            // FIX 5.3 — precio siempre desde BD, nunca del cliente
            'amount_in_cents'        => (int) round(floatval($plan->price) * 100),
            'currency'               => 'GTQ',
            'status'                 => 'pending',
            'concept'                => "Membresía: {$plan->name}",
        ]);

        // Debug: log full checkout response to find the correct URL field
        \Illuminate\Support\Facades\Log::info('[Recurrente] Full checkout response keys: ' . implode(', ', array_keys($checkout)), ['checkout' => $checkout]);

        // Recurrente returns `checkout_url` or `url` or `storefront_link` depending on version
        $checkoutUrl = $checkout['checkout_url']
            ?? $checkout['url']
            ?? $checkout['storefront_link']
            ?? null;

        return response()->json([
            'checkout_url' => $checkoutUrl,
            'checkout_id'  => $checkout['id'] ?? null,
            '_debug_keys'  => array_keys($checkout), // Remove after debugging
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  CASO 2 — Cobro con tarjeta guardada (Tokenized Payment)
    // ─────────────────────────────────────────────────────────────

    /**
     * Cobrar a un cliente con su tarjeta guardada.
     *
     * Request:  { client_id, plan_id }  ← monto desde BD (FIX 5.3)
     *        O: { client_id, amount_in_cents, concept } ← cobro ad-hoc
     * Response: { payment_id, status, amount_gtq, message }
     *
     * FIX 5.3 — Si hay plan_id, el monto se obtiene de la BD.
     *           El frontend puede enviar amount_in_cents solo para cobros
     *           ad-hoc (ej: mantenimiento, penalidades).
     *
     * FIX 2.2 — Se detecta doble click verificando pagos 'paid'
     *           del mismo cliente+plan en los últimos 60 segundos.
     */
    public function chargeCard(Request $request)
    {
        $data = $request->validate([
            'client_id'       => 'required|exists:clients,id',
            'plan_id'         => 'nullable|exists:membership_plans,id',
            'amount_in_cents' => 'nullable|integer|min:100',
            'concept'         => 'nullable|string|max:200',
        ]);

        if (empty($data['plan_id']) && empty($data['amount_in_cents'])) {
            return response()->json(['error' => 'Debes indicar plan_id o amount_in_cents'], 422);
        }

        $client = Client::findOrFail($data['client_id']);

        if (! $client->recurrente_payment_method_id) {
            return response()->json([
                'error'             => 'El cliente no tiene método de pago guardado.',
                'requires_checkout' => true,
            ], 422);
        }

        // ── FIX 5.3 — Monto desde BD cuando hay plan_id ──────────────────
        if (! empty($data['plan_id'])) {
            $plan          = MembershipPlan::findOrFail($data['plan_id']);
            $amountInCents = (int) round(floatval($plan->price) * 100);
            $concept       = "Membresía: {$plan->name}";
        } else {
            $amountInCents = $data['amount_in_cents'];
            $concept       = $data['concept'] ?? 'Pago IronGym';
        }

        // ── FIX 2.2 — Anti doble-click: pago duplicado en los últimos 60s ─
        $duplicate = RecurrentePayment::where('client_id', $client->id)
            ->whereIn('status', ['paid', 'succeeded'])
            ->when(! empty($data['plan_id']), fn ($q) => $q->where('membership_plan_id', $data['plan_id']))
            ->where('created_at', '>=', now()->subSeconds(60))
            ->exists();

        if ($duplicate) {
            return response()->json([
                'error'     => 'Cobro duplicado detectado. Espera un momento antes de reintentar.',
                'duplicate' => true,
            ], 429);
        }

        return DB::transaction(function () use ($client, $data, $amountInCents, $concept) {

            // ── POST /api/one_time_payments (payload correcto según docs) ──
            $payment = $this->recurrente->createOneTimePayment(
                $client->recurrente_payment_method_id,
                [[
                    'name'            => $concept,
                    'currency'        => 'GTQ',
                    'amount_in_cents' => $amountInCents,  // ← siempre desde BD (Fix 5.3)
                    'quantity'        => 1,
                ]],
                $client->recurrente_user_id ? ['user_id' => $client->recurrente_user_id] : []
            );

            $status = $payment['status'] ?? 'pending';

            $record = RecurrentePayment::create([
                'client_id'             => $client->id,
                'membership_plan_id'    => $data['plan_id'] ?? null,
                'recurrente_payment_id' => $payment['id'] ?? null,
                'type'                  => 'one_time',
                'amount_in_cents'       => $amountInCents,
                'currency'              => 'GTQ',
                'status'                => $status,
                'concept'               => $concept,
                'metadata'              => $payment,
                'paid_at'               => in_array($status, ['paid', 'succeeded']) ? now() : null,
            ]);

            return response()->json([
                'payment_id'            => $record->id,
                'recurrente_payment_id' => $payment['id'] ?? null,
                'status'                => $status,
                'amount_gtq'            => 'Q' . number_format($amountInCents / 100, 2),
                'message'               => in_array($status, ['paid', 'succeeded'])
                    ? '✅ Cobro exitoso'
                    : 'Pago procesándose',
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  CASO 3 — Suscripción recurrente
    // ─────────────────────────────────────────────────────────────

    /**
     * Activar suscripción recurrente mensual/anual.
     *
     * FIX 3.3 — Verificar que el cliente no tenga ya una suscripción
     * activa antes de crear otra. Informar al frontend para que muestre
     * la opción de cancelar primero.
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
            return response()->json([
                'error' => 'Cliente no sincronizado. Usa el checkout primero.',
            ], 422);
        }

        if (! $plan->recurrente_product_id) {
            return response()->json([
                'error' => 'Plan no sincronizado con Recurrente.',
            ], 422);
        }

        // ── FIX 3.3 — Bloquear suscripción duplicada ──────────────────────
        $existing = RecurrenteSubscription::where('client_id', $client->id)
            ->where('status', 'active')
            ->with('membershipPlan')
            ->first();

        if ($existing) {
            return response()->json([
                'error'           => 'El cliente ya tiene una suscripción activa.',
                'existing_plan'   => $existing->membershipPlan?->name,
                'subscription_id' => $existing->id,
                'cancel_first'    => true,
            ], 409);
        }

        $sub = $this->recurrente->createSubscription([
            'user_id'    => $client->recurrente_user_id,
            'product_id' => $plan->recurrente_product_id,
        ]);

        $record = RecurrenteSubscription::create([
            'client_id'                  => $client->id,
            'membership_plan_id'         => $plan->id,
            'recurrente_subscription_id' => $sub['id'],
            'recurrente_product_id'      => $plan->recurrente_product_id,
            'status'                     => $sub['status'] ?? 'active',
            'metadata'                   => $sub,
        ]);

        return response()->json([
            'subscription_id'            => $record->id,
            'recurrente_subscription_id' => $record->recurrente_subscription_id,
            'status'                     => $record->status,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────
    //  CASO 5 — Cancelar suscripción
    // ─────────────────────────────────────────────────────────────

    /**
     * Cancelar suscripción activa.
     * DELETE /api/suscripciones/{id}
     */
    public function cancelSubscription(int $id)
    {
        $sub = RecurrenteSubscription::findOrFail($id);

        try {
            $this->recurrente->cancelSubscription($sub->recurrente_subscription_id);
        } catch (\Exception $e) {
            // Si ya estaba cancelada en Recurrente, solo sincronizamos local
            Log::warning("[Recurrente] cancelSubscription aviso: " . $e->getMessage());
        }

        $sub->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Suscripción cancelada correctamente',
            'id'      => $sub->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  CONSULTAS
    // ─────────────────────────────────────────────────────────────

    /**
     * Historial de pagos de un cliente.
     * GET /api/pagos/historial/{clientId}
     */
    public function paymentHistory(int $clientId)
    {
        $payments = RecurrentePayment::where('client_id', $clientId)
            ->with('membershipPlan')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($p) => [
                ...$p->toArray(),
                'amount_gtq' => 'Q' . number_format($p->amount_in_cents / 100, 2),
            ]);

        $subscriptions = RecurrenteSubscription::where('client_id', $clientId)
            ->with('membershipPlan')
            ->get();

        return response()->json([
            'payments'      => $payments,
            'subscriptions' => $subscriptions,
        ]);
    }

    /**
     * Estado de pagos Recurrente de un cliente.
     * GET /api/pagos/estado/{clientId}
     */
    public function clientPaymentStatus(int $clientId)
    {
        $client = Client::findOrFail($clientId);

        return response()->json([
            'has_recurrente_account' => ! is_null($client->recurrente_user_id),
            'has_saved_card'         => ! is_null($client->recurrente_payment_method_id),
            'recurrente_user_id'     => $client->recurrente_user_id,
            'active_subscription'    => RecurrenteSubscription::where('client_id', $clientId)
                ->where('status', 'active')
                ->with('membershipPlan')
                ->first(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  CHECKOUT PÚBLICO (sin autenticación — auto-registro)
    // ─────────────────────────────────────────────────────────────

    /**
     * Crear checkout público para suscripción.
     * Crea o encuentra al cliente automáticamente por email,
     * luego genera el checkout de Recurrente.
     *
     * POST /api/public/checkout
     *
     * Request:  { name, email, phone, plan_id }
     * Response: { checkout_url, checkout_id, client_id }
     */
    public function publicCheckout(Request $request)
    {
        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'phone'   => 'nullable|string|max:50',
            'plan_id' => 'required|exists:membership_plans,id',
        ]);

        $plan = MembershipPlan::findOrFail($data['plan_id']);

        if (! $plan->recurrente_product_id) {
            return response()->json([
                'error' => 'Este plan aún no está disponible para pago en línea.',
            ], 422);
        }

        return DB::transaction(function () use ($data, $plan) {
            // Buscar o crear cliente por email
            $client = Client::where('email', $data['email'])->first();

            if (! $client) {
                $nameParts = explode(' ', trim($data['name']), 2);
                $client = Client::create([
                    'first_name' => $nameParts[0] ?: 'Cliente',
                    'last_name'  => $nameParts[1] ?? '-',
                    'email'  => $data['email'],
                    'phone'  => $data['phone'] ?? null,
                    'status' => 'active',
                    'qr_code' => 'GYM-' . strtoupper(Str::random(8)),
                ]);

                Log::info('[PublicCheckout] Cliente creado automáticamente', [
                    'client_id' => $client->id,
                    'email'     => $client->email,
                ]);
            }

            // Crear usuario en Recurrente si no existe
            if (! $client->recurrente_user_id) {
                $nameParts = explode(' ', trim($data['name']), 2);
                $userRes   = $this->recurrente->createUser([
                    'first_name' => $nameParts[0],
                    'last_name'  => $nameParts[1] ?? '-',
                    'email'      => $data['email'],
                    'phone'      => $data['phone'] ?? null,
                ]);
                $client->update(['recurrente_user_id' => $userRes['id']]);
            }

            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $successUrl  = "{$frontendUrl}/p/pago-exitoso?client_id={$client->id}&plan_id={$plan->id}";
            $cancelUrl   = "{$frontendUrl}/p/pago-fallido?plan_id={$plan->id}";

            $checkout = $this->recurrente->createCheckout([
                'user_id'     => $client->recurrente_user_id,
                'items'       => [[
                    'product_id' => $plan->recurrente_product_id,
                    'quantity'   => 1,
                ]],
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
            ]);

            RecurrentePayment::create([
                'client_id'              => $client->id,
                'membership_plan_id'     => $plan->id,
                'recurrente_checkout_id' => $checkout['id'] ?? null,
                'type'                   => 'checkout',
                'amount_in_cents'        => RecurrenteService::toCents((float) $plan->price),
                'currency'               => 'GTQ',
                'status'                 => 'pending',
                'concept'                => "Suscripción: {$plan->name}",
            ]);

            Log::info('[PublicCheckout] Checkout creado', [
                'client_id'   => $client->id,
                'plan_id'     => $plan->id,
                'checkout_id' => $checkout['id'] ?? null,
            ]);

            return response()->json([
                'checkout_url' => $checkout['checkout_url'] ?? $checkout['url'] ?? null,
                'checkout_id'  => $checkout['id'] ?? null,
                'client_id'    => $client->id,
            ]);
        });
    }
}
