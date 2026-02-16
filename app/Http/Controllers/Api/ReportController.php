<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\MovimientoInventario;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * 1. Inventario Disponible
     * Muestra la cantidad y valor de los productos en cada ubicación en un momento específico.
     */
    public function inventarioDisponible(Request $request)
    {
        $fecha = $request->get('fecha', now()->toDateString());

        $productos = Producto::with(['marca', 'presentacion'])
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'descripcion' => $p->descripcion,
                    'marca' => $p->marca?->nombre ?? 'Sin marca',
                    'presentacion' => $p->presentacion?->nombre ?? 'Sin presentación',
                    'stock' => $p->stock,
                    'precio_compra' => (float)$p->precio_compra,
                    'precio_venta' => (float)$p->precio_venta,
                    'valor_costo' => (float)($p->stock * $p->precio_compra),
                    'valor_venta' => (float)($p->stock * $p->precio_venta),
                    'image_url' => $p->image_url,
                ];
            });

        $totales = [
            'total_items' => $productos->sum('stock'),
            'total_productos' => $productos->count(),
            'valor_total_costo' => $productos->sum('valor_costo'),
            'valor_total_venta' => $productos->sum('valor_venta'),
            'productos_sin_stock' => $productos->where('stock', 0)->count(),
            'productos_stock_bajo' => $productos->where('stock', '<', 5)->where('stock', '>', 0)->count(),
        ];

        return response()->json([
            'fecha_consulta' => $fecha,
            'productos' => $productos,
            'totales' => $totales,
        ]);
    }

    /**
     * 2. Movimientos de Inventario
     * Detalla las entradas y salidas de mercancía.
     */
    public function movimientosInventario(Request $request)
    {
        $fechaInicio = $request->get('fecha_inicio', now()->startOfMonth()->toDateString());
        $fechaFin = $request->get('fecha_fin', now()->toDateString());

        $movimientos = MovimientoInventario::with('producto')
            ->whereBetween('created_at', [$fechaInicio, Carbon::parse($fechaFin)->endOfDay()])
            ->latest()
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'fecha' => $m->created_at->format('Y-m-d H:i:s'),
                    'producto' => $m->producto?->nombre ?? 'N/A',
                    'producto_id' => $m->producto_id,
                    'tipo' => $m->tipo,
                    'cantidad' => $m->cantidad,
                    'motivo' => $m->motivo,
                    'referencia_id' => $m->referencia_id,
                ];
            });

        $resumen = [
            'total_ingresos' => $movimientos->where('tipo', 'INGRESO')->sum('cantidad'),
            'total_egresos' => $movimientos->where('tipo', 'EGRESO')->sum('cantidad'),
            'count_ingresos' => $movimientos->where('tipo', 'INGRESO')->count(),
            'count_egresos' => $movimientos->where('tipo', 'EGRESO')->count(),
            'total_movimientos' => $movimientos->count(),
        ];

        return response()->json([
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'movimientos' => $movimientos,
            'resumen' => $resumen,
        ]);
    }

    /**
     * 3. Catálogo de Productos y Servicios
     * Lista completa y actualizada para supervisar productos activos.
     */
    public function catalogoProductos(Request $request)
    {
        $soloActivos = $request->get('solo_activos', true);

        $query = Producto::with(['marca', 'presentacion']);
        
        if ($soloActivos) {
            $query->where('stock', '>', 0);
        }

        $productos = $query->orderBy('nombre')->get()->map(function ($p) {
            return [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'marca' => $p->marca?->nombre ?? 'Sin marca',
                'presentacion' => $p->presentacion?->nombre ?? 'Sin presentación',
                'precio_compra' => (float)$p->precio_compra,
                'precio_venta' => (float)$p->precio_venta,
                'stock' => $p->stock,
                'estado' => $p->stock > 0 ? 'ACTIVO' : 'INACTIVO',
                'margen' => $p->precio_compra > 0 
                    ? round((($p->precio_venta - $p->precio_compra) / $p->precio_compra) * 100, 2)
                    : 0,
                'image_url' => $p->image_url,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ];
        });

        $resumen = [
            'total_productos' => $productos->count(),
            'activos' => $productos->where('estado', 'ACTIVO')->count(),
            'inactivos' => $productos->where('estado', 'INACTIVO')->count(),
            'margen_promedio' => round($productos->avg('margen'), 2),
        ];

        return response()->json([
            'productos' => $productos,
            'resumen' => $resumen,
        ]);
    }

    /**
     * 4. Reporte de Valoración (Valor del Inventario) 
     * Calcula el costo financiero total del stock y la utilidad bruta.
     */
    public function valoracionInventario(Request $request)
    {
        $productos = Producto::with(['marca', 'presentacion'])->get();

        // Ventas del período para calcular utilidad bruta
        $fechaInicio = $request->get('fecha_inicio', now()->startOfMonth()->toDateString());
        $fechaFin = $request->get('fecha_fin', now()->toDateString());

        $ventasDetalles = VentaDetalle::with('producto')
            ->whereHas('venta', function ($q) use ($fechaInicio, $fechaFin) {
                $q->where('estado', 'PAGADA')
                  ->whereBetween('created_at', [$fechaInicio, Carbon::parse($fechaFin)->endOfDay()]);
            })
            ->get();

        $productosValoracion = $productos->map(function ($p) use ($ventasDetalles) {
            $ventasProducto = $ventasDetalles->where('producto_id', $p->id);
            $cantidadVendida = $ventasProducto->sum('cantidad');
            $ingresoVentas = $ventasProducto->sum('subtotal');
            $costoVentas = $cantidadVendida * $p->precio_compra;
            $utilidadBruta = $ingresoVentas - $costoVentas;

            return [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'marca' => $p->marca?->nombre ?? 'Sin marca',
                'stock' => $p->stock,
                'precio_compra' => (float)$p->precio_compra,
                'precio_venta' => (float)$p->precio_venta,
                'valor_inventario' => (float)($p->stock * $p->precio_compra),
                'valor_venta_potencial' => (float)($p->stock * $p->precio_venta),
                'cantidad_vendida' => $cantidadVendida,
                'ingreso_ventas' => (float)$ingresoVentas,
                'costo_ventas' => (float)$costoVentas,
                'utilidad_bruta' => (float)$utilidadBruta,
                'margen_porcentaje' => $ingresoVentas > 0 
                    ? round(($utilidadBruta / $ingresoVentas) * 100, 2) 
                    : 0,
            ];
        });

        $totales = [
            'valor_inventario_total' => $productosValoracion->sum('valor_inventario'),
            'valor_venta_potencial_total' => $productosValoracion->sum('valor_venta_potencial'),
            'utilidad_potencial' => $productosValoracion->sum('valor_venta_potencial') - $productosValoracion->sum('valor_inventario'),
            'ingreso_ventas_total' => $productosValoracion->sum('ingreso_ventas'),
            'costo_ventas_total' => $productosValoracion->sum('costo_ventas'),
            'utilidad_bruta_total' => $productosValoracion->sum('utilidad_bruta'),
            'margen_bruto_promedio' => $productosValoracion->where('margen_porcentaje', '>', 0)->avg('margen_porcentaje') ?? 0,
        ];

        return response()->json([
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'productos' => $productosValoracion,
            'totales' => $totales,
        ]);
    }

    /**
     * 5. Reporte de Rotación 
     * Identifica productos de mayor demanda y los que permanecen más tiempo en bodega.
     */
    public function rotacionInventario(Request $request)
    {
        $fechaInicio = $request->get('fecha_inicio', now()->subMonths(6)->startOfMonth()->toDateString());
        $fechaFin = $request->get('fecha_fin', now()->toDateString());

        $meses = Carbon::parse($fechaInicio)->diffInMonths(Carbon::parse($fechaFin)) ?: 1;

        $productos = Producto::with(['marca'])->get();

        $ventasDetalles = VentaDetalle::with('producto')
            ->whereHas('venta', function ($q) use ($fechaInicio, $fechaFin) {
                $q->where('estado', 'PAGADA')
                  ->whereBetween('created_at', [$fechaInicio, Carbon::parse($fechaFin)->endOfDay()]);
            })
            ->get();

        $productosRotacion = $productos->map(function ($p) use ($ventasDetalles, $meses) {
            $ventasProducto = $ventasDetalles->where('producto_id', $p->id);
            $cantidadVendida = $ventasProducto->sum('cantidad');
            $ventasMensualesPromedio = round($cantidadVendida / $meses, 2);
            
            // Índice de rotación: ventas / stock promedio
            $stockPromedio = max($p->stock, 1);
            $indiceRotacion = $cantidadVendida > 0 ? round($cantidadVendida / $stockPromedio, 2) : 0;
            
            // Días de inventario: cuántos días dura el stock actual
            $diasInventario = $ventasMensualesPromedio > 0 
                ? round(($p->stock / $ventasMensualesPromedio) * 30, 0) 
                : ($p->stock > 0 ? 999 : 0);

            // Clasificación ABC
            $clasificacion = 'C';
            if ($indiceRotacion >= 3) $clasificacion = 'A';
            elseif ($indiceRotacion >= 1) $clasificacion = 'B';

            return [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'marca' => $p->marca?->nombre ?? 'Sin marca',
                'stock_actual' => $p->stock,
                'cantidad_vendida' => $cantidadVendida,
                'ventas_mensuales_promedio' => $ventasMensualesPromedio,
                'indice_rotacion' => $indiceRotacion,
                'dias_inventario' => $diasInventario,
                'clasificacion' => $clasificacion,
                'ingreso_total' => (float)$ventasProducto->sum('subtotal'),
            ];
        })->sortByDesc('cantidad_vendida')->values();

        $resumen = [
            'periodo_meses' => $meses,
            'productos_alta_rotacion' => $productosRotacion->where('clasificacion', 'A')->count(),
            'productos_media_rotacion' => $productosRotacion->where('clasificacion', 'B')->count(),
            'productos_baja_rotacion' => $productosRotacion->where('clasificacion', 'C')->count(),
            'productos_sin_movimiento' => $productosRotacion->where('cantidad_vendida', 0)->count(),
            'top_5_productos' => $productosRotacion->take(5)->values(),
        ];

        return response()->json([
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'productos' => $productosRotacion,
            'resumen' => $resumen,
        ]);
    }

    /**
     * 6. Reporte Semestral de Inventarios (Guatemala)
     * Obligatorio según Decreto 10-2012 (Ley de Actualización Tributaria).
     * Debe reportarse al 30 de junio y 31 de diciembre.
     */
    public function reporteSemestral(Request $request)
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

        $productos = Producto::with(['marca', 'presentacion'])->get();

        // Movimientos del semestre
        $movimientos = MovimientoInventario::with('producto')
            ->whereBetween('created_at', [$fechaInicio, $fechaCorte])
            ->get();

        // Ventas del semestre
        $ventasDetalles = VentaDetalle::with('producto')
            ->whereHas('venta', function ($q) use ($fechaInicio, $fechaCorte) {
                $q->where('estado', 'PAGADA')
                  ->whereBetween('created_at', [$fechaInicio, $fechaCorte]);
            })
            ->get();

        $productosReporte = $productos->map(function ($p) use ($movimientos, $ventasDetalles) {
            $ingresos = $movimientos->where('producto_id', $p->id)->where('tipo', 'INGRESO')->sum('cantidad');
            $egresos = $movimientos->where('producto_id', $p->id)->where('tipo', 'EGRESO')->sum('cantidad');
            $ventasProducto = $ventasDetalles->where('producto_id', $p->id);

            return [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'marca' => $p->marca?->nombre ?? 'Sin marca',
                'presentacion' => $p->presentacion?->nombre ?? 'Sin presentación',
                'precio_compra' => (float)$p->precio_compra,
                'precio_venta' => (float)$p->precio_venta,
                'stock_actual' => $p->stock,
                'ingresos_semestre' => $ingresos,
                'egresos_semestre' => $egresos,
                'unidades_vendidas' => (int)$ventasProducto->sum('cantidad'),
                'valor_inventario' => (float)($p->stock * $p->precio_compra),
                'valor_ventas' => (float)$ventasProducto->sum('subtotal'),
            ];
        })->sortBy('nombre')->values();

        $totales = [
            'total_productos' => $productosReporte->count(),
            'total_unidades' => $productosReporte->sum('stock_actual'),
            'valor_inventario_total' => $productosReporte->sum('valor_inventario'),
            'total_ingresos_semestre' => $productosReporte->sum('ingresos_semestre'),
            'total_egresos_semestre' => $productosReporte->sum('egresos_semestre'),
            'total_ventas_semestre' => $productosReporte->sum('valor_ventas'),
        ];

        return response()->json([
            'periodo' => $periodoLabel,
            'anio' => $anio,
            'semestre' => $semestre,
            'fecha_corte' => $fechaCorte->toDateString(),
            'base_legal' => 'Decreto 10-2012, Ley de Actualización Tributaria, Guatemala',
            'productos' => $productosReporte,
            'totales' => $totales,
        ]);
    }
}
