<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Presentacion;
use Illuminate\Http\Request;

class PresentacionController extends Controller
{
    public function index()
    {
        $presentaciones = Presentacion::withCount('productos')->orderBy('nombre')->get();
        return response()->json($presentaciones);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100|unique:presentaciones,nombre',
        ]);

        $presentacion = Presentacion::create([
            'nombre' => $request->nombre,
        ]);

        return response()->json($presentacion, 201);
    }

    public function show(Presentacion $presentacion)
    {
        return response()->json($presentacion->loadCount('productos'));
    }

    public function update(Request $request, Presentacion $presentacion)
    {
        $request->validate([
            'nombre' => 'required|string|max:100|unique:presentaciones,nombre,' . $presentacion->id,
        ]);

        $presentacion->update([
            'nombre' => $request->nombre,
        ]);

        return response()->json($presentacion);
    }

    public function destroy(Presentacion $presentacion)
    {
        // Check if presentacion has products
        if ($presentacion->productos()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar la presentación porque tiene productos asociados'
            ], 422);
        }

        $presentacion->delete();
        return response()->json(['message' => 'Presentación eliminada correctamente']);
    }
}
