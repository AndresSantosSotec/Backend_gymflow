<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\MovimientoInventario;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ReportExportController extends Controller
{
    // ──────────────────────────────────────
    // Helper: Get empresa name
    // ──────────────────────────────────────
    private function getEmpresaName(): string
    {
        $settings = SiteSetting::first();
        return $settings?->gym_name ?? 'IronGym';
    }

    // ──────────────────────────────────────
    // Helper: Style Excel header
    // ──────────────────────────────────────
    private function styleExcelReport(Spreadsheet $spreadsheet, string $title, array $headers, int $startDataRow = 5): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $sheet = $spreadsheet->getActiveSheet();
        $empresa = $this->getEmpresaName();
        $colCount = count($headers);
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

        // Title row
        $sheet->setCellValue('A1', $empresa);
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Subtitle
        $sheet->setCellValue('A2', $title);
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Date
        $sheet->setCellValue('A3', 'Generado: ' . now()->format('d/m/Y H:i'));
        $sheet->mergeCells("A3:{$lastCol}3");
        $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Headers row
        $headerRow = $startDataRow - 1;
        foreach ($headers as $idx => $header) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->setCellValue("{$col}{$headerRow}", $header);
        }

        // Header style - formal gray
        $headerRange = "A{$headerRow}:{$lastCol}{$headerRow}";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4A4A4A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '333333']]],
        ]);

        // Auto-width columns
        foreach (range(1, $colCount) as $colIdx) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $sheet;
    }

    // ──────────────────────────────────────
    // Helper: Apply data borders to Excel
    // ──────────────────────────────────────
    private function applyDataBorders($sheet, int $startRow, int $endRow, int $colCount): void
    {
        if ($endRow < $startRow) return;
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
        $sheet->getStyle("A{$startRow}:{$lastCol}{$endRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
            'font' => ['size' => 9],
        ]);
        // Alternate row shading
        for ($r = $startRow; $r <= $endRow; $r++) {
            if (($r - $startRow) % 2 === 1) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F5F5F5');
            }
        }
    }

    // ──────────────────────────────────────
    // Helper: Add totals row to Excel
    // ──────────────────────────────────────
    private function addTotalsRow($sheet, int $row, array $totals, int $colCount): void
    {
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
        foreach ($totals as $colIdx => $value) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $sheet->setCellValue("{$col}{$row}", $value);
        }
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']]],
        ]);
    }

    // ──────────────────────────────────────
    // Helper: Generate PDF from view
    // ──────────────────────────────────────
    private function generatePdf(string $view, array $data, string $filename, string $orientation = 'portrait')
    {
        $data['empresa'] = $this->getEmpresaName();
        $data['fecha_generacion'] = now()->format('d/m/Y H:i');

        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper('letter', $orientation);
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => true,
            'defaultFont' => 'Helvetica',
        ]);

        return $pdf->download($filename);
    }

    // ══════════════════════════════════════
    // 1. INVENTARIO DISPONIBLE
    // ══════════════════════════════════════
    public function inventarioDisponibleExcel(Request $request)
    {
        $productos = Producto::with(['marca', 'presentacion'])->get();
        $fecha = now()->format('d/m/Y');

        $spreadsheet = new Spreadsheet();
        $headers = ['No.', 'Producto', 'Marca', 'Presentación', 'Stock', 'P. Compra (Q)', 'P. Venta (Q)', 'Valor Costo (Q)', 'Valor Venta (Q)'];
        $sheet = $this->styleExcelReport($spreadsheet, "Reporte de Inventario Disponible - {$fecha}", $headers);

        $row = 5;
        $num = 1;
        foreach ($productos as $p) {
            $sheet->setCellValue("A{$row}", $num);
            $sheet->setCellValue("B{$row}", $p->nombre);
            $sheet->setCellValue("C{$row}", $p->marca?->nombre ?? 'Sin marca');
            $sheet->setCellValue("D{$row}", $p->presentacion?->nombre ?? 'N/A');
            $sheet->setCellValue("E{$row}", $p->stock);
            $sheet->setCellValue("F{$row}", (float)$p->precio_compra);
            $sheet->setCellValue("G{$row}", (float)$p->precio_venta);
            $sheet->setCellValue("H{$row}", (float)($p->stock * $p->precio_compra));
            $sheet->setCellValue("I{$row}", (float)($p->stock * $p->precio_venta));

            // Number format for currency
            foreach (['F', 'G', 'H', 'I'] as $c) {
                $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $row++;
            $num++;
        }

        $this->applyDataBorders($sheet, 5, $row - 1, count($headers));

        // Totals
        $this->addTotalsRow($sheet, $row, [
            0 => '', 1 => 'TOTALES', 2 => '', 3 => '',
            4 => $productos->sum('stock'),
            5 => '', 6 => '',
            7 => $productos->sum(fn($p) => $p->stock * $p->precio_compra),
            8 => $productos->sum(fn($p) => $p->stock * $p->precio_venta),
        ], count($headers));

        foreach (['H', 'I'] as $c) {
            $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'inventario_disponible_' . now()->format('Ymd_His') . '.xlsx';
        $tempPath = storage_path("app/{$filename}");
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function inventarioDisponiblePdf(Request $request)
    {
        $productos = Producto::with(['marca', 'presentacion'])->get()->map(function ($p) {
            return [
                'nombre' => $p->nombre,
                'marca' => $p->marca?->nombre ?? 'Sin marca',
                'presentacion' => $p->presentacion?->nombre ?? 'N/A',
                'stock' => $p->stock,
                'precio_compra' => (float)$p->precio_compra,
                'precio_venta' => (float)$p->precio_venta,
                'valor_costo' => (float)($p->stock * $p->precio_compra),
                'valor_venta' => (float)($p->stock * $p->precio_venta),
            ];
        });

        $totales = [
            'total_stock' => $productos->sum('stock'),
            'valor_total_costo' => $productos->sum('valor_costo'),
            'valor_total_venta' => $productos->sum('valor_venta'),
        ];

        return $this->generatePdf('reports.inventario-disponible', [
            'productos' => $productos,
            'totales' => $totales,
            'titulo' => 'Reporte de Inventario Disponible',
        ], 'inventario_disponible_' . now()->format('Ymd') . '.pdf', 'landscape');
    }

    // ══════════════════════════════════════
    // 2. MOVIMIENTOS DE INVENTARIO
    // ══════════════════════════════════════
    public function movimientosExcel(Request $request)
    {
        $fechaInicio = $request->get('fecha_inicio', now()->startOfMonth()->toDateString());
        $fechaFin = $request->get('fecha_fin', now()->toDateString());

        $movimientos = MovimientoInventario::with('producto')
            ->whereBetween('created_at', [$fechaInicio, Carbon::parse($fechaFin)->endOfDay()])
            ->latest()->get();

        $spreadsheet = new Spreadsheet();
        $headers = ['No.', 'Fecha', 'Tipo', 'Producto', 'Cantidad', 'Motivo', 'Referencia'];
        $sheet = $this->styleExcelReport($spreadsheet, "Movimientos de Inventario ({$fechaInicio} a {$fechaFin})", $headers);

        $row = 5;
        $num = 1;
        foreach ($movimientos as $m) {
            $sheet->setCellValue("A{$row}", $num);
            $sheet->setCellValue("B{$row}", $m->created_at->format('d/m/Y H:i'));
            $sheet->setCellValue("C{$row}", $m->tipo);
            $sheet->setCellValue("D{$row}", $m->producto?->nombre ?? 'N/A');
            $sheet->setCellValue("E{$row}", $m->cantidad);
            $sheet->setCellValue("F{$row}", $m->motivo);
            $sheet->setCellValue("G{$row}", $m->referencia_id ? "#{$m->referencia_id}" : '-');
            $row++;
            $num++;
        }

        $this->applyDataBorders($sheet, 5, $row - 1, count($headers));

        // Resumen
        $row += 1;
        $sheet->setCellValue("A{$row}", 'RESUMEN');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $row++;
        $sheet->setCellValue("A{$row}", 'Total Ingresos:');
        $sheet->setCellValue("B{$row}", $movimientos->where('tipo', 'INGRESO')->count() . ' movimientos (' . $movimientos->where('tipo', 'INGRESO')->sum('cantidad') . ' uds.)');
        $row++;
        $sheet->setCellValue("A{$row}", 'Total Egresos:');
        $sheet->setCellValue("B{$row}", $movimientos->where('tipo', 'EGRESO')->count() . ' movimientos (' . $movimientos->where('tipo', 'EGRESO')->sum('cantidad') . ' uds.)');
        $sheet->getStyle("A" . ($row - 2) . ":B{$row}")->getFont()->setBold(true);

        $writer = new Xlsx($spreadsheet);
        $filename = 'movimientos_inventario_' . now()->format('Ymd_His') . '.xlsx';
        $tempPath = storage_path("app/{$filename}");
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function movimientosPdf(Request $request)
    {
        $fechaInicio = $request->get('fecha_inicio', now()->startOfMonth()->toDateString());
        $fechaFin = $request->get('fecha_fin', now()->toDateString());

        $movimientos = MovimientoInventario::with('producto')
            ->whereBetween('created_at', [$fechaInicio, Carbon::parse($fechaFin)->endOfDay()])
            ->latest()->get();

        $resumen = [
            'total_ingresos' => $movimientos->where('tipo', 'INGRESO')->sum('cantidad'),
            'total_egresos' => $movimientos->where('tipo', 'EGRESO')->sum('cantidad'),
            'count_ingresos' => $movimientos->where('tipo', 'INGRESO')->count(),
            'count_egresos' => $movimientos->where('tipo', 'EGRESO')->count(),
        ];

        return $this->generatePdf('reports.movimientos', [
            'movimientos' => $movimientos,
            'resumen' => $resumen,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'titulo' => 'Reporte de Movimientos de Inventario',
        ], 'movimientos_' . now()->format('Ymd') . '.pdf', 'landscape');
    }

    // ══════════════════════════════════════
    // 3. CATÁLOGO DE PRODUCTOS
    // ══════════════════════════════════════
    public function catalogoExcel(Request $request)
    {
        $productos = Producto::with(['marca', 'presentacion'])->orderBy('nombre')->get();

        $spreadsheet = new Spreadsheet();
        $headers = ['No.', 'Producto', 'Descripción', 'Marca', 'Presentación', 'P. Compra (Q)', 'P. Venta (Q)', 'Margen %', 'Stock', 'Estado'];
        $sheet = $this->styleExcelReport($spreadsheet, 'Catálogo de Productos y Servicios', $headers);

        $row = 5;
        $num = 1;
        foreach ($productos as $p) {
            $margen = $p->precio_compra > 0 ? round((($p->precio_venta - $p->precio_compra) / $p->precio_compra) * 100, 2) : 0;
            $sheet->setCellValue("A{$row}", $num);
            $sheet->setCellValue("B{$row}", $p->nombre);
            $sheet->setCellValue("C{$row}", $p->descripcion ?? '-');
            $sheet->setCellValue("D{$row}", $p->marca?->nombre ?? 'Sin marca');
            $sheet->setCellValue("E{$row}", $p->presentacion?->nombre ?? 'N/A');
            $sheet->setCellValue("F{$row}", (float)$p->precio_compra);
            $sheet->setCellValue("G{$row}", (float)$p->precio_venta);
            $sheet->setCellValue("H{$row}", $margen);
            $sheet->setCellValue("I{$row}", $p->stock);
            $sheet->setCellValue("J{$row}", $p->stock > 0 ? 'ACTIVO' : 'INACTIVO');

            foreach (['F', 'G'] as $c) {
                $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode('0.00"%"');

            $row++;
            $num++;
        }

        $this->applyDataBorders($sheet, 5, $row - 1, count($headers));

        $writer = new Xlsx($spreadsheet);
        $filename = 'catalogo_productos_' . now()->format('Ymd_His') . '.xlsx';
        $tempPath = storage_path("app/{$filename}");
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function catalogoPdf(Request $request)
    {
        $productos = Producto::with(['marca', 'presentacion'])->orderBy('nombre')->get()->map(function ($p) {
            return [
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion ?? '-',
                'marca' => $p->marca?->nombre ?? 'Sin marca',
                'presentacion' => $p->presentacion?->nombre ?? 'N/A',
                'precio_compra' => (float)$p->precio_compra,
                'precio_venta' => (float)$p->precio_venta,
                'margen' => $p->precio_compra > 0 ? round((($p->precio_venta - $p->precio_compra) / $p->precio_compra) * 100, 2) : 0,
                'stock' => $p->stock,
                'estado' => $p->stock > 0 ? 'ACTIVO' : 'INACTIVO',
            ];
        });

        return $this->generatePdf('reports.catalogo', [
            'productos' => $productos,
            'titulo' => 'Catálogo de Productos y Servicios',
        ], 'catalogo_productos_' . now()->format('Ymd') . '.pdf', 'landscape');
    }

    // ══════════════════════════════════════
    // 4. VALORACIÓN DEL INVENTARIO
    // ══════════════════════════════════════
    public function valoracionExcel(Request $request)
    {
        $fechaInicio = $request->get('fecha_inicio', now()->startOfMonth()->toDateString());
        $fechaFin = $request->get('fecha_fin', now()->toDateString());

        $productos = Producto::with(['marca'])->get();
        $ventasDetalles = VentaDetalle::with('producto')
            ->whereHas('venta', fn($q) => $q->where('estado', 'PAGADA')->whereBetween('created_at', [$fechaInicio, Carbon::parse($fechaFin)->endOfDay()]))
            ->get();

        $spreadsheet = new Spreadsheet();
        $headers = ['No.', 'Producto', 'Stock', 'Valor Inv. (Q)', 'Vendido', 'Ingreso Vta. (Q)', 'Costo Vta. (Q)', 'Utilidad (Q)', 'Margen %'];
        $sheet = $this->styleExcelReport($spreadsheet, "Reporte de Valoración de Inventario ({$fechaInicio} a {$fechaFin})", $headers);

        $row = 5;
        $num = 1;
        $totValorInv = 0; $totIngresoVta = 0; $totCostoVta = 0; $totUtilidad = 0;

        foreach ($productos as $p) {
            $ventas = $ventasDetalles->where('producto_id', $p->id);
            $cantVendida = $ventas->sum('cantidad');
            $ingresoVta = (float)$ventas->sum('subtotal');
            $costoVta = $cantVendida * $p->precio_compra;
            $utilidad = $ingresoVta - $costoVta;
            $valorInv = $p->stock * $p->precio_compra;
            $margen = $ingresoVta > 0 ? round(($utilidad / $ingresoVta) * 100, 2) : 0;

            $sheet->setCellValue("A{$row}", $num);
            $sheet->setCellValue("B{$row}", $p->nombre);
            $sheet->setCellValue("C{$row}", $p->stock);
            $sheet->setCellValue("D{$row}", $valorInv);
            $sheet->setCellValue("E{$row}", $cantVendida);
            $sheet->setCellValue("F{$row}", $ingresoVta);
            $sheet->setCellValue("G{$row}", $costoVta);
            $sheet->setCellValue("H{$row}", $utilidad);
            $sheet->setCellValue("I{$row}", $margen);

            foreach (['D', 'F', 'G', 'H'] as $c) {
                $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode('0.00"%"');

            $totValorInv += $valorInv;
            $totIngresoVta += $ingresoVta;
            $totCostoVta += $costoVta;
            $totUtilidad += $utilidad;
            $row++;
            $num++;
        }

        $this->applyDataBorders($sheet, 5, $row - 1, count($headers));
        $this->addTotalsRow($sheet, $row, [
            0 => '', 1 => 'TOTALES', 2 => '',
            3 => $totValorInv, 4 => '',
            5 => $totIngresoVta, 6 => $totCostoVta,
            7 => $totUtilidad, 8 => '',
        ], count($headers));
        foreach (['D', 'F', 'G', 'H'] as $c) {
            $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'valoracion_inventario_' . now()->format('Ymd_His') . '.xlsx';
        $tempPath = storage_path("app/{$filename}");
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function valoracionPdf(Request $request)
    {
        $fechaInicio = $request->get('fecha_inicio', now()->startOfMonth()->toDateString());
        $fechaFin = $request->get('fecha_fin', now()->toDateString());

        $productos = Producto::with(['marca'])->get();
        $ventasDetalles = VentaDetalle::with('producto')
            ->whereHas('venta', fn($q) => $q->where('estado', 'PAGADA')->whereBetween('created_at', [$fechaInicio, Carbon::parse($fechaFin)->endOfDay()]))
            ->get();

        $productosData = $productos->map(function ($p) use ($ventasDetalles) {
            $ventas = $ventasDetalles->where('producto_id', $p->id);
            $cantVendida = $ventas->sum('cantidad');
            $ingresoVta = (float)$ventas->sum('subtotal');
            $costoVta = $cantVendida * $p->precio_compra;
            return [
                'nombre' => $p->nombre,
                'stock' => $p->stock,
                'valor_inventario' => $p->stock * $p->precio_compra,
                'cantidad_vendida' => $cantVendida,
                'ingreso_ventas' => $ingresoVta,
                'costo_ventas' => $costoVta,
                'utilidad_bruta' => $ingresoVta - $costoVta,
                'margen' => $ingresoVta > 0 ? round((($ingresoVta - $costoVta) / $ingresoVta) * 100, 2) : 0,
            ];
        });

        $totales = [
            'valor_inventario' => $productosData->sum('valor_inventario'),
            'ingreso_ventas' => $productosData->sum('ingreso_ventas'),
            'costo_ventas' => $productosData->sum('costo_ventas'),
            'utilidad_bruta' => $productosData->sum('utilidad_bruta'),
        ];

        return $this->generatePdf('reports.valoracion', [
            'productos' => $productosData,
            'totales' => $totales,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'titulo' => 'Reporte de Valoración del Inventario',
        ], 'valoracion_' . now()->format('Ymd') . '.pdf', 'landscape');
    }

    // ══════════════════════════════════════
    // 5. ROTACIÓN DE INVENTARIO
    // ══════════════════════════════════════
    public function rotacionExcel(Request $request)
    {
        $fechaInicio = $request->get('fecha_inicio', now()->subMonths(6)->startOfMonth()->toDateString());
        $fechaFin = $request->get('fecha_fin', now()->toDateString());
        $meses = Carbon::parse($fechaInicio)->diffInMonths(Carbon::parse($fechaFin)) ?: 1;

        $productos = Producto::with(['marca'])->get();
        $ventasDetalles = VentaDetalle::with('producto')
            ->whereHas('venta', fn($q) => $q->where('estado', 'PAGADA')->whereBetween('created_at', [$fechaInicio, Carbon::parse($fechaFin)->endOfDay()]))
            ->get();

        $spreadsheet = new Spreadsheet();
        $headers = ['No.', 'Producto', 'Clasif.', 'Stock', 'Vendido', 'Vta./Mes', 'Índ. Rotación', 'Días Inv.', 'Ingreso (Q)'];
        $sheet = $this->styleExcelReport($spreadsheet, "Reporte de Rotación de Inventario (Últimos {$meses} meses)", $headers);

        $row = 5;
        $num = 1;
        $data = [];
        foreach ($productos as $p) {
            $ventas = $ventasDetalles->where('producto_id', $p->id);
            $cantVendida = $ventas->sum('cantidad');
            $vtaMes = round($cantVendida / $meses, 2);
            $stockProm = max($p->stock, 1);
            $indiceRot = $cantVendida > 0 ? round($cantVendida / $stockProm, 2) : 0;
            $diasInv = $vtaMes > 0 ? round(($p->stock / $vtaMes) * 30, 0) : ($p->stock > 0 ? 999 : 0);
            $clasif = $indiceRot >= 3 ? 'A' : ($indiceRot >= 1 ? 'B' : 'C');

            $data[] = ['cant' => $cantVendida, 'row' => $row, 'nombre' => $p->nombre, 'clasif' => $clasif,
                       'stock' => $p->stock, 'vtaMes' => $vtaMes, 'indiceRot' => $indiceRot,
                       'diasInv' => $diasInv, 'ingreso' => (float)$ventas->sum('subtotal')];
        }

        usort($data, fn($a, $b) => $b['cant'] - $a['cant']);

        foreach ($data as $d) {
            $sheet->setCellValue("A{$row}", $num);
            $sheet->setCellValue("B{$row}", $d['nombre']);
            $sheet->setCellValue("C{$row}", $d['clasif']);
            $sheet->setCellValue("D{$row}", $d['stock']);
            $sheet->setCellValue("E{$row}", $d['cant']);
            $sheet->setCellValue("F{$row}", $d['vtaMes']);
            $sheet->setCellValue("G{$row}", $d['indiceRot']);
            $sheet->setCellValue("H{$row}", $d['diasInv'] >= 999 ? '∞' : $d['diasInv']);
            $sheet->setCellValue("I{$row}", $d['ingreso']);
            $sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
            $num++;
        }

        $this->applyDataBorders($sheet, 5, $row - 1, count($headers));

        $writer = new Xlsx($spreadsheet);
        $filename = 'rotacion_inventario_' . now()->format('Ymd_His') . '.xlsx';
        $tempPath = storage_path("app/{$filename}");
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function rotacionPdf(Request $request)
    {
        $fechaInicio = $request->get('fecha_inicio', now()->subMonths(6)->startOfMonth()->toDateString());
        $fechaFin = $request->get('fecha_fin', now()->toDateString());
        $meses = Carbon::parse($fechaInicio)->diffInMonths(Carbon::parse($fechaFin)) ?: 1;

        $productos = Producto::with(['marca'])->get();
        $ventasDetalles = VentaDetalle::with('producto')
            ->whereHas('venta', fn($q) => $q->where('estado', 'PAGADA')->whereBetween('created_at', [$fechaInicio, Carbon::parse($fechaFin)->endOfDay()]))
            ->get();

        $productosData = $productos->map(function ($p) use ($ventasDetalles, $meses) {
            $ventas = $ventasDetalles->where('producto_id', $p->id);
            $cantVendida = $ventas->sum('cantidad');
            $vtaMes = round($cantVendida / $meses, 2);
            $stockProm = max($p->stock, 1);
            $indiceRot = $cantVendida > 0 ? round($cantVendida / $stockProm, 2) : 0;
            $diasInv = $vtaMes > 0 ? round(($p->stock / $vtaMes) * 30, 0) : ($p->stock > 0 ? 999 : 0);
            return [
                'nombre' => $p->nombre,
                'clasificacion' => $indiceRot >= 3 ? 'A' : ($indiceRot >= 1 ? 'B' : 'C'),
                'stock' => $p->stock,
                'cantidad_vendida' => $cantVendida,
                'vta_mes' => $vtaMes,
                'indice_rotacion' => $indiceRot,
                'dias_inventario' => $diasInv,
                'ingreso' => (float)$ventas->sum('subtotal'),
            ];
        })->sortByDesc('cantidad_vendida')->values();

        return $this->generatePdf('reports.rotacion', [
            'productos' => $productosData,
            'meses' => $meses,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'titulo' => 'Reporte de Rotación de Inventario',
        ], 'rotacion_' . now()->format('Ymd') . '.pdf', 'landscape');
    }

    // ══════════════════════════════════════
    // 6. REPORTE SEMESTRAL GUATEMALA
    // ══════════════════════════════════════
    public function semestralExcel(Request $request)
    {
        $anio = (int)$request->get('anio', now()->year);
        $semestre = (int)$request->get('semestre', now()->month <= 6 ? 1 : 2);

        if ($semestre === 1) {
            $fechaCorte = Carbon::create($anio, 6, 30)->endOfDay();
            $fechaInicio = Carbon::create($anio, 1, 1)->startOfDay();
            $periodoLabel = "Enero - Junio {$anio}";
        } else {
            $fechaCorte = Carbon::create($anio, 12, 31)->endOfDay();
            $fechaInicio = Carbon::create($anio, 7, 1)->startOfDay();
            $periodoLabel = "Julio - Diciembre {$anio}";
        }

        $productos = Producto::with(['marca', 'presentacion'])->orderBy('nombre')->get();
        $movimientos = MovimientoInventario::whereBetween('created_at', [$fechaInicio, $fechaCorte])->get();
        $ventasDetalles = VentaDetalle::whereHas('venta', fn($q) => $q->where('estado', 'PAGADA')->whereBetween('created_at', [$fechaInicio, $fechaCorte]))->get();

        $spreadsheet = new Spreadsheet();
        $headers = ['No.', 'Producto', 'Marca', 'Presentación', 'P. Compra (Q)', 'P. Venta (Q)', 'Stock', 'Valor Inv. (Q)', 'Ingresos', 'Egresos', 'Vendido', 'Ventas (Q)'];
        $sheet = $this->styleExcelReport($spreadsheet, "Reporte Semestral de Inventario - {$periodoLabel}", $headers);

        // Legal note
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setCellValue('A3', "Base Legal: Decreto 10-2012 | Fecha Corte: {$fechaCorte->format('d/m/Y')} | Generado: " . now()->format('d/m/Y H:i'));
        $sheet->mergeCells("A3:{$lastCol}3");

        $row = 5;
        $num = 1;
        $totValorInv = 0; $totVentas = 0;

        foreach ($productos as $p) {
            $ingresos = $movimientos->where('producto_id', $p->id)->where('tipo', 'INGRESO')->sum('cantidad');
            $egresos = $movimientos->where('producto_id', $p->id)->where('tipo', 'EGRESO')->sum('cantidad');
            $ventasProd = $ventasDetalles->where('producto_id', $p->id);
            $valorInv = $p->stock * $p->precio_compra;
            $valorVentas = (float)$ventasProd->sum('subtotal');

            $sheet->setCellValue("A{$row}", $num);
            $sheet->setCellValue("B{$row}", $p->nombre);
            $sheet->setCellValue("C{$row}", $p->marca?->nombre ?? 'Sin marca');
            $sheet->setCellValue("D{$row}", $p->presentacion?->nombre ?? 'N/A');
            $sheet->setCellValue("E{$row}", (float)$p->precio_compra);
            $sheet->setCellValue("F{$row}", (float)$p->precio_venta);
            $sheet->setCellValue("G{$row}", $p->stock);
            $sheet->setCellValue("H{$row}", $valorInv);
            $sheet->setCellValue("I{$row}", $ingresos);
            $sheet->setCellValue("J{$row}", $egresos);
            $sheet->setCellValue("K{$row}", (int)$ventasProd->sum('cantidad'));
            $sheet->setCellValue("L{$row}", $valorVentas);

            foreach (['E', 'F', 'H', 'L'] as $c) {
                $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            }

            $totValorInv += $valorInv;
            $totVentas += $valorVentas;
            $row++;
            $num++;
        }

        $this->applyDataBorders($sheet, 5, $row - 1, count($headers));
        $this->addTotalsRow($sheet, $row, [
            0 => '', 1 => 'TOTALES', 2 => '', 3 => '', 4 => '', 5 => '',
            6 => $productos->sum('stock'),
            7 => $totValorInv,
            8 => '', 9 => '', 10 => '',
            11 => $totVentas,
        ], count($headers));
        foreach (['H', 'L'] as $c) {
            $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'reporte_semestral_' . $periodoLabel . '_' . now()->format('Ymd_His') . '.xlsx';
        $tempPath = storage_path("app/" . str_replace(' ', '_', $filename));
        $writer->save($tempPath);

        return response()->download($tempPath, str_replace(' ', '_', $filename))->deleteFileAfterSend(true);
    }

    public function semestralPdf(Request $request)
    {
        $anio = (int)$request->get('anio', now()->year);
        $semestre = (int)$request->get('semestre', now()->month <= 6 ? 1 : 2);

        if ($semestre === 1) {
            $fechaCorte = Carbon::create($anio, 6, 30)->endOfDay();
            $fechaInicio = Carbon::create($anio, 1, 1)->startOfDay();
            $periodoLabel = "Enero - Junio {$anio}";
        } else {
            $fechaCorte = Carbon::create($anio, 12, 31)->endOfDay();
            $fechaInicio = Carbon::create($anio, 7, 1)->startOfDay();
            $periodoLabel = "Julio - Diciembre {$anio}";
        }

        $productos = Producto::with(['marca', 'presentacion'])->orderBy('nombre')->get();
        $movimientos = MovimientoInventario::whereBetween('created_at', [$fechaInicio, $fechaCorte])->get();
        $ventasDetalles = VentaDetalle::whereHas('venta', fn($q) => $q->where('estado', 'PAGADA')->whereBetween('created_at', [$fechaInicio, $fechaCorte]))->get();

        $productosData = $productos->map(function ($p) use ($movimientos, $ventasDetalles) {
            $ventasProd = $ventasDetalles->where('producto_id', $p->id);
            return [
                'nombre' => $p->nombre,
                'marca' => $p->marca?->nombre ?? 'Sin marca',
                'presentacion' => $p->presentacion?->nombre ?? 'N/A',
                'precio_compra' => (float)$p->precio_compra,
                'precio_venta' => (float)$p->precio_venta,
                'stock' => $p->stock,
                'valor_inventario' => $p->stock * $p->precio_compra,
                'ingresos' => $movimientos->where('producto_id', $p->id)->where('tipo', 'INGRESO')->sum('cantidad'),
                'egresos' => $movimientos->where('producto_id', $p->id)->where('tipo', 'EGRESO')->sum('cantidad'),
                'unidades_vendidas' => (int)$ventasProd->sum('cantidad'),
                'valor_ventas' => (float)$ventasProd->sum('subtotal'),
            ];
        });

        $totales = [
            'total_stock' => $productosData->sum('stock'),
            'valor_inventario' => $productosData->sum('valor_inventario'),
            'total_ventas' => $productosData->sum('valor_ventas'),
        ];

        return $this->generatePdf('reports.semestral', [
            'productos' => $productosData,
            'totales' => $totales,
            'periodo' => $periodoLabel,
            'fecha_corte' => $fechaCorte->format('d/m/Y'),
            'anio' => $anio,
            'titulo' => 'Reporte Semestral de Inventarios',
        ], 'reporte_semestral_' . now()->format('Ymd') . '.pdf', 'landscape');
    }
}
