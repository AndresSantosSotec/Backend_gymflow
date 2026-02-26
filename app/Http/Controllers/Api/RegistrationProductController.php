<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegistrationProduct;
use App\Services\RecurrenteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RegistrationProductController
 *
 * Gestiona productos de inscripción/matrícula (pagos únicos via Recurrente)
 */
class RegistrationProductController extends Controller
{
    public function __construct(private RecurrenteService $recurrente)
    {}

    /**
     * GET /api/registration-products
     * Lista todos los productos de inscripción
     */
    public function index(Request $request)
    {
        $query = RegistrationProduct::query();

        // Filtro por publicado
        if ($request->has('published')) {
            $query->where('published', $request->boolean('published'));
        }

        // Solo disponibles
        if ($request->boolean('available')) {
            $query->available();
        }

        $products = $query->orderBy('name', 'asc')
            ->get()
            ->map(fn ($p) => $this->formatProduct($p));

        return response()->json($products);
    }

    /**
     * GET /api/registration-products/public
     * Lista productos publicados y disponibles (sin autenticación)
     */
    public function publicProducts()
    {
        $products = RegistrationProduct::available()
            ->orderBy('name', 'asc')
            ->get()
            ->map(fn ($p) => $this->formatProduct($p));

        return response()->json($products);
    }

    /**
     * GET /api/registration-products/{id}
     * Obtener un producto específico
     */
    public function show(string $id)
    {
        $product = RegistrationProduct::findOrFail($id);
        return response()->json($this->formatProduct($product));
    }

    /**
     * POST /api/registration-products
     * Crear nuevo producto de inscripción Y sincronizar con Recurrente
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                      => 'required|string|max:255',
            'slug'                      => 'nullable|string|max:255',
            'description'               => 'nullable|string',
            'price'                     => 'required|numeric|min:0',
            'image_url'                 => 'nullable|url|max:500',
            'published'                 => 'boolean',
            'max_uses'                  => 'nullable|integer|min:1',
            'success_url'               => 'nullable|url',
            'cancel_url'                => 'nullable|url',
            'phone_requirement'         => 'nullable|in:none,optional,required',
            'address_requirement'       => 'nullable|in:none,optional,required',
            'billing_info_requirement'  => 'nullable|in:none,optional,required',
            'metadata'                  => 'nullable|array',
        ]);

        // Generar slug si no viene
        $slug = !empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);

        // Asegurar unicidad del slug
        $baseSlug = $slug;
        $counter  = 1;
        while (RegistrationProduct::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }
        $validated['slug'] = $slug;

        return DB::transaction(function () use ($validated) {
            // 1. Crear producto local
            $product = RegistrationProduct::create($validated);

            // 2. Crear producto en Recurrente como "one_time"
            try {
                $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

                $recurrentePayload = [
                    'product' => [
                        'name'        => $product->name,
                        'description' => $product->description ?? "Inscripción: {$product->name}",
                        'image_url'   => $product->image_url,
                        'success_url' => $product->success_url ?? "{$frontendUrl}/pagos/exitoso",
                        'cancel_url'  => $product->cancel_url ?? "{$frontendUrl}/pagos/cancelado",
                        'phone_requirement'        => $product->phone_requirement ?? 'none',
                        'address_requirement'      => $product->address_requirement ?? 'none',
                        'billing_info_requirement' => $product->billing_info_requirement ?? 'none',
                        'prices_attributes' => [
                            [
                                'amount_in_cents' => RecurrenteService::toCents((float) $product->price),
                                'currency'        => 'GTQ',
                                'charge_type'     => 'one_time', // ← CLAVE: pago único
                            ],
                        ],
                    ],
                ];

                if ($product->metadata) {
                    $recurrentePayload['metadata'] = $product->metadata;
                }

                Log::info('[RegistrationProduct] Creando producto one_time en Recurrente', [
                    'product_id' => $product->id,
                    'payload'    => $recurrentePayload,
                ]);

                $response = $this->recurrente->createOneTimeProduct($recurrentePayload);

                // Guardar IDs de Recurrente
                $product->update([
                    'recurrente_product_id' => $response['id'] ?? null,
                    'recurrente_price_id'   => $response['prices'][0]['id'] ?? null,
                ]);

                Log::info('[RegistrationProduct] ✅ Producto one_time creado en Recurrente', [
                    'product_id'            => $product->id,
                    'recurrente_product_id' => $response['id'] ?? null,
                    'recurrente_price_id'   => $response['prices'][0]['id'] ?? null,
                ]);

            } catch (\Exception $e) {
                Log::error('[RegistrationProduct] ❌ Error al crear producto en Recurrente', [
                    'product_id' => $product->id,
                    'error'      => $e->getMessage(),
                ]);
            }

            return response()->json($this->formatProduct($product->fresh()), 201);
        });
    }

    /**
     * PATCH /api/registration-products/{id}
     * Actualizar producto
     */
    public function update(Request $request, string $id)
    {
        $product = RegistrationProduct::findOrFail($id);

        $validated = $request->validate([
            'name'                      => 'sometimes|required|string|max:255',
            'slug'                      => 'sometimes|string|max:255',
            'description'               => 'nullable|string',
            'price'                     => 'sometimes|required|numeric|min:0',
            'image_url'                 => 'nullable|url|max:500',
            'published'                 => 'boolean',
            'max_uses'                  => 'nullable|integer|min:1',
            'success_url'               => 'nullable|url',
            'cancel_url'                => 'nullable|url',
            'phone_requirement'         => 'nullable|in:none,optional,required',
            'address_requirement'       => 'nullable|in:none,optional,required',
            'billing_info_requirement'  => 'nullable|in:none,optional,required',
            'metadata'                  => 'nullable|array',
        ]);

        // Si viene slug, asegurar unicidad
        if (isset($validated['slug'])) {
            $slug     = Str::slug($validated['slug']);
            $baseSlug = $slug;
            $counter  = 1;
            while (RegistrationProduct::withTrashed()->where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validated['slug'] = $slug;
        }

        // Actualizar localmente
        $product->update($validated);

        // Sincronizar con Recurrente si existe
        if ($product->recurrente_product_id) {
            try {
                $recurrentePayload = ['product' => []];

                if (isset($validated['name'])) {
                    $recurrentePayload['product']['name'] = $validated['name'];
                }
                if (isset($validated['description'])) {
                    $recurrentePayload['product']['description'] = $validated['description'];
                }
                if (isset($validated['image_url'])) {
                    $recurrentePayload['product']['image_url'] = $validated['image_url'];
                }

                // Si cambió el precio, actualizar via prices_attributes
                if (isset($validated['price']) && $product->recurrente_price_id) {
                    $recurrentePayload['product']['prices_attributes'] = [
                        [
                            'id'              => $product->recurrente_price_id,
                            'amount_in_cents' => RecurrenteService::toCents((float) $validated['price']),
                        ],
                    ];
                }

                if (!empty($recurrentePayload['product'])) {
                    $this->recurrente->updateProduct($product->recurrente_product_id, $recurrentePayload);

                    Log::info('[RegistrationProduct] ✅ Producto actualizado en Recurrente', [
                        'product_id'            => $product->id,
                        'recurrente_product_id' => $product->recurrente_product_id,
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('[RegistrationProduct] ❌ Error al actualizar en Recurrente', [
                    'product_id' => $product->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return response()->json($this->formatProduct($product->fresh()));
    }

    /**
     * DELETE /api/registration-products/{id}
     * Eliminar producto (soft delete local + eliminar de Recurrente)
     */
    public function destroy(string $id)
    {
        $product = RegistrationProduct::findOrFail($id);

        // Eliminar de Recurrente si existe
        if ($product->recurrente_product_id) {
            try {
                $this->recurrente->deleteProduct($product->recurrente_product_id);
                Log::info('[RegistrationProduct] ✅ Producto eliminado de Recurrente', [
                    'product_id'            => $product->id,
                    'recurrente_product_id' => $product->recurrente_product_id,
                ]);
            } catch (\Exception $e) {
                Log::warning('[RegistrationProduct] ⚠ No se pudo eliminar de Recurrente', [
                    'product_id' => $product->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/registration-products/{id}/checkout
     * Generar link de pago para un cliente específico
     *
     * Body: { "user_id": "us_xxx", "metadata": {} }
     */
    public function createCheckout(Request $request, string $id)
    {
        $product = RegistrationProduct::findOrFail($id);

        // Validar disponibilidad
        if (!$product->isAvailable()) {
            return response()->json([
                'error' => 'Este producto no está disponible actualmente'
            ], 422);
        }

        $validated = $request->validate([
            'user_id'  => 'required|string', // ID de usuario en Recurrente
            'metadata' => 'nullable|array',
        ]);

        try {
            $checkoutPayload = [
                'user_id' => $validated['user_id'],
                'items'   => [
                    [
                        'product_id' => $product->recurrente_product_id,
                        'quantity'   => 1,
                    ],
                ],
            ];

            if (isset($validated['metadata'])) {
                $checkoutPayload['metadata'] = $validated['metadata'];
            }

            $checkout = $this->recurrente->createCheckout($checkoutPayload);

            // Incrementar contador de usos
            $product->incrementUses();

            Log::info('[RegistrationProduct] ✅ Checkout creado', [
                'product_id'  => $product->id,
                'checkout_id' => $checkout['id'] ?? null,
                'user_id'     => $validated['user_id'],
            ]);

            return response()->json([
                'checkout_id'  => $checkout['id'] ?? null,
                'checkout_url' => $checkout['url'] ?? null,
                'product'      => $this->formatProduct($product),
            ]);

        } catch (\Exception $e) {
            Log::error('[RegistrationProduct] ❌ Error al crear checkout', [
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Error al generar link de pago: ' . $e->getMessage()
            ], 500);
        }
    }

    // ─── Helper ───────────────────────────────────────────────────

    private function formatProduct(RegistrationProduct $product): array
    {
        return [
            'id'                        => $product->id,
            'name'                      => $product->name,
            'slug'                      => $product->slug,
            'description'               => $product->description,
            'price'                     => (float) $product->price,
            'image_url'                 => $product->image_url,
            'published'                 => $product->published,
            'max_uses'                  => $product->max_uses,
            'uses_count'                => $product->uses_count,
            'available'                 => $product->isAvailable(),
            'success_url'               => $product->success_url,
            'cancel_url'                => $product->cancel_url,
            'phone_requirement'         => $product->phone_requirement,
            'address_requirement'       => $product->address_requirement,
            'billing_info_requirement'  => $product->billing_info_requirement,
            'recurrente_product_id'     => $product->recurrente_product_id,
            'recurrente_price_id'       => $product->recurrente_price_id,
            'synced_with_recurrente'    => $product->isSyncedWithRecurrente(),
            'metadata'                  => $product->metadata,
            'created_at'                => $product->created_at?->toISOString(),
            'updated_at'                => $product->updated_at?->toISOString(),
        ];
    }
}
