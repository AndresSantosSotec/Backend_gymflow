<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Payment::with(['client', 'membership']);

        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('method')) {
            $query->where('payment_method', $request->method);
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15);

        return response()->json($payments);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'membership_id' => 'nullable|exists:memberships,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,card,transfer,stripe',
            'status' => 'required|in:pending,completed,failed,refunded',
            'transaction_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validated['status'] === 'completed') {
            $validated['paid_at'] = now();
        }

        $payment = Payment::create($validated);

        // Auto-generate receipt
        try {
            Receipt::createFromPaymentAuto($payment, 'individual_payment');
        } catch (\Exception $e) {
            \Log::warning('Auto-receipt generation failed for payment #' . $payment->id . ': ' . $e->getMessage());
        }

        return response()->json($payment->load(['client', 'membership']), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $payment = Payment::with(['client', 'membership'])->findOrFail($id);
        return response()->json($payment);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $payment = Payment::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|in:pending,completed,failed,refunded',
            'notes' => 'nullable|string',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'completed' && !$payment->paid_at) {
            $validated['paid_at'] = now();
        }

        $payment->update($validated);

        return response()->json($payment->load(['client', 'membership']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $payment = Payment::findOrFail($id);
        $payment->delete();

        return response()->json(['message' => 'Payment deleted successfully']);
    }

    /**
     * Get payments by client
     */
    public function byClient(string $clientId)
    {
        $payments = Payment::with('membership')
            ->where('client_id', $clientId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($payments);
    }

    /**
     * Get total revenue
     */
    public function revenue(Request $request)
    {
        $query = Payment::where('status', 'completed');

        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        $total = $query->sum('amount');

        return response()->json([
            'total_revenue' => $total,
            'count' => $query->count(),
        ]);
    }

    /**
     * Update payment status
     */
    public function updateStatus(Request $request, string $id)
    {
        $payment = Payment::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:pending,completed,failed,refunded',
        ]);

        if ($validated['status'] === 'completed' && !$payment->paid_at) {
            $payment->paid_at = now();
        }

        $payment->status = $validated['status'];
        $payment->save();

        return response()->json($payment);
    }
}
