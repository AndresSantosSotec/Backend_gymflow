<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Filtro por día: date=YYYY-MM-DD (pagos de ese día por paid_at o created_at)
        if ($request->filled('date')) {
            $query->where(function ($q) use ($request) {
                $q->whereDate('paid_at', $request->date)
                    ->orWhere(function ($q2) use ($request) {
                        $q2->whereNull('paid_at')->whereDate('created_at', $request->date);
                    });
            });
        }
        // Rango: date_from / date_to
        if ($request->filled('date_from')) {
            $query->where(function ($q) use ($request) {
                $q->where('paid_at', '>=', $request->date_from . ' 00:00:00')
                    ->orWhere(function ($q2) use ($request) {
                        $q2->whereNull('paid_at')->where('created_at', '>=', $request->date_from . ' 00:00:00');
                    });
            });
        }
        if ($request->filled('date_to')) {
            $query->where(function ($q) use ($request) {
                $q->where('paid_at', '<=', $request->date_to . ' 23:59:59')
                    ->orWhere(function ($q2) use ($request) {
                        $q2->whereNull('paid_at')->where('created_at', '<=', $request->date_to . ' 23:59:59');
                    });
            });
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
            //\Log::warning('Auto-receipt generation failed for payment #' . $payment->id . ': ' . $e->getMessage());
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
     * Eliminar un pago y los recibos/facturas asociados.
     * Solo administradores (ROLES_MANAGE) pueden borrar pagos.
     */
    public function destroy(Request $request, string $id)
    {
        if (! $request->user()?->hasPermission('ROLES_MANAGE')) {
            return response()->json(['message' => 'No tienes permiso para eliminar pagos.'], 403);
        }

        $payment = Payment::findOrFail($id);

        DB::transaction(function () use ($payment) {
            // Borrar (soft delete) todos los recibos/facturas vinculados a este pago
            Receipt::where('payment_id', $payment->id)->delete();
            $payment->delete();
        });

        return response()->json([
            'message' => 'Pago y recibos/facturas asociados eliminados correctamente.',
        ]);
    }

    /**
     * Get payments by client
     */
    public function byClient(string $clientId)
    {
        $payments = Payment::with(['membership', 'membership.plan'])
            ->where('client_id', $clientId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($payments);
    }

    /**
     * Get total revenue (optional from/to; filtra por paid_at o created_at).
     */
    public function revenue(Request $request)
    {
        $query = Payment::where('status', 'completed');

        if ($request->filled('from')) {
            $from = $request->from . (strlen((string) $request->from) <= 10 ? ' 00:00:00' : '');
            $query->where(function ($q) use ($from) {
                $q->where('paid_at', '>=', $from)
                    ->orWhere(function ($q2) use ($from) {
                        $q2->whereNull('paid_at')->where('created_at', '>=', $from);
                    });
            });
        }
        if ($request->filled('to')) {
            $to = $request->to . (strlen((string) $request->to) <= 10 ? ' 23:59:59' : '');
            $query->where(function ($q) use ($to) {
                $q->where('paid_at', '<=', $to)
                    ->orWhere(function ($q2) use ($to) {
                        $q2->whereNull('paid_at')->where('created_at', '<=', $to);
                    });
            });
        }

        return response()->json([
            'total_revenue' => round((float) $query->sum('amount'), 2),
            'count' => $query->count(),
        ]);
    }

    /**
     * Corte de caja: pagos en un rango de fechas con totales por método y total general (JSON).
     */
    public function corteCaja(Request $request)
    {
        $from = $request->input('from', now()->format('Y-m-d'));
        $to = $request->input('to', $from);
        $from = strlen((string) $from) <= 10 ? $from . ' 00:00:00' : $from;
        $to = strlen((string) $to) <= 10 ? $to . ' 23:59:59' : $to;

        $query = Payment::with(['client'])
            ->where('status', 'completed')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('paid_at', [$from, $to])
                    ->orWhere(function ($q2) use ($from, $to) {
                        $q2->whereNull('paid_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->orderBy('paid_at')
            ->orderBy('id');

        $payments = $query->get();
        $totalRevenue = round((float) $payments->sum('amount'), 2);
        $byMethod = $payments->groupBy('payment_method')->map(fn ($group) => round((float) $group->sum('amount'), 2))->all();

        return response()->json([
            'from' => substr($from, 0, 10),
            'to' => substr($to, 0, 10),
            'payments' => $payments,
            'total_revenue' => $totalRevenue,
            'count' => $payments->count(),
            'by_method' => $byMethod,
        ]);
    }

    /**
     * Descargar reporte de corte de caja en PDF.
     */
    public function corteCajaPdf(Request $request)
    {
        $from = $request->input('from', now()->format('Y-m-d'));
        $to = $request->input('to', $from);
        $fromStr = strlen((string) $from) <= 10 ? $from . ' 00:00:00' : $from;
        $toStr = strlen((string) $to) <= 10 ? $to . ' 23:59:59' : $to;

        $payments = Payment::with(['client'])
            ->where('status', 'completed')
            ->where(function ($q) use ($fromStr, $toStr) {
                $q->whereBetween('paid_at', [$fromStr, $toStr])
                    ->orWhere(function ($q2) use ($fromStr, $toStr) {
                        $q2->whereNull('paid_at')
                            ->whereBetween('created_at', [$fromStr, $toStr]);
                    });
            })
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        $totalRevenue = round((float) $payments->sum('amount'), 2);
        $byMethod = $payments->groupBy('payment_method')->map(fn ($group) => round((float) $group->sum('amount'), 2))->all();

        $companyName = config('app.name', 'IronGym');
        $companyAddress = config('site.company_address', 'Guatemala, Guatemala');
        $companyPhone = config('site.company_phone', '(502) 0000-0000');
        $companyEmail = config('site.company_email', 'info@irongym.gt');

        $pdf = Pdf::loadView('pdfs.corte_caja', [
            'payments' => $payments,
            'from' => substr($from, 0, 10),
            'to' => substr($to, 0, 10),
            'total_revenue' => $totalRevenue,
            'by_method' => $byMethod,
            'companyName' => $companyName,
            'companyAddress' => $companyAddress,
            'companyPhone' => $companyPhone,
            'companyEmail' => $companyEmail,
        ])
            ->setPaper('letter')
            ->setOption('defaultFont', 'DejaVu Sans');

        $filename = 'corte-caja-' . substr($from, 0, 10) . (substr($from, 0, 10) !== substr($to, 0, 10) ? '_a_' . substr($to, 0, 10) : '') . '.pdf';

        return $pdf->download($filename);
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
