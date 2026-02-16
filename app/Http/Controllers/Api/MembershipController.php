<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Client;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\PaymentInstallment;
use Illuminate\Http\Request;
use Carbon\Carbon;

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
            'num_installments' => 'sometimes|integer|min:2|max:12',
            'initial_payment' => 'sometimes|numeric|min:0', // enganche
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $plan = MembershipPlan::findOrFail($validated['plan_id']);

        $totalAmount = (float) $plan->price;
        $paymentType = $validated['payment_type'] ?? 'single';
        $numInstallments = $validated['num_installments'] ?? 1;

        // Calculate dates
        $startDate = Carbon::now();
        $endDate = $startDate->copy()->addDays($plan->duration_days);

        // Create membership
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
            // ── Single payment (original flow) ──
            $payment = Payment::create([
                'client_id' => $client->id,
                'membership_id' => $membership->id,
                'amount' => $validated['amount'],
                'payment_method' => strtolower($validated['payment_method']),
                'status' => 'completed',
                'transaction_id' => $validated['reference'],
                'paid_at' => now(),
            ]);

            // Create a single installment record for consistency
            PaymentInstallment::create([
                'membership_id' => $membership->id,
                'client_id' => $client->id,
                'installment_number' => 1,
                'amount' => $totalAmount,
                'amount_paid' => $validated['amount'],
                'due_date' => $startDate,
                'status' => $validated['amount'] >= $totalAmount ? 'paid' : 'partial',
                'payment_id' => $payment->id,
                'paid_at' => now(),
            ]);

            $membership->update([
                'amount_paid' => $validated['amount'],
                'payment_status' => $validated['amount'] >= $totalAmount ? 'paid' : 'partial',
            ]);
        } else {
            // ── Installment-based payment plan ──
            $initialPayment = (float) ($validated['initial_payment'] ?? 0);
            $remainingAfterInitial = $totalAmount - $initialPayment;
            $installmentAmount = round($remainingAfterInitial / $numInstallments, 2);

            // Adjust last installment for rounding differences
            $adjustedLast = $remainingAfterInitial - ($installmentAmount * ($numInstallments - 1));

            $firstPayment = null;

            // Register initial payment (enganche) if any
            if ($initialPayment > 0) {
                $firstPayment = Payment::create([
                    'client_id' => $client->id,
                    'membership_id' => $membership->id,
                    'amount' => $initialPayment,
                    'payment_method' => strtolower($validated['payment_method']),
                    'status' => 'completed',
                    'transaction_id' => $validated['reference'],
                    'notes' => 'Enganche / Pago inicial',
                    'paid_at' => now(),
                ]);
            }

            // Generate installment schedule
            for ($i = 1; $i <= $numInstallments; $i++) {
                $dueDate = $startDate->copy()->addMonths($i);
                $amt = ($i === $numInstallments) ? $adjustedLast : $installmentAmount;

                PaymentInstallment::create([
                    'membership_id' => $membership->id,
                    'client_id' => $client->id,
                    'installment_number' => $i,
                    'amount' => round($amt, 2),
                    'amount_paid' => 0,
                    'due_date' => $dueDate,
                    'status' => 'pending',
                ]);
            }

            $membership->update([
                'amount_paid' => $initialPayment,
                'payment_status' => $initialPayment > 0 ? 'partial' : 'pending',
            ]);
        }

        // Activate client
        $client->update(['status' => 'active']);

        return response()->json([
            'membership' => $membership->load(['client', 'plan', 'installments']),
            'message' => $paymentType === 'installments'
                ? "Membresía asignada con plan de {$numInstallments} cuotas"
                : 'Membresía asignada exitosamente',
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
}
