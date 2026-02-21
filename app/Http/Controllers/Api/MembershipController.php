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
     * Assign membership to client — supports single payment or installments
     */
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'plan_id' => 'required|exists:membership_plans,id',
            'payment_method' => 'required|in:CASH,TRANSFER,STRIPE,CARD,cash,card,transfer,stripe',
            'amount' => 'required|numeric|min:0',
            'reference' => 'nullable|string',
            // Payment plan fields
            'payment_type' => 'sometimes|in:single,installments',
            'num_installments' => 'sometimes|integer|min:1|max:12',
            'initial_payment' => 'sometimes|numeric|min:0', // enganche
            'inscription_fee' => 'sometimes|numeric|min:0', // cuota 0 / inscripción
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $plan = MembershipPlan::findOrFail($validated['plan_id']);

        $inscriptionFee = (float) ($validated['inscription_fee'] ?? 0);
        $paymentType = 'installments'; // Forzar a cuotas
        $numInstallments = 12; // Forzar a 12 cuotas
        $totalAmount = ((float) $plan->price * $numInstallments) + $inscriptionFee;

        // Calculate dates
        $startDate = Carbon::now();
        $endDate = $startDate->copy()->addDays($plan->duration_days);

        // Todo debe ejecutarse de forma atómica. Si algo falla (ej. base de datos), se deshace.
        return DB::transaction(function () use ($validated, $client, $plan, $inscriptionFee, $paymentType, $numInstallments, $totalAmount, $startDate, $endDate, $request) {
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

            if ($paymentType === 'single') {
                // ── Flujo de Pago Único ──
                $payment = Payment::create([
                    'client_id' => $client->id,
                    'membership_id' => $membership->id,
                    'amount' => $validated['amount'],
                    'payment_method' => strtolower($validated['payment_method']),
                    'status' => 'completed',
                    'transaction_id' => $request->input('reference'),
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
                    \Log::warning('Auto-receipt failed for membership payment #' . $payment->id . ': ' . $e->getMessage());
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
                        'notes' => 'Enganche / Pago inicial / Inscripción',
                        'paid_at' => now(),
                    ]);
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
                        \Log::warning('Auto-receipt failed for installment payment #' . $firstPayment->id . ': ' . $e->getMessage());
                    }
                }
            }

            // Activar al cliente final
            $client->update(['status' => 'active']);

            return response()->json([
                'membership' => $membership->load(['client', 'plan', 'installments']),
                'message' => $paymentType === 'installments'
                    ? "Membresía asignada con plan de {$numInstallments} cuotas"
                    : 'Membresía asignada exitosamente',
            ], 201);
        });
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
}
