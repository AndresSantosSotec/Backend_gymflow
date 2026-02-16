<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Lead::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $leads = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15);

        return response()->json($leads);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'status' => 'sometimes|in:new,contacted,interested,not_interested,converted',
            'notes' => 'nullable|string',
            'source' => 'nullable|string|max:255',
            'plan_slug' => 'nullable|string|max:255',
            'preferred_payment_method' => 'nullable|in:cash,card,transfer',
        ]);

        $validated['status'] = $validated['status'] ?? 'new';

        $lead = Lead::create($validated);

        return response()->json($lead, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $lead = Lead::findOrFail($id);
        return response()->json($lead);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $lead = Lead::findOrFail($id);

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
            'phone' => 'nullable|string|max:20',
            'status' => 'sometimes|in:new,contacted,interested,not_interested,converted',
            'notes' => 'nullable|string',
            'source' => 'nullable|string|max:255',
            'plan_slug' => 'nullable|string|max:255',
            'preferred_payment_method' => 'nullable|in:cash,card,transfer',
        ]);

        $lead->update($validated);

        return response()->json($lead);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $lead = Lead::findOrFail($id);
        $lead->delete();

        return response()->json(['message' => 'Lead deleted successfully']);
    }

    /**
     * Convert lead to client (CRÍTICO)
     */
    public function convertToClient(Request $request, string $id)
    {
        $lead = Lead::findOrFail($id);

        // Verificar que el lead no esté ya convertido
        if ($lead->status === 'converted') {
            return response()->json([
                'message' => 'Este lead ya fue convertido a cliente',
            ], 400);
        }

        // Verificar si ya existe un cliente con ese email
        $existingClient = Client::where('email', $lead->email)->first();
        if ($existingClient) {
            return response()->json([
                'message' => 'Ya existe un cliente con este email',
                'client' => $existingClient,
            ], 409);
        }

        // Validar datos adicionales opcionales
        $validated = $request->validate([
            'birth_date' => 'nullable|date',
            'address' => 'nullable|string|max:255',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'photo_url' => 'nullable|string|max:255',
        ]);

        // Crear cliente desde el lead
        $client = Client::create([
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'birth_date' => $validated['birth_date'] ?? null,
            'address' => $validated['address'] ?? null,
            'emergency_contact_name' => $validated['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? null,
            'notes' => $validated['notes'] ?? $lead->notes,
            'photo_url' => $validated['photo_url'] ?? null,
            'qr_code' => 'QR' . strtoupper(Str::random(8)),
            'status' => 'inactive', // Se activará al asignar membresía
        ]);

        // Actualizar el lead como convertido
        $lead->update([
            'status' => 'converted',
            'notes' => ($lead->notes ?? '') . "\nConvertido a cliente ID: {$client->id} el " . now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'message' => 'Lead convertido exitosamente a cliente',
            'client' => $client,
            'lead' => $lead,
        ], 201);
    }

    /**
     * Get statistics
     */
    public function statistics()
    {
        $total = Lead::count();
        $byStatus = Lead::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $conversionRate = $total > 0
            ? round(($byStatus['converted'] ?? 0) / $total * 100, 2)
            : 0;

        return response()->json([
            'total' => $total,
            'by_status' => $byStatus,
            'conversion_rate' => $conversionRate,
        ]);
    }
}
