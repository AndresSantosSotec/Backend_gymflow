<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecurrenteProducto;
use App\Services\RecurrenteProductoService;
use Illuminate\Http\Request;

/**
 * CRUD de productos de pago único (Recurrente) para inscripción/mensualidad/curso.
 */
class RecurrenteProductoController extends Controller
{
    public function __construct(private RecurrenteProductoService $productoService)
    {}

    /**
     * GET /api/recurrente/productos
     * Lista productos con filtros opcionales tipo y activo.
     */
    public function index(Request $request)
    {
        $query = RecurrenteProducto::query();

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }
        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }

        $productos = $query->orderBy('tipo')->orderBy('nombre')->get();

        return response()->json($productos->map(fn ($p) => $this->formatProducto($p)));
    }

    /**
     * POST /api/recurrente/productos
     * Crea producto en Recurrente y guarda en BD local.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre'      => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:2000',
            'monto'       => 'required|numeric|min:0',
            'tipo'        => 'required|in:inscripcion,mensualidad,curso,otro',
            'activo'      => 'boolean',
        ]);

        $validated['activo'] = $request->boolean('activo', true);

        try {
            $producto = $this->productoService->crearProductoPagoUnico($validated);
            return response()->json($this->formatProducto($producto), 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear producto en Recurrente: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * DELETE /api/recurrente/productos/{id}
     * Elimina en Recurrente (si aplica) y soft-delete local.
     */
    public function destroy(string $id)
    {
        $producto = RecurrenteProducto::findOrFail($id);
        $this->productoService->eliminarProducto($producto);
        return response()->json(null, 204);
    }

    private function formatProducto(RecurrenteProducto $p): array
    {
        return [
            'id'                     => $p->id,
            'recurrente_product_id'  => $p->recurrente_product_id,
            'recurrente_price_id'    => $p->recurrente_price_id,
            'nombre'                 => $p->nombre,
            'descripcion'            => $p->descripcion,
            'monto_centavos'         => $p->monto_centavos,
            'monto_quetzales'        => $p->monto_quetzales,
            'tipo'                   => $p->tipo,
            'storefront_link'        => $p->storefront_link,
            'activo'                 => $p->activo,
            'created_at'             => $p->created_at?->format('c'),
            'updated_at'             => $p->updated_at?->format('c'),
        ];
    }
}
