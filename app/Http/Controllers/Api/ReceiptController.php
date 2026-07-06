<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Models\Payment;
use App\Models\Client;
use App\Services\ReceiptPdfService;
use App\Services\FelPaymentService;
use App\Services\ElectronicBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ReceiptController extends Controller
{
    /**
     * Display a listing of receipts
     */
    public function index(Request $request)
    {
        $query = Receipt::with(['client', 'payment', 'membership.plan', 'venta.cliente']);

        // Filtros
        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('payment_id')) {
            $query->where('payment_id', $request->payment_id);
        }

        if ($request->has('venta_id')) {
            $query->where('venta_id', $request->venta_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_type')) {
            $query->where('payment_type', $request->payment_type);
        }

        if ($request->has('is_invoiced')) {
            $query->where('is_invoiced', $request->boolean('is_invoiced'));
        }

        if ($request->has('email_sent')) {
            $query->where('email_sent', $request->boolean('email_sent'));
        }

        // Búsqueda por número de recibo o factura
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%$search%")
                  ->orWhere('invoice_number', 'like', "%$search%");
            });
        }

        // Ordenamiento
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $receipts = $query->paginate($request->per_page ?? 15);

        return response()->json($receipts);
    }

    /**
     * Store a newly created receipt
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'payment_id' => 'nullable|exists:payments,id',
            'venta_id' => 'nullable|exists:ventas,id',
            'membership_id' => 'nullable|exists:memberships,id',
            'type' => 'required|in:receipt,invoice,proforma',
            'payment_type' => 'required|in:subscription,individual_payment,course,product',
            'subtotal' => 'required|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'details' => 'nullable|array',
            'status' => 'required|in:draft,pending,paid,cancelled',
        ]);

        // Generar número de recibo
        $validated['receipt_number'] = Receipt::generateReceiptNumber();

        $receipt = Receipt::create($validated);

        return response()->json($receipt->load(['client', 'payment', 'membership', 'venta.cliente']), 201);
    }

    /**
     * Display the specified receipt
     */
    public function show(string $id)
    {
        $receipt = Receipt::with(['client', 'payment', 'membership', 'venta.cliente'])->findOrFail($id);
        return response()->json($receipt);
    }

    /**
     * Update the specified receipt
     */
    public function update(Request $request, string $id)
    {
        $receipt = Receipt::findOrFail($id);

        $validated = $request->validate([
            'subtotal' => 'sometimes|numeric|min:0',
            'tax' => 'sometimes|numeric|min:0',
            'discount' => 'sometimes|numeric|min:0',
            'total' => 'sometimes|numeric|min:0',
            'description' => 'sometimes|nullable|string',
            'details' => 'sometimes|nullable|array',
            'status' => 'sometimes|in:draft,pending,paid,cancelled',
            'invoice_notes' => 'sometimes|nullable|string',
        ]);

        $receipt->update($validated);

        return response()->json($receipt->load(['client', 'payment', 'membership', 'venta.cliente']));
    }

    /**
     * Delete the specified receipt (soft delete).
     * Solo administradores (ROLES_MANAGE) pueden borrar recibos/facturas.
     * Returns 200 even if already deleted to avoid 404 on double-submit.
     */
    public function destroy(Request $request, string $id)
    {
        if (! $request->user()?->hasPermission('ROLES_MANAGE')) {
            return response()->json(['message' => 'No tienes permiso para eliminar recibos o facturas.'], 403);
        }

        $receipt = Receipt::withTrashed()->find($id);

        if (! $receipt) {
            return response()->json(['message' => 'Recibo no encontrado.'], 404);
        }

        if (! $receipt->trashed()) {
            $receipt->delete();
        }

        return response()->json(['message' => 'Recibo eliminado correctamente.']);
    }

    /**
     * Mark receipt as paid
     */
    public function markAsPaid(Request $request, string $id)
    {
        $receipt = Receipt::findOrFail($id);
        $receipt->markAsPaid();

        return response()->json([
            'message' => 'Receipt marked as paid',
            'receipt' => $receipt
        ]);
    }

    /**
     * Create invoice from receipt (facturación)
     */
    public function createInvoice(Request $request, string $id)
    {
        $receipt = Receipt::findOrFail($id);

        if ($receipt->is_invoiced) {
            return response()->json(['error' => 'Receipt is already invoiced'], 400);
        }

        $validated = $request->validate([
            'invoice_notes' => 'nullable|string',
            'certify_fel' => 'nullable|boolean',
        ]);

        $receipt->markAsInvoiced($validated['invoice_notes'] ?? null);

        $felResult = null;
        if ($request->boolean('certify_fel', config('billing.corpo_fel.enabled', false))) {
            try {
                $felResult = app(FelPaymentService::class)->certifyReceipt($receipt);
            } catch (\Exception $e) {
                $felResult = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Invoice created successfully',
            'receipt' => $receipt->fresh(),
            'invoice_number' => $receipt->invoice_number,
            'fel' => $felResult,
        ]);
    }

    /**
     * Send receipt/invoice by email
     */
    public function sendEmail(Request $request, string $id)
    {
        $receipt = Receipt::findOrFail($id);

        $validated = $request->validate([
            'email' => 'required|email',
            'message' => 'nullable|string',
        ]);

        try {
            // Aquí puedes integrar con tu servicio de email
            // Por ahora solo registramos el envío
            $receipt->markEmailSent($validated['email']);

            // TODO: Implementar envío real de email con PDF
            // Mail::send(new ReceiptMail($receipt));

            return response()->json([
                'message' => 'Email sent successfully',
                'receipt' => $receipt
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to send email',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get receipts by client
     */
    public function byClient(string $clientId)
    {
        $receipts = Receipt::where('client_id', $clientId)
            ->with(['payment', 'membership', 'venta.cliente'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($receipts);
    }

    /**
     * Get statistics/summaries
     */
    public function statistics(Request $request)
    {
        $stats = [
            'total_receipts' => Receipt::count(),
            'total_invoiced' => Receipt::where('is_invoiced', true)->count(),
            'total_paid' => Receipt::where('status', 'paid')->sum('total'),
            'total_pending' => Receipt::where('status', 'pending')->sum('total'),
            'email_sent' => Receipt::where('email_sent', true)->count(),
            'by_payment_type' => Receipt::groupBy('payment_type')
                ->selectRaw('payment_type, COUNT(*) as count, SUM(total) as total')
                ->get(),
            'by_status' => Receipt::groupBy('status')
                ->selectRaw('status, COUNT(*) as count, SUM(total) as total')
                ->get(),
        ];

        return response()->json($stats);
    }

    /**
     * Create receipt from payment
     */
    public function createFromPayment(Request $request)
    {
        $validated = $request->validate([
            'payment_id' => 'required|exists:payments,id',
            'type' => 'required|in:receipt,invoice,proforma',
            'payment_type' => 'required|in:subscription,individual_payment,course,product',
        ]);

        $payment = Payment::findOrFail($validated['payment_id']);
        $client = $payment->client;

        $receipt = Receipt::create([
            'client_id' => $client->id,
            'payment_id' => $payment->id,
            'membership_id' => $payment->membership_id,
            'receipt_number' => Receipt::generateReceiptNumber(),
            'type' => $validated['type'],
            'payment_type' => $validated['payment_type'],
            'subtotal' => $payment->amount,
            'tax' => 0,
            'discount' => 0,
            'total' => $payment->amount,
            'status' => $payment->status === 'completed' ? 'paid' : 'pending',
            'paid_at' => $payment->paid_at,
        ]);

        return response()->json($receipt->load(['client', 'payment', 'membership']), 201);
    }

    /**
     * Bulk export receipts
     */
    public function bulkExport(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:receipts,id'
        ]);

        $receipts = Receipt::whereIn('id', $validated['ids'])
            ->with(['client', 'payment', 'membership', 'venta.cliente'])
            ->get();

        // TODO: Implementar exportación a Excel/PDF

        return response()->json([
            'message' => 'Export prepared',
            'count' => $receipts->count(),
            'receipts' => $receipts
        ]);
    }

    /**
     * Download receipt as PDF
     */
    public function downloadReceiptPdf(string $id)
    {
        try {
            $receipt = Receipt::findOrFail($id);
            $pdfService = new ReceiptPdfService();

            return $pdfService->downloadReceiptPdf($receipt);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate PDF',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download invoice as PDF (factura electrónica)
     */
    public function downloadInvoicePdf(string $id)
    {
        try {
            $receipt = Receipt::findOrFail($id);

            if (!$receipt->is_invoiced) {
                return response()->json([
                    'error' => 'Receipt is not invoiced',
                    'message' => 'Este recibo no ha sido facturado aún',
                ], 422);
            }

            $pdfService = new ReceiptPdfService();

            return $pdfService->downloadInvoicePdf($receipt);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate invoice PDF',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate invoice from receipt and return PDF
     */
    public function generateAndDownloadInvoice(Request $request, string $id)
    {
        try {
            $receipt = Receipt::findOrFail($id);

            if ($receipt->is_invoiced) {
                return response()->json(['error' => 'Receipt is already invoiced'], 400);
            }

            $validated = $request->validate([
                'invoice_notes' => 'nullable|string',
            ]);

            // Marcar como facturado
            $receipt->markAsInvoiced($validated['invoice_notes'] ?? null);

            // Generar PDF
            $pdfService = new ReceiptPdfService();
            return $pdfService->downloadInvoicePdf($receipt);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate invoice',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send receipt PDF by email
     */
    public function emailReceiptPdf(Request $request, string $id)
    {
        try {
            $receipt = Receipt::findOrFail($id);

            $validated = $request->validate([
                'email' => 'required|email',
                'message' => 'nullable|string',
            ]);

            $pdfService = new ReceiptPdfService();
            $pdfService->emailReceiptPdf($receipt, $validated['email'], $validated['message'] ?? null);

            $receipt->markEmailSent($validated['email']);

            return response()->json([
                'message' => 'PDF sent successfully',
                'email' => $validated['email'],
                'receipt' => $receipt,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to send PDF',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download multiple receipts as PDFs
     */
    public function bulkDownloadReceipts(Request $request)
    {
        try {
            $validated = $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'integer|exists:receipts,id'
            ]);

            $pdfService = new ReceiptPdfService();
            $results = $pdfService->generateBulkPdfs($validated['ids']);

            return response()->json([
                'message' => 'Bulk PDF generation completed',
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate bulk PDFs',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View receipt as HTML (preview)
     */
    public function previewReceipt(string $id)
    {
        try {
            $receipt = Receipt::with(['client', 'payment', 'membership.plan', 'venta.cliente'])->findOrFail($id);

            $companyName = config('app.name', 'IronGym');
            $companyAddress = config('site.company_address', 'Dirección no configurada');
            $companyPhone = config('site.company_phone', '5868 7153');
            $companyEmail = config('site.company_email', 'email@irongym.local');

            return view('pdfs.receipt', [
                'receipt' => $receipt,
                'companyName' => $companyName,
                'companyAddress' => $companyAddress,
                'companyPhone' => $companyPhone,
                'companyEmail' => $companyEmail,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to preview receipt',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View invoice as HTML (preview)
     */
    public function previewInvoice(string $id)
    {
        try {
            $receipt = Receipt::with(['client', 'payment', 'membership.plan', 'venta.cliente'])->findOrFail($id);

            if (!$receipt->is_invoiced) {
                return response()->json([
                    'error' => 'Receipt is not invoiced',
                    'message' => 'Este recibo no ha sido facturado aún',
                ], 422);
            }

            $companyName = config('app.name', 'IronGym');
            $companyAddress = config('site.company_address', 'Dirección no configurada');
            $companyPhone = config('site.company_phone', '5868 7153');
            $companyEmail = config('site.company_email', 'email@irongym.local');
            $companyTax = config('site.company_tax_id', 'RFC/TAX no configurado');

            return view('pdfs.invoice', [
                'receipt' => $receipt,
                'companyName' => $companyName,
                'companyAddress' => $companyAddress,
                'companyPhone' => $companyPhone,
                'companyEmail' => $companyEmail,
                'companyTax' => $companyTax,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to preview invoice',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download thermal ticket PDF (80mm)
     */
    public function downloadTicket(string $id)
    {
        try {
            $receipt = Receipt::findOrFail($id);
            $pdfService = new ReceiptPdfService();

            return $pdfService->downloadTicketPdf($receipt);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate ticket',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get ticket HTML for direct browser printing
     */
    public function previewTicket(string $id)
    {
        try {
            $receipt = Receipt::findOrFail($id);
            $pdfService = new ReceiptPdfService();

            $html = $pdfService->getTicketHtml($receipt);

            return response($html)->header('Content-Type', 'text/html');
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to preview ticket',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate general receipts report PDF with filters
     */
    public function report(Request $request)
    {
        try {
            $query = Receipt::with(['client', 'payment', 'membership.plan', 'venta.cliente']);
            $appliedFilters = [];

            // Date range filter
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
                $appliedFilters['Desde'] = $request->date_from;
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
                $appliedFilters['Hasta'] = $request->date_to;
            }

            // Status filter
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
                $labels = ['paid' => 'Pagado', 'pending' => 'Pendiente', 'cancelled' => 'Cancelado', 'draft' => 'Borrador'];
                $appliedFilters['Estado'] = $labels[$request->status] ?? $request->status;
            }

            // Payment type filter
            if ($request->has('payment_type') && $request->payment_type) {
                $query->where('payment_type', $request->payment_type);
                $labels = ['subscription' => 'Membresia', 'individual_payment' => 'Pago individual', 'course' => 'Curso', 'product' => 'Producto'];
                $appliedFilters['Tipo'] = $labels[$request->payment_type] ?? $request->payment_type;
            }

            // Payment method filter
            if ($request->has('payment_method') && $request->payment_method) {
                $query->whereHas('payment', function ($q) use ($request) {
                    $q->where('payment_method', $request->payment_method);
                });
                $appliedFilters['Metodo'] = ucfirst($request->payment_method);
            }

            // Invoiced filter
            if ($request->has('is_invoiced')) {
                $query->where('is_invoiced', $request->boolean('is_invoiced'));
                $appliedFilters['Facturado'] = $request->boolean('is_invoiced') ? 'Si' : 'No';
            }

            $receipts = $query->orderBy('created_at', 'desc')->get();

            $pdfService = new ReceiptPdfService();

            return $pdfService->downloadReportPdf(
                $receipts,
                $request->date_from,
                $request->date_to,
                $appliedFilters
            );
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to generate report',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
