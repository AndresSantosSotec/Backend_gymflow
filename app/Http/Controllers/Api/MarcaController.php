<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Marca;
use Illuminate\Http\Request;

class MarcaController extends Controller
{
    public function index()
    {
        $marcas = Marca::withCount('productos')->orderBy('nombre')->get();
        return response()->json($marcas);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100|unique:marcas,nombre',
        ]);

        $marca = Marca::create([
            'nombre' => $request->nombre,
        ]);

        return response()->json($marca, 201);
    }

    public function show(Marca $marca)
    {
        return response()->json($marca->loadCount('productos'));
    }

    public function update(Request $request, Marca $marca)
    {
        $request->validate([
            'nombre' => 'required|string|max:100|unique:marcas,nombre,' . $marca->id,
        ]);

        $marca->update([
            'nombre' => $request->nombre,
        ]);

        return response()->json($marca);
    }

    public function destroy(Marca $marca)
    {
        // Check if marca has products
        if ($marca->productos()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar la marca porque tiene productos asociados'
            ], 422);
        }

        $marca->delete();
        return response()->json(['message' => 'Marca eliminada correctamente']);
    }
}
