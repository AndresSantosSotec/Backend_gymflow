<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(User::with(['role.permissions', 'photos', 'documents'])->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'roleId' => 'nullable|exists:roles,id',
            'active' => 'boolean',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'birthDate' => 'nullable|date',
            'position' => 'nullable|string',
            'hireDate' => 'nullable|date',
            'salary' => 'nullable|numeric',
            'emergencyContact' => 'nullable|array',
            'notes' => 'nullable|string',
            'photo' => 'nullable|string',
            'photos' => 'nullable|array',
            'documents' => 'nullable|array',
            'cvUrl' => 'nullable|string',
            'fingerprintId' => 'nullable|string|unique:users',
            'fingerprintRegisteredAt' => 'nullable|date',
            'fingerprintData' => 'nullable|string',
        ]);

        $data = $validated;
        $data['password'] = Hash::make($validated['password']);
        
        // Map camelCase to snake_case
        if (isset($validated['roleId'])) $data['role_id'] = $validated['roleId'];
        if (isset($validated['birthDate'])) $data['birth_date'] = $validated['birthDate'];
        if (isset($validated['hireDate'])) $data['hire_date'] = $validated['hireDate'];
        if (isset($validated['cvUrl'])) $data['cv_url'] = $validated['cvUrl'];
        if (isset($validated['fingerprintId'])) $data['fingerprint_id'] = $validated['fingerprintId'];
        if (isset($validated['fingerprintRegisteredAt'])) $data['fingerprint_registered_at'] = $validated['fingerprintRegisteredAt'];
        if (isset($validated['fingerprintData'])) $data['fingerprint_template'] = $validated['fingerprintData'];

        // Map nested emergencyContact
        if (!empty($validated['emergencyContact'])) {
            if (isset($validated['emergencyContact']['name'])) $data['emergency_contact_name'] = $validated['emergencyContact']['name'];
            if (isset($validated['emergencyContact']['phone'])) $data['emergency_contact_phone'] = $validated['emergencyContact']['phone'];
            if (isset($validated['emergencyContact']['relationship'])) $data['emergency_contact_relationship'] = $validated['emergencyContact']['relationship'];
        }

        $user = User::create($data);

        // Handle photos in the new relational table
        if (isset($validated['photos']) && is_array($validated['photos'])) {
            foreach ($validated['photos'] as $photoUrl) {
                $user->photos()->create([
                    'url' => $photoUrl,
                    'type' => 'gallery'
                ]);
            }
        }

        // Handle documents in the new relational table
        if (isset($validated['documents']) && is_array($validated['documents'])) {
            foreach ($validated['documents'] as $doc) {
                $user->documents()->create([
                    'name' => $doc['name'] ?? 'document',
                    'url' => $doc['url'],
                    'type' => $doc['type'] ?? null,
                    'category' => $doc['category'] ?? 'general'
                ]);
            }
        }

        return response()->json($user->load(['role.permissions', 'photos', 'documents']), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return response()->json($user->load(['role.permissions', 'photos', 'documents']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'username' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|nullable|string|min:8',
            'roleId' => 'nullable|exists:roles,id',
            'active' => 'boolean',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'birthDate' => 'nullable|date',
            'position' => 'nullable|string',
            'hireDate' => 'nullable|date',
            'salary' => 'nullable|numeric',
            'emergencyContact' => 'nullable|array',
            'notes' => 'nullable|string',
            'photo' => 'nullable|string',
            'photos' => 'nullable|array',
            'documents' => 'nullable|array',
            'cvUrl' => 'nullable|string',
            'fingerprintId' => ['nullable', 'string', Rule::unique('users')->ignore($user->id)],
            'fingerprintRegisteredAt' => 'nullable|date',
            'fingerprintData' => 'nullable|string',
        ]);

        $data = $validated;
        if (isset($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }
        
        // Map camelCase to snake_case
        if (isset($validated['roleId'])) $data['role_id'] = $validated['roleId'];
        if (isset($validated['birthDate'])) $data['birth_date'] = $validated['birthDate'];
        if (isset($validated['hireDate'])) $data['hire_date'] = $validated['hireDate'];
        if (isset($validated['cvUrl'])) $data['cv_url'] = $validated['cvUrl'];
        if (isset($validated['fingerprintId'])) $data['fingerprint_id'] = $validated['fingerprintId'];
        if (isset($validated['fingerprintRegisteredAt'])) $data['fingerprint_registered_at'] = $validated['fingerprintRegisteredAt'];
        if (isset($validated['fingerprintData'])) $data['fingerprint_template'] = $validated['fingerprintData'];


        // Map nested emergencyContact
        if (!empty($validated['emergencyContact'])) {
            if (isset($validated['emergencyContact']['name'])) $data['emergency_contact_name'] = $validated['emergencyContact']['name'];
            if (isset($validated['emergencyContact']['phone'])) $data['emergency_contact_phone'] = $validated['emergencyContact']['phone'];
            if (isset($validated['emergencyContact']['relationship'])) $data['emergency_contact_relationship'] = $validated['emergencyContact']['relationship'];
        }

        $user->update($data);

        // Handle photos in the new relational table (sync)
        if (isset($validated['photos']) && is_array($validated['photos'])) {
            // Delete old photos that are not in the new list (or just replace all for simplicity in this demo)
            $user->photos()->delete();
            foreach ($validated['photos'] as $photoUrl) {
                $user->photos()->create([
                    'url' => $photoUrl,
                    'type' => 'gallery'
                ]);
            }
        }

        // Handle documents in the new relational table (sync)
        if (isset($validated['documents']) && is_array($validated['documents'])) {
            $user->documents()->delete();
            foreach ($validated['documents'] as $doc) {
                $user->documents()->create([
                    'name' => $doc['name'] ?? 'document',
                    'url' => $doc['url'],
                    'type' => $doc['type'] ?? null,
                    'category' => $doc['category'] ?? 'general'
                ]);
            }
        }

        return response()->json($user->load(['role.permissions', 'photos', 'documents']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(null, 204);
    }
}
