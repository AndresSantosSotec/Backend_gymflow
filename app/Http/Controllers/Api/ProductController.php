<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Producto::with(['marca', 'presentacion'])->get());
    }

    /**
     * Display a public listing of products.
     */
    public function publicIndex()
    {
        // Only show products with stock > 0
        $products = Producto::with(['marca', 'presentacion'])
            ->where('stock', '>', 0)
            ->get()
            ->makeHidden(['precio_compra', 'created_at', 'updated_at']);
            
        return response()->json($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'marca_id' => 'nullable|exists:marcas,id',
            'presentacion_id' => 'nullable|exists:presentaciones,id',
            'precio_compra' => 'required|numeric',
            'precio_venta' => 'required|numeric',
            'stock' => 'integer',
            'image_url' => 'nullable|string|max:500'
        ]);

        $producto = Producto::create($validated);
        return response()->json($producto, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Producto $producto)
    {
        return response()->json($producto->load(['marca', 'presentacion', 'movimientos']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Producto $producto)
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'descripcion' => 'nullable|string',
            'marca_id' => 'nullable|exists:marcas,id',
            'presentacion_id' => 'nullable|exists:presentaciones,id',
            'precio_compra' => 'sometimes|required|numeric',
            'precio_venta' => 'sometimes|required|numeric',
            'stock' => 'integer',
            'image_url' => 'nullable|string|max:500'
        ]);

        $producto->update($validated);
        return response()->json($producto);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Producto $producto)
    {
        $producto->delete();
        return response()->json(null, 204);
    }

    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('products', $filename, 'public');
            $url = asset(Storage::url($path));

            return response()->json(['url' => $url]);
        }

        return response()->json(['error' => 'No image uploaded'], 400);
    }
}
