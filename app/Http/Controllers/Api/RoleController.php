<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Role::with('permissions')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:roles',
            'slug' => 'nullable|string|unique:roles',
            'description' => 'nullable|string',
            'permissions' => 'array'
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        }

        $role = Role::create($validated);

        if (isset($validated['permissions'])) {
            // Convert slugs to IDs if necessary
            $permissionIds = \App\Models\Permission::whereIn('slug', $validated['permissions'])
                ->orWhereIn('id', $validated['permissions'])
                ->pluck('id');
            $role->permissions()->sync($permissionIds);
        }

        return response()->json($role->load('permissions'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role)
    {
        return response()->json($role->load('permissions'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|unique:roles,name,' . $role->id,
            'slug' => 'sometimes|nullable|string|unique:roles,slug,' . $role->id,
            'description' => 'nullable|string',
            'permissions' => 'array'
        ]);

        if (isset($validated['name']) && empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        }

        $role->update($validated);

        if (isset($validated['permissions'])) {
            // Convert slugs to IDs if necessary
            $permissionIds = \App\Models\Permission::whereIn('slug', $validated['permissions'])
                ->orWhereIn('id', $validated['permissions'])
                ->pluck('id');
            $role->permissions()->sync($permissionIds);
        }

        return response()->json($role->load('permissions'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role)
    {
        $role->delete();
        return response()->json(null, 204);
    }
}
