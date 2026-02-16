<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MembershipPlanController extends Controller
{
    /**
     * Obtener planes publicados (público, sin autenticación)
     */
    public function publicPlans()
    {
        $plans = MembershipPlan::where('published', true)
            ->orderBy('price', 'asc')
            ->get()
            ->map(function ($plan) {
                return [
                    'id' => (string) $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'price' => (float) $plan->price,
                    'durationDays' => $plan->duration_days,
                    'description' => $plan->description,
                    'features' => $plan->features ?? [],
                    'published' => $plan->published,
                    'createdAt' => $plan->created_at->toISOString(),
                    'updatedAt' => $plan->updated_at->toISOString(),
                ];
            });

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

        return response()->json([
            'id' => (string) $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price' => (float) $plan->price,
            'durationDays' => $plan->duration_days,
            'description' => $plan->description,
            'features' => $plan->features ?? [],
            'published' => $plan->published,
            'createdAt' => $plan->created_at->toISOString(),
            'updatedAt' => $plan->updated_at->toISOString(),
        ]);
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

        $plans = $query->orderBy('price', 'asc')->get();

        return response()->json($plans);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:membership_plans,slug',
            'price' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'features' => 'nullable|array',
            'published' => 'boolean',
        ]);

        if (!isset($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $plan = MembershipPlan::create($validated);

        return response()->json($plan, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $plan = MembershipPlan::findOrFail($id);
        return response()->json($plan);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $plan = MembershipPlan::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|string|unique:membership_plans,slug,' . $id,
            'price' => 'sometimes|required|numeric|min:0',
            'duration_days' => 'sometimes|required|integer|min:1',
            'description' => 'nullable|string',
            'features' => 'nullable|array',
            'published' => 'boolean',
        ]);

        $plan->update($validated);

        return response()->json($plan);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $plan = MembershipPlan::findOrFail($id);
        $plan->delete();

        return response()->json(['message' => 'Plan deleted successfully']);
    }

    /**
     * Toggle the published status of a plan.
     */
    public function togglePublished(string $id)
    {
        $plan = MembershipPlan::findOrFail($id);
        $plan->published = !$plan->published;
        $plan->save();

        return response()->json([
            'id' => (string) $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price' => (float) $plan->price,
            'durationDays' => $plan->duration_days,
            'description' => $plan->description,
            'features' => $plan->features ?? [],
            'published' => $plan->published,
            'createdAt' => $plan->created_at->toISOString(),
            'updatedAt' => $plan->updated_at->toISOString(),
        ]);
    }

    /**
     * Get a plan by its slug.
     */
    public function getBySlug(string $slug)
    {
        $plan = MembershipPlan::where('slug', $slug)->firstOrFail();

        return response()->json([
            'id' => (string) $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price' => (float) $plan->price,
            'durationDays' => $plan->duration_days,
            'description' => $plan->description,
            'features' => $plan->features ?? [],
            'published' => $plan->published,
            'createdAt' => $plan->created_at->toISOString(),
            'updatedAt' => $plan->updated_at->toISOString(),
        ]);
    }
}
