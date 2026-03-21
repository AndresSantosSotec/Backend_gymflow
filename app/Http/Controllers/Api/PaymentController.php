<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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

        // Filtro por día: date=YYYY-MM-DD — día en zona Guatemala para que coincida con lo que muestra el frontend (es-GT)
        $tz = 'America/Guatemala';
        if ($request->filled('date')) {
            $dayStart = Carbon::parse($request->date, $tz)->startOfDay()->utc();
            $dayEnd = Carbon::parse($request->date, $tz)->endOfDay()->utc();
            $query->where(function ($q) use ($dayStart, $dayEnd) {
                $q->whereBetween('paid_at', [$dayStart, $dayEnd])
                    ->orWhere(function ($q2) use ($dayStart, $dayEnd) {
                        $q2->whereNull('paid_at')->whereBetween('created_at', [$dayStart, $dayEnd]);
                    });
            });
        }
        // Rango: date_from / date_to (misma zona para consistencia)
        if ($request->filled('date_from')) {
            $fromStart = Carbon::parse($request->date_from, $tz)->startOfDay()->utc();
            $query->where(function ($q) use ($fromStart) {
                $q->where('paid_at', '>=', $fromStart)
                    ->orWhere(function ($q2) use ($fromStart) {
                        $q2->whereNull('paid_at')->where('created_at', '>=', $fromStart);
                    });
            });
        }
        if ($request->filled('date_to')) {
            $toEnd = Carbon::parse($request->date_to, $tz)->endOfDay()->utc();
            $query->where(function ($q) use ($toEnd) {
                $q->where('paid_at', '<=', $toEnd)
                    ->orWhere(function ($q2) use ($toEnd) {
                        $q2->whereNull('paid_at')->where('created_at', '<=', $toEnd);
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
     * Query de pagos para corte de caja: mismos criterios que el listado (fecha Guatemala + método opcional).
     */
    private function queryPaymentsForCorteCaja(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $from = $request->input('from', now()->format('Y-m-d'));
        $to = $request->input('to', $from);
        $tz = 'America/Guatemala';
        $fromStart = Carbon::parse($from, $tz)->startOfDay()->utc();
        $toEnd = Carbon::parse($to, $tz)->endOfDay()->utc();

        $query = Payment::with(['client'])
            ->where('status', 'completed')
            ->where(function ($q) use ($fromStart, $toEnd) {
                $q->whereBetween('paid_at', [$fromStart, $toEnd])
                    ->orWhere(function ($q2) use ($fromStart, $toEnd) {
                        $q2->whereNull('paid_at')
                            ->whereBetween('created_at', [$fromStart, $toEnd]);
                    });
            })
            ->orderBy('paid_at')
            ->orderBy('id');

        if ($request->filled('method')) {
            $query->where('payment_method', $request->method);
        }

        return $query;
    }

    /**
     * Corte de caja: pagos en un rango de fechas con totales por método y total general (JSON).
     * Usa los mismos filtros que el listado (fecha en Guatemala + método opcional).
     */
    public function corteCaja(Request $request)
    {
        $payments = $this->queryPaymentsForCorteCaja($request)->get();
        $totalRevenue = round((float) $payments->sum('amount'), 2);
        $byMethod = $payments->groupBy('payment_method')->map(fn ($group) => round((float) $group->sum('amount'), 2))->all();

        $from = $request->input('from', now()->format('Y-m-d'));
        $to = $request->input('to', $from);

        return response()->json([
            'from' => substr((string) $from, 0, 10),
            'to' => substr((string) $to, 0, 10),
            'payments' => $payments,
            'total_revenue' => $totalRevenue,
            'count' => $payments->count(),
            'by_method' => $byMethod,
        ]);
    }

    /**
     * Descargar reporte de corte de caja en PDF.
     * Mismos filtros que el listado (fecha Guatemala + método opcional).
     */
    public function corteCajaPdf(Request $request)
    {
        $payments = $this->queryPaymentsForCorteCaja($request)->get();
        $totalRevenue = round((float) $payments->sum('amount'), 2);
        $byMethod = $payments->groupBy('payment_method')->map(fn ($group) => round((float) $group->sum('amount'), 2))->all();

        $from = $request->input('from', now()->format('Y-m-d'));
        $to = $request->input('to', $from);

        $companyName = config('app.name', 'IronGym');
        $companyAddress = config('site.company_address', 'Guatemala, Guatemala');
        $companyPhone = config('site.company_phone', '5868 7153');
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
     * Descargar reporte de corte de caja en Excel.
     * Mismos filtros que el listado (fecha Guatemala + método opcional).
     */
    public function corteCajaExcel(Request $request)
    {
        $payments = $this->queryPaymentsForCorteCaja($request)->get();
        $totalRevenue = round((float) $payments->sum('amount'), 2);
        $byMethod = $payments->groupBy('payment_method')->map(fn ($group) => round((float) $group->sum('amount'), 2))->all();

        $from = $request->input('from', now()->format('Y-m-d'));
        $to = $request->input('to', $from);

        $methodLabels = [
            'cash' => 'Efectivo',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            'stripe' => 'Stripe',
            'recurrente' => 'Recurrente',
        ];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $companyName = config('app.name', 'IronGym');
        $periodLabel = $from === $to
            ? \Carbon\Carbon::parse($from)->format('d/m/Y')
            : \Carbon\Carbon::parse($from)->format('d/m/Y') . ' - ' . \Carbon\Carbon::parse($to)->format('d/m/Y');

        $sheet->setCellValue('A1', $companyName);
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Corte de Caja - ' . $periodLabel);
        $sheet->mergeCells('A2:F2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
        $sheet->setCellValue('A3', 'Generado: ' . now()->format('d/m/Y H:i'));
        $sheet->mergeCells('A3:F3');
        $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true);

        $headers = ['#', 'Fecha', 'Cliente', 'Método', 'Monto (Q)', 'Referencia / Notas'];
        $headerRow = 5;
        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . $headerRow, $h);
        }
        $sheet->getStyle("A{$headerRow}:F{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4A4A4A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $row = $headerRow + 1;
        $num = 1;
        foreach ($payments as $p) {
            $clientName = $p->client
                ? trim(($p->client->first_name ?? '') . ' ' . ($p->client->last_name ?? ''))
                : '-';
            $fecha = $p->paid_at
                ? $p->paid_at->format('d/m/Y H:i')
                : ($p->created_at ? $p->created_at->format('d/m/Y H:i') : '-');
            $metodo = $methodLabels[$p->payment_method] ?? $p->payment_method;
            $ref = trim(($p->transaction_id ?: '') . ' ' . ($p->notes ?: '')) ?: '-';

            $sheet->setCellValue('A' . $row, $num);
            $sheet->setCellValue('B' . $row, $fecha);
            $sheet->setCellValue('C' . $row, $clientName);
            $sheet->setCellValue('D' . $row, $metodo);
            $sheet->setCellValue('E' . $row, (float) $p->amount);
            $sheet->setCellValue('F' . $row, $ref);
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
            $num++;
        }

        $dataEnd = $row - 1;
        if ($dataEnd >= $headerRow + 1) {
            $sheet->getStyle('A' . ($headerRow + 1) . ':F' . $dataEnd)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
                'font' => ['size' => 9],
            ]);
        }

        $row++;
        foreach ($byMethod as $method => $total) {
            $sheet->setCellValue('D' . $row, ($methodLabels[$method] ?? $method) . ':');
            $sheet->setCellValue('E' . $row, $total);
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("D{$row}:E{$row}")->getFont()->setBold(true);
            $row++;
        }
        $sheet->setCellValue('D' . $row, 'TOTAL INGRESOS');
        $sheet->setCellValue('E' . $row, $totalRevenue);
        $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("D{$row}:E{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $row++;

        foreach (range('A', 'F') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'corte-caja-' . substr($from, 0, 10) . (substr($from, 0, 10) !== substr($to, 0, 10) ? '_a_' . substr($to, 0, 10) : '') . '.xlsx';
        $tempPath = storage_path('app/' . $filename);
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
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
