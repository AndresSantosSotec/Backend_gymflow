<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentInstallment;
use App\Models\Payment;
use App\Models\Membership;
use App\Models\Receipt;
use Illuminate\Http\Request;

class PaymentInstallmentController extends Controller
{
    /**
     * List all installments (optionally filtered)
     */
    public function index(Request $request)
    {
        $query = PaymentInstallment::with(['membership.plan', 'client', 'payment']);

        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('membership_id')) {
            $query->where('membership_id', $request->membership_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter overdue
        if ($request->boolean('overdue')) {
            $query->where('status', '!=', 'paid')
                ->where('due_date', '<', now()->startOfDay());
        }

        // Filter upcoming (next 7 days)
        if ($request->boolean('upcoming')) {
            $query->where('status', '!=', 'paid')
                ->where('due_date', '>=', now()->startOfDay())
                ->where('due_date', '<=', now()->addDays(7)->endOfDay());
        }

        $installments = $query->orderBy('due_date', 'asc')->get();

        return response()->json($installments);
    }

    /**
     * Show installment details
     */
    public function show(string $id)
    {
        $installment = PaymentInstallment::with(['membership.plan', 'client', 'payment'])->findOrFail($id);
        return response()->json($installment);
    }

    /**
     * Pay an installment (full or partial)
     */
    public function pay(Request $request, string $id)
    {
        $installment = PaymentInstallment::with('membership')->findOrFail($id);

        if ($installment->status === 'paid') {
            return response()->json(['message' => 'Esta cuota ya fue pagada'], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,transfer,stripe',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'document_base64' => 'nullable|string',
        ]);

        $remaining = (float) $installment->amount - (float) $installment->amount_paid;

        $documentUrl = null;
        if ($request->filled('document_base64')) {
            $base64 = $request->document_base64;
            if (preg_match('/^data:(image\/\w+|application\/pdf);base64,/', $base64, $type)) {
                $data = substr($base64, strpos($base64, ',') + 1);
                $mime = strtolower($type[1]);
                $extension = explode('/', $mime)[1];
                $data = base64_decode($data);
                $fileName = 'payments/docs/' . uniqid() . '.' . $extension;
                \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $data);
                $documentUrl = $fileName;
            }
        }

        if ($validated['amount'] > $remaining + 0.01) {
            return response()->json([
                'message' => "El monto excede el saldo pendiente de Q{$remaining}",
            ], 422);
        }

        $payAmount = min($validated['amount'], $remaining);

        // Create a payment record
        $payment = Payment::create([
            'client_id' => $installment->client_id,
            'membership_id' => $installment->membership_id,
            'amount' => $payAmount,
            'payment_method' => $validated['payment_method'],
            'status' => 'completed',
            'transaction_id' => $request->input('reference'),
            'document_url' => $documentUrl,
            'notes' => ($request->input('notes') ?? '') . " [Cuota #{$installment->installment_number}]",
            'paid_at' => now(),
        ]);

        // Update installment
        $installment->amount_paid = (float) $installment->amount_paid + $payAmount;
        $installment->payment_id = $payment->id;

        if ($installment->amount_paid >= (float) $installment->amount) {
            $installment->status = 'paid';
            $installment->paid_at = now();
        } else {
            $installment->status = 'partial';
        }

        $installment->save();

        // Recalculate membership payment status
        $installment->membership->recalculatePaymentStatus();

        // Auto-generate receipt for installment payment
        try {
            Receipt::createFromPaymentAuto($payment, 'subscription', $installment->membership_id);
        } catch (\Exception $e) {
            //\Log::warning('Auto-receipt failed for installment payment #' . $payment->id . ': ' . $e->getMessage());
        }

        return response()->json([
            'installment' => $installment->fresh()->load(['membership.plan', 'client', 'payment']),
            'payment' => $payment,
            'message' => $installment->status === 'paid'
                ? "Cuota #{$installment->installment_number} pagada completamente"
                : "Abono de Q" . number_format($payAmount, 2) . " registrado",
        ]);
    }

    /**
     * Get payment plan for a membership
     */
    public function byMembership(string $membershipId)
    {
        $membership = Membership::with(['plan', 'client', 'installments.payment'])
            ->findOrFail($membershipId);

        return response()->json([
            'membership' => $membership,
            'installments' => $membership->installments,
            'summary' => [
                'total' => (float) $membership->total_amount,
                'paid' => (float) $membership->amount_paid,
                'balance' => $membership->balance,
                'payment_type' => $membership->payment_type,
                'num_installments' => $membership->num_installments,
                'status' => $membership->payment_status,
                'installments_paid' => $membership->installments()->where('status', 'paid')->count(),
                'installments_pending' => $membership->installments()->where('status', '!=', 'paid')->count(),
            ],
        ]);
    }

    /**
     * Dashboard summary of pending installments
     */
    public function summary()
    {
        $today = now()->startOfDay();

        $overdue = PaymentInstallment::where('status', '!=', 'paid')
            ->where('due_date', '<', $today)
            ->count();

        $overdueAmount = PaymentInstallment::where('status', '!=', 'paid')
            ->where('due_date', '<', $today)
            ->selectRaw('SUM(amount - amount_paid) as total')
            ->value('total') ?? 0;

        $dueSoon = PaymentInstallment::where('status', '!=', 'paid')
            ->whereBetween('due_date', [$today, $today->copy()->addDays(7)])
            ->count();

        $dueSoonAmount = PaymentInstallment::where('status', '!=', 'paid')
            ->whereBetween('due_date', [$today, $today->copy()->addDays(7)])
            ->selectRaw('SUM(amount - amount_paid) as total')
            ->value('total') ?? 0;

        $totalPending = PaymentInstallment::where('status', '!=', 'paid')
            ->selectRaw('SUM(amount - amount_paid) as total')
            ->value('total') ?? 0;

        $totalPendingCount = PaymentInstallment::where('status', '!=', 'paid')->count();

        return response()->json([
            'overdue_count' => $overdue,
            'overdue_amount' => round((float) $overdueAmount, 2),
            'due_soon_count' => $dueSoon,
            'due_soon_amount' => round((float) $dueSoonAmount, 2),
            'total_pending_count' => $totalPendingCount,
            'total_pending_amount' => round((float) $totalPending, 2),
        ]);
    }
}
