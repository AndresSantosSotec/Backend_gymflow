<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\Client;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AccessLogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = AccessLog::with('client');

        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('limit')) {
            $limit = min((int)$request->limit, 100);
            $logs = $query->orderBy('access_time', 'desc')->limit($limit)->get();
        } else {
            $logs = $query->orderBy('access_time', 'desc')->paginate($request->per_page ?? 15);
        }

        return response()->json($logs);
    }

    /**
     * Verify access by QR code (CRÍTICO)
     */
    public function verifyQR(Request $request)
    {
        $validated = $request->validate([
            'qr_code' => 'required|string',
        ]);

        $client = Client::where('qr_code', $validated['qr_code'])->first();

        if (!$client) {
            return response()->json([
                'allowed' => false,
                'client' => null,
                'message' => 'Código QR inválido',
            ], 404);
        }

        // Verificar si el cliente está activo y tiene membresía válida
        $allowed = $this->checkClientAccess($client);

        // Crear log de acceso
        $log = AccessLog::create([
            'client_id' => $client->id,
            'access_type' => 'entry',
            'verification_method' => 'qr',
            'qr_code' => $validated['qr_code'],
            'access_time' => now(),
            'status' => $allowed ? 'allowed' : 'denied',
            'notes' => $allowed ? 'Acceso permitido' : 'Membresía vencida o cliente inactivo',
        ]);

        return response()->json([
            'allowed' => $allowed,
            'client' => $client,
            'message' => $allowed ? '¡Acceso permitido! Bienvenido/a ' . $client->first_name : 'Acceso denegado - Membresía vencida',
            'log' => $log,
        ]);
    }

    /**
     * Verify access by fingerprint (CRÍTICO)
     */
    public function verifyFingerprint(Request $request)
    {
        $validated = $request->validate([
            'fingerprint_id' => 'required|string',
        ]);

        $client = Client::where('fingerprint_id', $validated['fingerprint_id'])->first();

        if (!$client) {
            return response()->json([
                'allowed' => false,
                'client' => null,
                'message' => 'Huella digital no registrada',
            ], 404);
        }

        // Verificar si el cliente está activo y tiene membresía válida
        $allowed = $this->checkClientAccess($client);

        // Crear log de acceso
        $log = AccessLog::create([
            'client_id' => $client->id,
            'access_type' => 'entry',
            'verification_method' => 'fingerprint',
            'qr_code' => '',
            'fingerprint_id' => $validated['fingerprint_id'],
            'access_time' => now(),
            'status' => $allowed ? 'allowed' : 'denied',
            'notes' => 'Verificación por huella digital',
        ]);

        return response()->json([
            'allowed' => $allowed,
            'client' => $client,
            'message' => $allowed ? '¡Acceso permitido! Bienvenido/a ' . $client->first_name : 'Acceso denegado - Membresía vencida',
            'log' => $log,
        ]);
    }

    /**
     * Check if client has valid access
     */
    private function checkClientAccess(Client $client): bool
    {
        // Verificar que el cliente esté activo
        if ($client->status !== 'active') {
            return false;
        }

        // Verificar que tenga una membresía activa válida
        $activeMembership = $client->memberships()
            ->where('status', 'active')
            ->where('end_date', '>=', Carbon::today())
            ->first();

        return $activeMembership !== null;
    }

    /**
     * Get recent logs
     */
    public function recent(Request $request)
    {
        $limit = min((int)($request->limit ?? 10), 100);

        $logs = AccessLog::with('client')
            ->orderBy('access_time', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($logs);
    }

    /**
     * Get logs by client
     */
    public function byClient(string $clientId)
    {
        $logs = AccessLog::where('client_id', $clientId)
            ->orderBy('access_time', 'desc')
            ->get();

        return response()->json($logs);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'access_type' => 'required|in:entry,exit',
            'qr_code' => 'nullable|string',
            'status' => 'required|in:allowed,denied',
            'notes' => 'nullable|string',
        ]);

        $validated['access_time'] = now();

        $log = AccessLog::create($validated);

        return response()->json($log->load('client'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $log = AccessLog::with('client')->findOrFail($id);
        return response()->json($log);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $log = AccessLog::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|in:allowed,denied',
            'notes' => 'nullable|string',
        ]);

        $log->update($validated);

        return response()->json($log->load('client'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $log = AccessLog::findOrFail($id);
        $log->delete();

        return response()->json(['message' => 'Access log deleted successfully']);
    }
}
