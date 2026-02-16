<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\MovimientoInventario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(MovimientoInventario::with('producto')->latest()->get());
    }

    /**
     * Store a newly created resource in storage. (Ingreso/Egreso manual)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'tipo' => 'required|in:INGRESO,EGRESO',
            'cantidad' => 'required|integer|min:1',
            'motivo' => 'required|string|max:255',
        ]);

        $result = DB::transaction(function () use ($validated) {
            $producto = Producto::lockForUpdate()->find($validated['producto_id']);
            
            if ($validated['tipo'] === 'INGRESO') {
                $producto->stock += $validated['cantidad'];
            } else {
                if ($producto->stock < $validated['cantidad']) {
                    return ['error' => 'Stock insuficiente', 'status' => 422];
                }
                $producto->stock -= $validated['cantidad'];
            }
            
            $producto->save();

            $movimiento = MovimientoInventario::create($validated);
            return ['data' => $movimiento->load('producto'), 'status' => 201];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        return response()->json($result['data'], $result['status']);
    }

    public function show(MovimientoInventario $movimiento)
    {
        return response()->json($movimiento->load('producto'));
    }
}
