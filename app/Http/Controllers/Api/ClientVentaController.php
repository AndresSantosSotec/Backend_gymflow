<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClienteVenta;
use Illuminate\Http\Request;

class ClientVentaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(ClienteVenta::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'nit' => 'nullable|string|max:50',
            'ciudad' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:50',
            'correo' => 'nullable|email|max:255',
        ]);

        $cliente = ClienteVenta::create($validated);
        return response()->json($cliente, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ClienteVenta $clienteVenta)
    {
        return response()->json($clienteVenta->load('ventas'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ClienteVenta $clienteVenta)
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'nit' => 'nullable|string|max:50',
            'ciudad' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:50',
            'correo' => 'nullable|email|max:255',
        ]);

        $clienteVenta->update($validated);
        return response()->json($clienteVenta);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ClienteVenta $clienteVenta)
    {
        $clienteVenta->delete();
        return response()->json(null, 204);
    }
}
