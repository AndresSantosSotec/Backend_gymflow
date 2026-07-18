<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Client;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\PaymentInstallment;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
class MembershipController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Membership::with(['client', 'plan']);

        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $memberships = $query->orderBy('created_at', 'desc')->get();

        return response()->json($memberships);
    }

    /**
     * Memberships expiring soon or already expired — for Dashboard alert panel.
     * Query params:
     *   days   → look-ahead window in days (default 7)
     *   type   → 'expiring' | 'expired' | 'all' (default 'all')
     *   limit  → max records (default 20)
     */
    public function expiring(Request $request)
    {
        $days  = max(1, (int) $request->input('days', 7));
        $limit = min(100, max(1, (int) $request->input('limit', 20)));
        $type  = $request->input('type', 'all');
        $today = Carbon::today();

        $query = Membership::with(['client', 'plan'])
            ->whereIn('status', ['active', 'advance_active', 'advance_expiring']);

        if ($type === 'expiring') {
            // Vence entre hoy y +N días
            $query->whereBetween('end_date', [$today, $today->copy()->addDays($days)->endOfDay()]);
        } elseif ($type === 'expired') {
            // Ya venció
            $query->where('end_date', '<', $today);
        } else {
            // Ambos: ya vencidas O próximas a vencer
            $query->where(function ($q) use ($today, $days) {
                $q->where('end_date', '<', $today)
                  ->orWhereBetween('end_date', [$today, $today->copy()->addDays($days)->endOfDay()]);
            });
        }

        $memberships = $query->orderBy('end_date', 'asc')->limit($limit)->get();

        return response()->json($memberships->map(function (Membership $m) use ($today) {
            $endDate = $m->end_date;
            $daysLeft = $endDate ? (int) $today->diffInDays($endDate, false) : null;
            return [
                'id'          => $m->id,
                'client_id'   => $m->client_id,
                'status'      => $m->status,
                'end_date'    => $endDate?->toDateString(),
                'start_date'  => $m->start_date?->toDateString(),
                'days_left'   => $daysLeft,
                'is_expired'  => $daysLeft !== null && $daysLeft < 0,
                'plan'        => $m->plan ? ['id' => $m->plan->id, 'name' => $m->plan->name, 'plan_type' => $m->plan->plan_type ?? 'membership'] : null,
                'client'      => $m->client ? [
                    'id'              => $m->client->id,
                    'full_name'       => $m->client->full_name,
                    'first_name'      => $m->client->first_name,
                    'last_name'       => $m->client->last_name,
                    'phone'           => $m->client->phone,
                    'photo_public_path' => $m->client->photo_public_path,
                ] : null,
            ];
        }));
    }

    /**
     * Assign membership to client — supports single payment or installments
     */
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'client_id'       => 'required|exists:clients,id',
            'plan_id'         => 'required|exists:membership_plans,id',
            'payment_method'  => 'required|in:CASH,TRANSFER,STRIPE,CARD,cash,card,transfer,stripe',
            'amount'          => 'required|numeric|min:0',
            'reference'       => 'nullable|string',
            // Payment plan fields
            'payment_type'    => 'sometimes|in:single,installments',
            'num_installments'=> 'sometimes|integer|min:1|max:12',
            'initial_payment' => 'sometimes|numeric|min:0', // enganche
            'inscription_fee' => 'sometimes|numeric|min:0', // cuota 0 / inscripción
            'issue_fel'       => 'sometimes|boolean',
            // start_date define el ciclo independiente de este servicio.
            // Si se paga el día 15, start_date=15 → end_date=15+duration_days.
            // Esto permite cobros "desfasados": cada servicio tiene su propio vencimiento.
            'start_date'      => 'sometimes|date',
            'billing_day'     => 'sometimes|integer|min:1|max:28',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $plan = MembershipPlan::findOrFail($validated['plan_id']);

        $inscriptionFee = (float) ($validated['inscription_fee'] ?? 0);
        $paymentType = $validated['payment_type'] ?? 'single';
        $numInstallments = (int) ($validated['num_installments'] ?? ($paymentType === 'installments' ? 12 : 1));
        $numInstallments = max(1, min(12, $numInstallments));

        if ($paymentType === 'single') {
            $totalAmount = (float) $validated['amount'];
        } else {
            $totalAmount = ((float) $plan->price * $numInstallments) + $inscriptionFee;
        }

        // Calculate dates (optional start_date + billing_day for cuotas)
        $startDate = !empty($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->startOfDay()
            : Carbon::now();
        $endDate = $startDate->copy()->addDays($plan->duration_days);
        $billingDay = isset($validated['billing_day'])
            ? max(1, min(28, (int) $validated['billing_day']))
            : null;

        // Procesar documento de transferencia (base64) si existe
        $documentUrl = null;
        if ($request->filled('document_base64')) {
            $base64 = $request->document_base64;
            if (preg_match('/^data:(image\/\w+|application\/pdf);base64,/', $base64, $type)) {
                $data = substr($base64, strpos($base64, ',') + 1);
                $mime = strtolower($type[1]);
                $extension = str_replace('jpeg', 'jpg', explode('/', $mime)[1]);
                $decoded = base64_decode($data);
                $fileName = 'payments/docs/' . uniqid() . '.' . $extension;
                Storage::disk('public')->put($fileName, $decoded);
                $documentUrl = $fileName;
            }
        }

        // Todo debe ejecutarse de forma atómica. Si algo falla (ej. base de datos), se deshace.
        $result = DB::transaction(function () use ($validated, $client, $plan, $inscriptionFee, $paymentType, $numInstallments, $totalAmount, $startDate, $endDate, $request, $documentUrl) {
            // 1. Asignar membresía al cliente (relación principal)
            $membership = Membership::create([
                'client_id' => $client->id,
                'plan_id' => $plan->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'active',
                'auto_renew' => false,
                'total_amount' => $totalAmount,
                'payment_type' => $paymentType,
                'num_installments' => $paymentType === 'installments' ? $numInstallments : 1,
                'amount_paid' => 0,
                'payment_status' => 'pending',
            ]);

            $payment = null;

            if ($paymentType === 'single') {
                // ── Flujo de Pago Único ──
                $payment = Payment::create([
                    'client_id' => $client->id,
                    'membership_id' => $membership->id,
                    'amount' => $validated['amount'],
                    'payment_method' => strtolower($validated['payment_method']),
                    'status' => 'completed',
                    'transaction_id' => $request->input('reference'),
                    'document_url' => $documentUrl,
                    'paid_at' => now(),
                ]);

                $paidAmount = (float) $validated['amount'];

                // Cuota 0 (Inscripción)
                if ($inscriptionFee > 0) {
                    $inscriptionPaid = min($inscriptionFee, $paidAmount);
                    PaymentInstallment::create([
                        'membership_id' => $membership->id,
                        'client_id' => $client->id,
                        'installment_number' => 0,
                        'amount' => $inscriptionFee,
                        'amount_paid' => $inscriptionPaid,
                        'due_date' => $startDate,
                        'status' => $inscriptionPaid >= $inscriptionFee ? 'paid' : 'partial',
                        'payment_id' => $inscriptionPaid > 0 ? $payment->id : null,
                        'paid_at' => $inscriptionPaid > 0 ? now() : null,
                        'notes' => 'Cuota de Inscripción',
                    ]);
                    $paidAmount -= $inscriptionPaid;
                }

                PaymentInstallment::create([
                    'membership_id' => $membership->id,
                    'client_id' => $client->id,
                    'installment_number' => 1,
                    'amount' => (float) $plan->price,
                    'amount_paid' => $paidAmount,
                    'due_date' => $startDate,
                    'status' => $paidAmount >= (float) $plan->price ? 'paid' : 'partial',
                    'payment_id' => $paidAmount > 0 ? $payment->id : null,
                    'paid_at' => $paidAmount > 0 ? now() : null,
                ]);

                $membership->update([
                    'amount_paid' => $validated['amount'],
                    'payment_status' => $validated['amount'] >= $totalAmount ? 'paid' : 'partial',
                ]);

                try {
                    Receipt::createFromPaymentAuto($payment, 'subscription', $membership->id);
                } catch (\Exception $e) {
                    //\Log::warning('Auto-receipt failed for membership payment #' . $payment->id . ': ' . $e->getMessage());
                }
            } else {
                // ── 2. Crear plan de pago estructurado (12 meses por defecto) ──
                $initialPayment = (float) ($validated['initial_payment'] ?? $validated['amount'] ?? 0);

                $installmentAmount = (float) $plan->price;
                $adjustedLast = $installmentAmount;

                $firstPayment = null;
                $paidAmount = $initialPayment;

                // 4. Registrar pago inicial vinculado al plan (si existe)
                if ($initialPayment > 0) {
                    $firstPayment = Payment::create([
                        'client_id' => $client->id,
                        'membership_id' => $membership->id, // Este payment ya queda amarrado a la membresía
                        'amount' => $initialPayment,
                        'payment_method' => strtolower($validated['payment_method']),
                        'status' => 'completed',
                        'transaction_id' => $request->input('reference'),
                        'document_url' => $documentUrl,
                        'notes' => 'Enganche / Pago inicial / Inscripción',
                        'paid_at' => now(),
                    ]);
                    $payment = $firstPayment;
                }

                // 3. Crear cuota #0 de inscripción vinculada al plan generado
                if ($inscriptionFee > 0) {
                    $inscriptionPaid = min($inscriptionFee, $paidAmount);
                    PaymentInstallment::create([
                        'membership_id' => $membership->id,
                        'client_id' => $client->id,
                        'installment_number' => 0,
                        'amount' => $inscriptionFee,
                        'amount_paid' => $inscriptionPaid,
                        'due_date' => $startDate,
                        'status' => $inscriptionPaid >= $inscriptionFee ? 'paid' : 'partial',
                        // Esta Cuota #0 queda relacionada al Payment (firstPayment) registrado en caso de ser pagada.
                        'payment_id' => $inscriptionPaid > 0 ? $firstPayment?->id : null,
                        'paid_at' => $inscriptionPaid > 0 ? now() : null,
                        'notes' => 'Cuota de Inscripción',
                    ]);
                    $paidAmount -= $inscriptionPaid;
                }

                for ($i = 1; $i <= $numInstallments; $i++) {
                    $dueDate = $startDate->copy()->addMonths($i);
                    if ($billingDay !== null) {
                        $dueDate->day(min($billingDay, $dueDate->daysInMonth));
                    }
                    $amt = ($i === $numInstallments) ? $adjustedLast : $installmentAmount;

                    $instPaid = min($amt, $paidAmount);
                    $paidAmount -= $instPaid;

                    PaymentInstallment::create([
                        'membership_id' => $membership->id,
                        'client_id' => $client->id,
                        'installment_number' => $i,
                        'amount' => round($amt, 2),
                        'amount_paid' => round($instPaid, 2),
                        'due_date' => $dueDate,
                        'status' => $instPaid >= $amt ? 'paid' : ($instPaid > 0 ? 'partial' : 'pending'),
                        'payment_id' => $instPaid > 0 ? $firstPayment?->id : null,
                        'paid_at' => $instPaid > 0 ? now() : null,
                    ]);
                }

                $actualTotalAmount = ($installmentAmount * $numInstallments) + $inscriptionFee;

                $membership->update([
                    'amount_paid' => $initialPayment,
                    'payment_status' => $initialPayment >= $actualTotalAmount ? 'paid' : ($initialPayment > 0 ? 'partial' : 'pending'),
                ]);

                if ($firstPayment) {
                    try {
                        Receipt::createFromPaymentAuto($firstPayment, 'subscription', $membership->id);
                    } catch (\Exception $e) {
                        // \Log::warning('Auto-receipt failed for installment payment #' . $firstPayment->id . ': ' . $e->getMessage());
                    }
                }
            }

            // Activar al cliente final
            $client->update(['status' => 'active']);

            return [
                'membership' => $membership,
                'payment' => $payment,
                'message' => $paymentType === 'installments'
                    ? "Membresía asignada con plan de {$numInstallments} cuotas"
                    : 'Membresía asignada exitosamente',
            ];
        });

        // 2. Procesar FEL afuera de la transacción DB (Best Practice para llamadas HTTP)
        $felResult = null;
        if ($result['payment']) {
            $issueFel = $request->has('issue_fel') ? $request->boolean('issue_fel') : null;
            try {
                $felResult = app(\App\Services\FelPaymentService::class)->processAfterPayment($result['payment'], $issueFel);
            } catch (\Exception $e) {
                \Log::warning('Auto-certification on membership assign failed: ' . $e->getMessage());
                $felResult = ['success' => false, 'fel_status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'membership' => $result['membership']->load(['client', 'plan', 'installments']),
            'message' => $result['message'],
            'fel' => $felResult,
        ], 201);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'plan_id' => 'required|exists:membership_plans,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'status' => 'required|in:active,expired,cancelled',
            'auto_renew' => 'boolean',
        ]);

        $membership = Membership::create($validated);

        return response()->json($membership->load(['client', 'plan']), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $membership = Membership::with(['client', 'plan', 'payments'])->findOrFail($id);
        return response()->json($membership);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $membership = Membership::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|in:active,expired,cancelled',
            'auto_renew' => 'boolean',
            'end_date' => 'sometimes|date',
        ]);

        $membership->update($validated);

        return response()->json($membership->load(['client', 'plan']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $membership = Membership::findOrFail($id);
        $membership->delete();

        return response()->json(['message' => 'Membership deleted successfully']);
    }

    /**
     * Get memberships by client
     */
    public function byClient(string $clientId)
    {
        $memberships = Membership::with('plan')
            ->where('client_id', $clientId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($memberships);
    }

    /**
     * Retorna todos los servicios activos de un cliente agrupados por tipo de plan.
     *
     * Endpoint: GET /api/memberships/client/{clientId}/services
     *
     * Cada servicio tiene su propio ciclo de vencimiento independiente.
     * Un cliente puede tener, por ejemplo:
     *   - Mensualidad que vence el 1 del mes
     *   - Entrenamiento personalizado que vence el 15 del mes
     * Sin que ninguno afecte al otro.
     */
    public function clientServices(string $clientId)
    {
        // Traer TODAS las membresias del cliente, ordenadas por tipo y fecha de creación
        $memberships = Membership::with(['plan', 'installments'])
            ->where('client_id', $clientId)
            ->whereIn('status', [
                Membership::STATUS_ACTIVE,
                Membership::STATUS_ADVANCE_ACTIVE,
                Membership::STATUS_ADVANCE_EXPIRING,
                Membership::STATUS_AT_RISK,
                Membership::STATUS_PAUSED,
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        // Agrupar por plan_type para que el frontend pueda mostrar cada servicio
        // en su propia tarjeta con su fecha de vencimiento propia
        $grouped = $memberships->groupBy(function ($membership) {
            return $membership->plan->plan_type ?? 'membership';
        });

        // Construir respuesta estructurada por tipo de servicio
        $services = [];
        $planTypeLabels = \App\Models\MembershipPlan::TYPE_LABELS;

        foreach ($grouped as $planType => $membershipsOfType) {
            // La membresia activa más reciente de este tipo es la que manda
            $active = $membershipsOfType->first();

            $services[] = [
                'plan_type'       => $planType,
                'plan_type_label' => $planTypeLabels[$planType] ?? 'Servicio',
                'active_membership' => [
                    'id'             => $active->id,
                    'status'         => $active->status,
                    'start_date'     => $active->start_date?->toDateString(),
                    'end_date'       => $active->end_date?->toDateString(),
                    'advance_end_date' => $active->advance_end_date?->toDateString(),
                    'payment_status' => $active->payment_status,
                    'amount_paid'    => (float) $active->amount_paid,
                    'total_amount'   => (float) $active->total_amount,
                    'days_remaining' => $active->end_date
                        ? (int) now()->startOfDay()->diffInDays($active->end_date, false)
                        : null,
                    'plan'           => $active->plan ? [
                        'id'              => $active->plan->id,
                        'name'            => $active->plan->name,
                        'plan_type'       => $active->plan->plan_type ?? 'membership',
                        'plan_type_label' => $planTypeLabels[$active->plan->plan_type ?? 'membership'] ?? 'Mensualidad',
                        'price'           => (float) $active->plan->price,
                        'duration_days'   => $active->plan->duration_days,
                    ] : null,
                ],
                'history_count' => $membershipsOfType->count(),
            ];
        }

        return response()->json([
            'client_id' => $clientId,
            'services'  => $services,
            'total_active_services' => count($services),
        ]);
    }
}
