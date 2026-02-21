<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Models\PagoVenta;
use App\Models\Producto;
use App\Models\MovimientoInventario;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
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
                        \Log::warning('Auto-receipt failed for sale #' . $venta->id . ': ' . $e->getMessage());
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
                         \Log::warning('Auto-receipt failed for sale #' . $venta->id . ': ' . $e->getMessage());
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
}
