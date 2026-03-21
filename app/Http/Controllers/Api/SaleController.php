<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Models\PagoVenta;
use App\Models\Producto;
use App\Models\MovimientoInventario;
use App\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SaleController extends Controller
{
    private const CASH_CUT_TZ = 'America/Guatemala';

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Venta::with(['cliente', 'detalles.producto', 'pagos.metodoPago', 'receipt']);

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        return response()->json($query->latest()->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'cliente_venta_id' => 'nullable|exists:cliente_ventas,id',
            'estado' => 'required|in:PAGADA,PENDIENTE,COTIZACION',
            'detalles' => 'required|array|min:1',
            'detalles.*.producto_id' => 'required|exists:productos,id',
            'detalles.*.cantidad' => 'required|integer|min:1',
            'pagos' => 'nullable|array',
            'pagos.*.metodo_pago_id' => 'required|exists:metodo_pagos,id',
            'pagos.*.monto' => 'required|numeric|min:0',
        ]);

        try {
            $result = DB::transaction(function () use ($validated) {
                $total = 0;
                // Pre-calculo del total
                foreach ($validated['detalles'] as $item) {
                    $producto = Producto::find($item['producto_id']);
                    $total += $producto->precio_venta * $item['cantidad'];
                }

                $venta = Venta::create([
                    'cliente_venta_id' => $validated['cliente_venta_id'],
                    'total' => $total,
                    'estado' => $validated['estado'],
                ]);

                foreach ($validated['detalles'] as $item) {
                    $producto = Producto::lockForUpdate()->find($item['producto_id']);
                    $subtotal = $producto->precio_venta * $item['cantidad'];

                    VentaDetalle::create([
                        'venta_id' => $venta->id,
                        'producto_id' => $item['producto_id'],
                        'cantidad' => $item['cantidad'],
                        'precio_unitario' => $producto->precio_venta,
                        'subtotal' => $subtotal,
                    ]);

                    // Solo afecta inventario si NO es cotización
                    if ($venta->estado !== 'COTIZACION') {
                        if ($producto->stock < $item['cantidad']) {
                            throw new \Exception("Stock insuficiente para: {$producto->nombre}");
                        }
                        $producto->stock -= $item['cantidad'];
                        $producto->save();

                        MovimientoInventario::create([
                            'producto_id' => $producto->id,
                            'tipo' => 'EGRESO',
                            'cantidad' => $item['cantidad'],
                            'motivo' => "Venta #{$venta->id}",
                            'referencia_id' => $venta->id,
                        ]);
                    }
                }

                if (isset($validated['pagos']) && $venta->estado !== 'COTIZACION') {
                    foreach ($validated['pagos'] as $pago) {
                        PagoVenta::create([
                            'venta_id' => $venta->id,
                            'metodo_pago_id' => $pago['metodo_pago_id'],
                            'monto' => $pago['monto'],
                        ]);
                    }
                }

                if ($venta->estado !== 'COTIZACION') {
                    try {
                        Receipt::createFromVentaAuto($venta);
                    } catch (\Exception $e) {
                        Log::warning('Auto-receipt failed for sale #' . $venta->id . ': ' . $e->getMessage());
                    }
                }

                return $venta->load(['cliente', 'detalles.producto', 'pagos.metodoPago', 'receipt']);
            });

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Venta $venta)
    {
        return response()->json($venta->load(['cliente', 'detalles.producto', 'pagos.metodoPago', 'receipt']));
    }

    /**
     * Convertir cotización a venta (Simple impl)
     */
    public function update(Request $request, Venta $venta)
    {
        if ($venta->estado === 'COTIZACION' && $request->estado === 'PAGADA') {
            try {
                $result = DB::transaction(function () use ($venta, $request) {
                    $venta->load('detalles.producto');

                    foreach ($venta->detalles as $detalle) {
                        $producto = Producto::lockForUpdate()->find($detalle->producto_id);

                        if ($producto->stock < $detalle->cantidad) {
                            throw new \Exception("Stock insuficiente para: {$producto->nombre} (Actual: {$producto->stock})");
                        }

                        $producto->stock -= $detalle->cantidad;
                        $producto->save();

                        MovimientoInventario::create([
                            'producto_id' => $producto->id,
                            'tipo' => 'EGRESO',
                            'cantidad' => $detalle->cantidad,
                            'motivo' => "Conversión Cotización #{$venta->id} a Venta",
                            'referencia_id' => $venta->id,
                        ]);
                    }

                    // Registrar pagos si vienen en la petición
                    if ($request->has('pagos')) {
                        foreach ($request->pagos as $pago) {
                            PagoVenta::create([
                                'venta_id' => $venta->id,
                                'metodo_pago_id' => $pago['metodo_pago_id'],
                                'monto' => $pago['monto'],
                            ]);
                        }
                    }

                    $venta->estado = 'PAGADA';
                    $venta->save();

                    try {
                        Receipt::createFromVentaAuto($venta);
                    } catch (\Exception $e) {
                        Log::warning('Auto-receipt failed for sale #' . $venta->id . ': ' . $e->getMessage());
                    }

                    return $venta->load(['cliente', 'detalles.producto', 'pagos.metodoPago', 'receipt']);
                });

                return response()->json($result);
            } catch (\Exception $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json($venta->load(['cliente', 'detalles.producto', 'pagos.metodoPago', 'receipt']));
    }

    private function querySalesForCashCut(Request $request): Builder
    {
        $from = $request->input('from', now()->format('Y-m-d'));
        $to = $request->input('to', $from);

        $fromStart = Carbon::parse($from, self::CASH_CUT_TZ)->startOfDay()->utc();
        $toEnd = Carbon::parse($to, self::CASH_CUT_TZ)->endOfDay()->utc();

        $query = Venta::with(['cliente', 'detalles.producto', 'pagos.metodoPago', 'receipt'])
            ->where('estado', 'PAGADA')
            ->whereBetween('created_at', [$fromStart, $toEnd])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($request->filled('method')) {
            $query->whereHas('pagos.metodoPago', function ($paymentQuery) use ($request) {
                $paymentQuery->where('nombre', $request->method);
            });
        }

        return $query;
    }

    private function buildCashCutPayload(Request $request): array
    {
        $sales = $this->querySalesForCashCut($request)->get();

        $paymentRows = $sales->flatMap(function (Venta $sale) {
            if ($sale->pagos->isEmpty()) {
                return [[
                    'method' => 'SIN_METODO',
                    'amount' => (float) $sale->total,
                ]];
            }

            return $sale->pagos->map(function (PagoVenta $payment) {
                return [
                    'method' => $payment->metodoPago->nombre ?? 'SIN_METODO',
                    'amount' => (float) $payment->monto,
                ];
            })->values()->all();
        });

        $byMethod = $paymentRows
            ->groupBy('method')
            ->map(fn ($group, $method) => [
                'method' => $method,
                'amount' => round((float) collect($group)->sum('amount'), 2),
            ])
            ->values();

        $dailyTotals = $sales
            ->groupBy(function (Venta $sale) {
                return Carbon::parse($sale->created_at)->timezone(self::CASH_CUT_TZ)->format('Y-m-d');
            })
            ->map(fn ($group, $date) => [
                'date' => $date,
                'amount' => round((float) $group->sum('total'), 2),
                'count' => $group->count(),
            ])
            ->values();

        $topProducts = $sales
            ->flatMap(function (Venta $sale) {
                return $sale->detalles->map(function ($detail) {
                    return [
                        'product_id' => $detail->producto_id,
                        'name' => $detail->producto->nombre ?? 'Producto eliminado',
                        'quantity' => (int) $detail->cantidad,
                        'amount' => (float) $detail->subtotal,
                    ];
                });
            })
            ->groupBy('product_id')
            ->map(function ($group) {
                return [
                    'product_id' => $group->first()['product_id'],
                    'name' => $group->first()['name'],
                    'quantity' => (int) collect($group)->sum('quantity'),
                    'amount' => round((float) collect($group)->sum('amount'), 2),
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->take(5)
            ->values();

        return [
            'from' => substr((string) $request->input('from', now()->format('Y-m-d')), 0, 10),
            'to' => substr((string) $request->input('to', $request->input('from', now()->format('Y-m-d'))), 0, 10),
            'count' => $sales->count(),
            'total_revenue' => round((float) $sales->sum('total'), 2),
            'total_items' => (int) $sales->sum(fn (Venta $sale) => $sale->detalles->sum('cantidad')),
            'by_method' => $byMethod,
            'daily_totals' => $dailyTotals,
            'top_products' => $topProducts,
            'sales' => $sales,
        ];
    }

    public function corteCaja(Request $request)
    {
        return response()->json($this->buildCashCutPayload($request));
    }

    public function corteCajaPdf(Request $request)
    {
        $payload = $this->buildCashCutPayload($request);

        $pdf = Pdf::loadView('pdfs.corte_caja_ventas', [
            'sales' => $payload['sales'],
            'from' => $payload['from'],
            'to' => $payload['to'],
            'count' => $payload['count'],
            'total_revenue' => $payload['total_revenue'],
            'total_items' => $payload['total_items'],
            'by_method' => $payload['by_method'],
            'top_products' => $payload['top_products'],
            'companyName' => config('app.name', 'IronGym'),
            'companyAddress' => config('site.company_address', 'Guatemala, Guatemala'),
            'companyPhone' => config('site.company_phone', '5868 7153'),
            'companyEmail' => config('site.company_email', 'info@irongym.gt'),
        ])->setPaper('letter')->setOption('defaultFont', 'DejaVu Sans');

        $filename = 'corte-caja-ventas-' . $payload['from'] . ($payload['from'] !== $payload['to'] ? '_a_' . $payload['to'] : '') . '.pdf';

        return $pdf->download($filename);
    }

    public function corteCajaExcel(Request $request)
    {
        $payload = $this->buildCashCutPayload($request);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $companyName = config('app.name', 'IronGym');
        $periodLabel = $payload['from'] === $payload['to']
            ? Carbon::parse($payload['from'])->format('d/m/Y')
            : Carbon::parse($payload['from'])->format('d/m/Y') . ' - ' . Carbon::parse($payload['to'])->format('d/m/Y');

        $sheet->setCellValue('A1', $companyName);
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Corte de Caja Ventas - ' . $periodLabel);
        $sheet->mergeCells('A2:F2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
        $sheet->setCellValue('A3', 'Generado: ' . now()->format('d/m/Y H:i'));
        $sheet->mergeCells('A3:F3');
        $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true);

        $headers = ['#', 'Fecha', 'Cliente', 'Productos', 'Métodos de pago', 'Total (Q)'];
        $headerRow = 5;
        foreach ($headers as $i => $header) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . $headerRow, $header);
        }

        $sheet->getStyle("A{$headerRow}:F{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4A4A4A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $row = $headerRow + 1;
        foreach ($payload['sales'] as $index => $sale) {
            $clientName = $sale->cliente?->nombre ?? 'Consumidor Final';
            $products = $sale->detalles->map(fn ($detail) => ($detail->producto->nombre ?? 'Producto') . ' x' . $detail->cantidad)->implode(', ');
            $methods = $sale->pagos->map(fn ($payment) => ($payment->metodoPago->nombre ?? 'SIN_METODO') . ' Q' . number_format((float) $payment->monto, 2))->implode(', ');

            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, Carbon::parse($sale->created_at)->timezone(self::CASH_CUT_TZ)->format('d/m/Y H:i'));
            $sheet->setCellValue('C' . $row, $clientName);
            $sheet->setCellValue('D' . $row, $products ?: '-');
            $sheet->setCellValue('E' . $row, $methods ?: 'SIN_METODO');
            $sheet->setCellValue('F' . $row, (float) $sale->total);
            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }

        $dataEnd = $row - 1;
        if ($dataEnd >= $headerRow + 1) {
            $sheet->getStyle('A' . ($headerRow + 1) . ':F' . $dataEnd)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
                'font' => ['size' => 9],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
        }

        $row += 1;
        foreach ($payload['by_method'] as $methodTotal) {
            $sheet->setCellValue('E' . $row, $methodTotal['method'] . ':');
            $sheet->setCellValue('F' . $row, (float) $methodTotal['amount']);
            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("E{$row}:F{$row}")->getFont()->setBold(true);
            $row++;
        }

        $sheet->setCellValue('E' . $row, 'TOTAL VENTAS');
        $sheet->setCellValue('F' . $row, (float) $payload['total_revenue']);
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("E{$row}:F{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'corte-caja-ventas-' . $payload['from'] . ($payload['from'] !== $payload['to'] ? '_a_' . $payload['to'] : '') . '.xlsx';
        $tempPath = storage_path('app/' . $filename);
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }
}
