<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipPlan;
use App\Services\RecurrenteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MembershipPlanController extends Controller
{
    public function __construct(private RecurrenteService $recurrente)
    {}
    /**
     * Obtener planes publicados (público, sin autenticación)
     */
    public function publicPlans()
    {
        $plans = MembershipPlan::where('published', true)
            ->orderBy('price', 'asc')
            ->get()
            ->map(fn ($plan) => $this->formatPlan($plan));

        return response()->json($plans);
    }

    /**
     * Obtener un plan publicado por slug (público, sin autenticación)
     */
    public function publicPlanBySlug(string $slug)
    {
        $plan = MembershipPlan::where('slug', $slug)
            ->where('published', true)
            ->firstOrFail();

        return response()->json($this->formatPlan($plan));
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = MembershipPlan::query();

        if ($request->has('published')) {
            $query->where('published', $request->boolean('published'));
        }

        $plans = $query->orderBy('price', 'asc')->get()
            ->map(fn ($plan) => $this->formatPlan($plan));

        return response()->json($plans);
    }

    /**
     * Store a newly created resource in storage.
     *
     * Crea el plan localmente Y en Recurrente como producto de suscripción.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'slug'          => 'nullable|string',
            'plan_type'     => 'sometimes|in:membership,personal_training,nutrition,course,other',
            'price'         => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
            'description'   => 'nullable|string',
            'features'      => 'nullable|array',
            'published'     => 'boolean',
        ]);

        // Generar slug si no viene en el request
        $slug = !empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);

        // Asegurar unicidad del slug (contando también soft-deleted)
        $baseSlug = $slug;
        $counter  = 1;
        while (MembershipPlan::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }
        $validated['slug'] = $slug;

        return DB::transaction(function () use ($validated) {
            // 1. Crear plan en BD local
            $plan = MembershipPlan::create($validated);

            // 2. Calcular intervalo de billing basado en duration_days
            $billingInterval      = $this->resolveBillingInterval($plan->duration_days);
            $billingIntervalCount = $this->resolveBillingIntervalCount($plan->duration_days);

            // 3. Crear producto en Recurrente como suscripción
            try {
                $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

                $recurrentePayload = [
                    'product' => [
                        'name'        => $plan->name,
                        'description' => $plan->description ?? "Plan de membresía: {$plan->name}",
                        'success_url' => "{$frontendUrl}/pagos/exitoso",
                        'cancel_url'  => "{$frontendUrl}/pagos/cancelado",
                        'phone_requirement'        => 'none',
                        'address_requirement'      => 'none',
                        'billing_info_requirement' => 'none',
                        'prices_attributes' => [
                            [
                                'amount_in_cents'    => RecurrenteService::toCents((float) $plan->price),
                                'currency'           => 'GTQ',
                                'charge_type'        => 'recurring',
                                'billing_interval'       => $billingInterval,
                                'billing_interval_count' => $billingIntervalCount,
                            ],
                        ],
                    ],
                ];

                Log::info('[MembershipPlan] Creando producto en Recurrente', [
                    'plan_id' => $plan->id,
                    'payload' => $recurrentePayload,
                ]);

                $response = $this->recurrente->createProduct($recurrentePayload);

                // Guardar IDs de Recurrente en el plan
                $plan->update([
                    'recurrente_product_id' => $response['id'] ?? null,
                    'recurrente_price_id'   => $response['prices'][0]['id'] ?? null,
                ]);

                Log::info('[MembershipPlan] ✅ Producto creado en Recurrente', [
                    'plan_id'              => $plan->id,
                    'recurrente_product_id' => $response['id'] ?? null,
                    'recurrente_price_id'   => $response['prices'][0]['id'] ?? null,
                ]);

            } catch (\Exception $e) {
                // No revertir la creación local — el plan queda sin sincronizar
                // Se puede sincronizar manualmente después
                Log::error('[MembershipPlan] ❌ Error al crear producto en Recurrente', [
                    'plan_id' => $plan->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            return response()->json($this->formatPlan($plan->fresh()), 201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $plan = MembershipPlan::findOrFail($id);
        return response()->json($this->formatPlan($plan));
    }

    /**
     * Update the specified resource in storage.
     *
     * Actualiza el plan localmente Y en Recurrente (nombre, precio).
     */
    public function update(Request $request, string $id)
    {
        $plan = MembershipPlan::findOrFail($id);

        $validated = $request->validate([
            'name'          => 'sometimes|required|string|max:255',
            'slug'          => 'sometimes|string',
            'plan_type'     => 'sometimes|in:membership,personal_training,nutrition,course,other',
            'price'         => 'sometimes|required|numeric|min:0',
            'duration_days' => 'sometimes|required|integer|min:1',
            'description'   => 'nullable|string',
            'features'      => 'nullable|array',
            'published'     => 'boolean',
        ]);

        // Si viene slug, asegurar unicidad excluyendo el propio registro
        if (isset($validated['slug'])) {
            $slug     = Str::slug($validated['slug']);
            $baseSlug = $slug;
            $counter  = 1;
            while (MembershipPlan::withTrashed()->where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $validated['slug'] = $slug;
        }

        // 1. Actualizar localmente
        $plan->update($validated);

        // 2. Sincronizar con Recurrente si el producto existe allá
        if ($plan->recurrente_product_id) {
            try {
                $recurrentePayload = ['product' => []];

                // Actualizar nombre si cambió
                if (isset($validated['name'])) {
                    $recurrentePayload['product']['name'] = $validated['name'];
                }

                // Actualizar descripción si cambió
                if (array_key_exists('description', $validated)) {
                    $recurrentePayload['product']['description'] = $validated['description'] ?? '';
                }

                // Actualizar precio si cambió (requiere price_id)
                if (isset($validated['price']) && $plan->recurrente_price_id) {
                    $recurrentePayload['product']['prices_attributes'] = [
                        [
                            'id'              => $plan->recurrente_price_id,
                            'amount_in_cents' => RecurrenteService::toCents((float) $validated['price']),
                        ],
                    ];
                }

                // Solo enviar si hay algo que actualizar
                if (!empty($recurrentePayload['product'])) {
                    Log::info('[MembershipPlan] Actualizando producto en Recurrente', [
                        'plan_id'    => $plan->id,
                        'product_id' => $plan->recurrente_product_id,
                        'payload'    => $recurrentePayload,
                    ]);

                    $response = $this->recurrente->updateProduct(
                        $plan->recurrente_product_id,
                        $recurrentePayload
                    );

                    // Actualizar price_id por si cambió
                    if (isset($response['prices'][0]['id'])) {
                        $plan->update(['recurrente_price_id' => $response['prices'][0]['id']]);
                    }

                    Log::info('[MembershipPlan] ✅ Producto actualizado en Recurrente', [
                        'plan_id'    => $plan->id,
                        'product_id' => $plan->recurrente_product_id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('[MembershipPlan] ❌ Error al actualizar producto en Recurrente', [
                    'plan_id'    => $plan->id,
                    'product_id' => $plan->recurrente_product_id,
                    'error'      => $e->getMessage(),
                ]);
                // No fallar — el plan local ya se actualizó
            }
        }

        return response()->json($this->formatPlan($plan->fresh()));
    }

    /**
     * Remove the specified resource from storage.
     *
     * Elimina el plan localmente Y el producto asociado en Recurrente.
     */
    public function destroy(string $id)
    {
        $plan = MembershipPlan::findOrFail($id);

        // 1. Cancelar suscripciones activas vinculadas a este plan
        $activeSubscriptions = \App\Models\RecurrenteSubscription::where('membership_plan_id', $plan->id)
            ->whereIn('status', ['active', 'past_due', 'trialing'])
            ->get();

        foreach ($activeSubscriptions as $subscription) {
            if ($subscription->recurrente_subscription_id) {
                try {
                    $this->recurrente->cancelSubscription($subscription->recurrente_subscription_id);
                    $subscription->update(['status' => 'canceled']);
                    Log::info('[MembershipPlan] Suscripción cancelada en Recurrente por eliminación de plan', [
                        'plan_id' => $plan->id,
                        'subscription_id' => $subscription->recurrente_subscription_id
                    ]);
                } catch (\Exception $e) {
                    Log::error('[MembershipPlan] Error cancelando suscripción en Recurrente por eliminación de plan', [
                        'plan_id' => $plan->id,
                        'subscription_id' => $subscription->recurrente_subscription_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // 2. Eliminar producto en Recurrente si existe
        if ($plan->recurrente_product_id) {
            try {
                Log::info('[MembershipPlan] Eliminando producto en Recurrente', [
                    'plan_id'    => $plan->id,
                    'product_id' => $plan->recurrente_product_id,
                ]);

                $this->recurrente->deleteProduct($plan->recurrente_product_id);

                Log::info('[MembershipPlan] ✅ Producto eliminado en Recurrente', [
                    'plan_id'    => $plan->id,
                    'product_id' => $plan->recurrente_product_id,
                ]);
            } catch (\Exception $e) {
                // Si ya estaba eliminado o no existe, solo logear
                Log::warning('[MembershipPlan] ⚠ Error al eliminar producto en Recurrente (continuando)', [
                    'plan_id'    => $plan->id,
                    'product_id' => $plan->recurrente_product_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // 3. Soft delete local
        $plan->delete();

        return response()->json(['message' => 'Plan eliminado correctamente']);
    }

    /**
     * Toggle the published status of a plan.
     */
    public function togglePublished(string $id)
    {
        $plan = MembershipPlan::findOrFail($id);
        $plan->published = !$plan->published;
        $plan->save();

        return response()->json($this->formatPlan($plan));
    }

    /**
     * Get a plan by its slug.
     */
    public function getBySlug(string $slug)
    {
        $plan = MembershipPlan::where('slug', $slug)->firstOrFail();

        return response()->json($this->formatPlan($plan));
    }

    // ─────────────────────────────────────────────────────────────
    //  SINCRONIZACIÓN CON RECURRENTE
    // ─────────────────────────────────────────────────────────────

    /**
     * Sincronizar un plan específico con Recurrente.
     * POST /api/membership-plans/{id}/sync-recurrente
     *
     * Útil si el plan se creó antes de configurar las keys,
     * o si la sincronización falló al crear.
     */
    public function syncToRecurrente(string $id)
    {
        $plan = MembershipPlan::findOrFail($id);

        // Si ya tiene producto en Recurrente, obtener estado actual
        if ($plan->recurrente_product_id) {
            try {
                $existing = $this->recurrente->getProduct($plan->recurrente_product_id);
                return response()->json([
                    'message'        => 'Plan ya sincronizado con Recurrente',
                    'plan_id'        => $plan->id,
                    'recurrente'     => $existing,
                    'already_synced' => true,
                ]);
            } catch (\Exception $e) {
                // Producto no existe en Recurrente, recrear
                Log::warning('[MembershipPlan] Producto no encontrado en Recurrente, recreando', [
                    'plan_id'    => $plan->id,
                    'product_id' => $plan->recurrente_product_id,
                ]);
            }
        }

        // Crear producto en Recurrente
        $billingInterval      = $this->resolveBillingInterval($plan->duration_days);
        $billingIntervalCount = $this->resolveBillingIntervalCount($plan->duration_days);
        $frontendUrl          = config('app.frontend_url', 'http://localhost:5173');

        $response = $this->recurrente->createProduct([
            'product' => [
                'name'        => $plan->name,
                'description' => $plan->description ?? "Plan de membresía: {$plan->name}",
                'success_url' => "{$frontendUrl}/pagos/exitoso",
                'cancel_url'  => "{$frontendUrl}/pagos/cancelado",
                'phone_requirement'        => 'none',
                'address_requirement'      => 'none',
                'billing_info_requirement' => 'none',
                'prices_attributes' => [
                    [
                        'amount_in_cents'        => RecurrenteService::toCents((float) $plan->price),
                        'currency'               => 'GTQ',
                        'charge_type'            => 'recurring',
                        'billing_interval'       => $billingInterval,
                        'billing_interval_count' => $billingIntervalCount,
                    ],
                ],
            ],
        ]);

        $plan->update([
            'recurrente_product_id' => $response['id'] ?? null,
            'recurrente_price_id'   => $response['prices'][0]['id'] ?? null,
        ]);

        return response()->json([
            'message'               => '✅ Plan sincronizado con Recurrente',
            'plan_id'               => $plan->id,
            'recurrente_product_id' => $plan->recurrente_product_id,
            'recurrente_price_id'   => $plan->recurrente_price_id,
            'recurrente'            => $response,
        ]);
    }

    /**
     * Sincronizar TODOS los planes no sincronizados.
     * POST /api/membership-plans/sync-all-recurrente
     */
    public function syncAllToRecurrente()
    {
        $plans = MembershipPlan::whereNull('recurrente_product_id')->get();

        if ($plans->isEmpty()) {
            return response()->json([
                'message' => 'Todos los planes ya están sincronizados',
                'synced'  => 0,
            ]);
        }

        $results = [];
        $synced  = 0;
        $errors  = 0;

        foreach ($plans as $plan) {
            try {
                $billingInterval      = $this->resolveBillingInterval($plan->duration_days);
                $billingIntervalCount = $this->resolveBillingIntervalCount($plan->duration_days);
                $frontendUrl          = config('app.frontend_url', 'http://localhost:5173');

                $response = $this->recurrente->createProduct([
                    'product' => [
                        'name'        => $plan->name,
                        'description' => $plan->description ?? "Plan de membresía: {$plan->name}",
                        'success_url' => "{$frontendUrl}/pagos/exitoso",
                        'cancel_url'  => "{$frontendUrl}/pagos/cancelado",
                        'phone_requirement'        => 'none',
                        'address_requirement'      => 'none',
                        'billing_info_requirement' => 'none',
                        'prices_attributes' => [
                            [
                                'amount_in_cents'        => RecurrenteService::toCents((float) $plan->price),
                                'currency'               => 'GTQ',
                                'charge_type'            => 'recurring',
                                'billing_interval'       => $billingInterval,
                                'billing_interval_count' => $billingIntervalCount,
                            ],
                        ],
                    ],
                ]);

                $plan->update([
                    'recurrente_product_id' => $response['id'] ?? null,
                    'recurrente_price_id'   => $response['prices'][0]['id'] ?? null,
                ]);

                $results[] = [
                    'plan_id' => $plan->id,
                    'name'    => $plan->name,
                    'status'  => 'ok',
                    'recurrente_product_id' => $response['id'] ?? null,
                ];
                $synced++;

            } catch (\Exception $e) {
                $results[] = [
                    'plan_id' => $plan->id,
                    'name'    => $plan->name,
                    'status'  => 'error',
                    'error'   => $e->getMessage(),
                ];
                $errors++;
            }
        }

        return response()->json([
            'message' => "Sincronización completada: {$synced} OK, {$errors} errores",
            'synced'  => $synced,
            'errors'  => $errors,
            'results' => $results,
        ]);
    }

    /**
     * Obtener estado de Recurrente para un plan.
     * GET /api/membership-plans/{id}/recurrente-status
     */
    public function recurrenteStatus(string $id)
    {
        $plan = MembershipPlan::findOrFail($id);

        if (! $plan->recurrente_product_id) {
            return response()->json([
                'synced'   => false,
                'plan_id'  => $plan->id,
                'message'  => 'Plan no sincronizado con Recurrente',
            ]);
        }

        try {
            $product = $this->recurrente->getProduct($plan->recurrente_product_id);

            return response()->json([
                'synced'                => true,
                'plan_id'               => $plan->id,
                'recurrente_product_id' => $plan->recurrente_product_id,
                'recurrente_price_id'   => $plan->recurrente_price_id,
                'recurrente_status'     => $product['status'] ?? 'unknown',
                'recurrente_product'    => $product,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'synced'                => false,
                'plan_id'               => $plan->id,
                'recurrente_product_id' => $plan->recurrente_product_id,
                'error'                 => $e->getMessage(),
                'message'               => 'Producto existe en BD pero no se pudo verificar en Recurrente',
            ], 502);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  HELPERS PRIVADOS
    // ─────────────────────────────────────────────────────────────

    /**
     * Mapear duration_days → billing_interval de Recurrente.
     */
    private function resolveBillingInterval(int $durationDays): string
    {
        return match (true) {
            $durationDays <= 14  => 'week',
            $durationDays <= 180 => 'month',
            default              => 'year',
        };
    }

    /**
     * Mapear duration_days → billing_interval_count de Recurrente.
     */
    private function resolveBillingIntervalCount(int $durationDays): int
    {
        return match (true) {
            $durationDays <= 7   => 1,         // 1 semana
            $durationDays <= 14  => 2,         // 2 semanas
            $durationDays <= 31  => 1,         // 1 mes
            $durationDays <= 62  => 2,         // 2 meses
            $durationDays <= 93  => 3,         // 3 meses (trimestral)
            $durationDays <= 186 => 6,         // 6 meses (semestral)
            $durationDays <= 366 => 1,         // 1 año
            default              => 1,
        };
    }

    /**
     * Formato estándar para respuestas de plan (incluye campos Recurrente).
     */
    private function formatPlan(MembershipPlan $plan): array
    {
        return [
            'id'                     => (string) $plan->id,
            'name'                   => $plan->name,
            'slug'                   => $plan->slug,
            'plan_type'              => $plan->plan_type ?? 'membership',
            'plan_type_label'        => \App\Models\MembershipPlan::TYPE_LABELS[$plan->plan_type ?? 'membership'] ?? 'Mensualidad',
            'price'                  => (float) $plan->price,
            'durationDays'           => $plan->duration_days,
            'description'            => $plan->description,
            'features'               => $plan->features ?? [],
            'published'              => $plan->published,
            'recurrente_product_id'  => $plan->recurrente_product_id,
            'recurrente_price_id'    => $plan->recurrente_price_id,
            'synced_with_recurrente' => ! is_null($plan->recurrente_product_id),
            'createdAt'              => $plan->created_at->toISOString(),
            'updatedAt'              => $plan->updated_at->toISOString(),
        ];
    }
}
