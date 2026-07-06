<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\Client;
use App\Services\FingerprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AccessLogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = AccessLog::with('client');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('verification_method')) {
            $query->where('verification_method', $request->verification_method);
        }

        if ($request->filled('access_type')) {
            $query->where('access_type', $request->access_type);
        }

        $tz = 'America/Guatemala';
        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->date_from, $tz)->startOfDay()->utc();
            $query->where('access_time', '>=', $from);
        }

        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->date_to, $tz)->endOfDay()->utc();
            $query->where('access_time', '<=', $to);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->whereHas('client', function ($clientQuery) use ($search) {
                $clientQuery->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%");
            });
        }

        $query->orderBy('access_time', 'desc');

        if ($request->filled('limit')) {
            $limit = min((int)$request->limit, 100);
            $logs = $query->limit($limit)->get();
        } else {
            $logs = $query->paginate($request->integer('per_page', 15));
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
    /**
     * 1:N fingerprint identification — receive a live-captured template from the
     * browser WebSDK, find the matching client, log and return their data.
     * This public endpoint powers the passive Access Scanner on the frontend.
     */
    public function identifyFingerprint(Request $request)
    {
        $validated = $request->validate([
            'fingerprint_template' => 'required|string',
        ]);

        $service   = new FingerprintService();
        $result    = $service->identifyFingerprint($validated['fingerprint_template']);

        if (!$result['success']) {
            // Python server not available — return graceful no-match (don't crash UI)
            return response()->json([
                'match'   => false,
                'client'  => null,
                'message' => 'Servidor de huellas no disponible.',
                'error'   => $result['error'] ?? null,
            ]);
        }

        $data = $result['data'];

        if (!($data['match'] ?? false)) {
            \Illuminate\Support\Facades\Log::info('Fingerprint identify attempt (no match)', [
                'decision' => $data['status'] ?? 'reject',
                'reason' => $data['decision_reason'] ?? null,
                'best_score' => $data['best_score'] ?? null,
                'second_best_score' => $data['second_best_score'] ?? null,
                'gap' => $data['gap'] ?? null,
                'quality_score' => $data['quality_score'] ?? null,
                'blur_score' => $data['blur_score'] ?? null,
                'is_second_scan' => $data['is_second_scan'] ?? false,
                'candidate_id' => $data['candidate_id'] ?? null,
            ]);
            return response()->json([
                'match'         => false,
                'client'        => null,
                'status'        => $data['status'] ?? 'reject',
                'decision_reason' => $data['decision_reason'] ?? null,
                'similarity_pct' => $data['similarity_pct'] ?? 0,
                'best_score'    => $data['best_score'] ?? null,
                'second_best_score' => $data['second_best_score'] ?? null,
                'gap'           => $data['gap'] ?? null,
                'quality_score' => $data['quality_score'] ?? null,
                'blur_score'    => $data['blur_score'] ?? null,
                'confirm_window_sec' => $data['confirm_window_sec'] ?? null,
                'candidate_name' => $data['candidate_name'] ?? null,
                'candidate_id'   => $data['candidate_id'] ?? null,
                'message'       => $data['message'] ?? 'No se encontró coincidencia.',
            ]);
        }

        $clientId = $data['client_id'] ?? null;
        $client   = $clientId ? Client::with(['memberships' => function ($q) {
            $q->orderBy('end_date', 'desc');
        }])->find($clientId) : null;

        if (!$client) {
            return response()->json([
                'match'   => false,
                'client'  => null,
                'message' => 'Cliente no encontrado en la base de datos.',
            ]);
        }

        $allowed = $this->checkClientAccess($client);

        // Log the access event
        AccessLog::create([
            'client_id'           => $client->id,
            'access_type'         => 'entry',
            'verification_method' => 'fingerprint',
            'qr_code'             => '',
            'fingerprint_id'      => $client->fingerprint_id,
            'access_time'         => now(),
            'status'              => $allowed ? 'allowed' : 'denied',
            'notes'               => json_encode([
                'flow' => 'identify_1n',
                'decision' => $data['status'] ?? 'accept',
                'reason' => $data['decision_reason'] ?? null,
                'similarity_pct' => $data['similarity_pct'] ?? 0,
                'best_score' => $data['best_score'] ?? null,
                'second_best_score' => $data['second_best_score'] ?? null,
                'gap' => $data['gap'] ?? null,
                'quality_score' => $data['quality_score'] ?? null,
                'blur_score' => $data['blur_score'] ?? null,
                'is_second_scan' => $data['is_second_scan'] ?? false,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return response()->json([
            'match'          => true,
            'status'         => $data['status'] ?? 'accept',
            'decision_reason' => $data['decision_reason'] ?? null,
            'allowed'        => $allowed,
            'similarity_pct' => $data['similarity_pct'] ?? 0,
            'best_score'     => $data['best_score'] ?? null,
            'second_best_score' => $data['second_best_score'] ?? null,
            'gap'            => $data['gap'] ?? null,
            'quality_score'  => $data['quality_score'] ?? null,
            'blur_score'     => $data['blur_score'] ?? null,
            'client'         => $client,
            'message'        => $allowed
                ? "¡Bienvenido/a {$client->first_name}!"
                : "Membresía vencida — {$client->full_name}",
        ]);
    }

    /**
     * Log a fingerprint access event whose 1:N matching was already performed
     * locally by the Python bridge server (localhost:8089).
     *
     * The frontend calls the Python server directly to avoid the remote-server
     * cURL error, then sends the matched client_id here so we can record the
     * access log and return full client + membership data.
     *
     * POST /access/log-fingerprint-access
     * Body: { client_id, similarity_pct?, fingerprint_id? }
     */
    public function logFingerprintAccess(Request $request)
    {
        $validated = $request->validate([
            'client_id'              => 'required|integer',
            'similarity_pct'         => 'nullable|integer',
            'fingerprint_id'         => 'nullable|string',
            'match_token'            => 'nullable|string',
            'winning_fingerprint_id' => 'nullable|string',
        ]);

        $client = Client::with(['memberships' => function ($q) {
            $q->orderBy('end_date', 'desc');
        }])->find($validated['client_id']);

        if (!$client) {
            return response()->json([
                'match'   => false,
                'client'  => null,
                'message' => 'Cliente no encontrado en la base de datos.',
            ], 404);
        }

        $winningFp = $validated['winning_fingerprint_id']
            ?? $validated['fingerprint_id']
            ?? null;

        if ($winningFp && ! $this->clientOwnsFingerprint($client, $winningFp)) {
            \Illuminate\Support\Facades\Log::warning('Fingerprint access rejected: fp_id mismatch', [
                'client_id' => $client->id,
                'fingerprint_id' => $winningFp,
            ]);

            return response()->json([
                'match'   => false,
                'client'  => null,
                'message' => 'La huella identificada no corresponde a este cliente.',
            ], 422);
        }

        $secret = config('services.fingerprint.match_secret');
        if ($secret) {
            if (empty($validated['match_token']) || ! $this->verifyFingerprintMatchToken(
                $validated['match_token'],
                (int) $client->id,
                (string) ($winningFp ?? $client->fingerprint_id ?? ''),
                (int) ($validated['similarity_pct'] ?? 0),
            )) {
                return response()->json([
                    'match'   => false,
                    'client'  => null,
                    'message' => 'Token de identificación inválido o expirado. Vuelve a escanear.',
                ], 422);
            }
        }

        $allowed = $this->checkClientAccess($client);

        AccessLog::create([
            'client_id'           => $client->id,
            'access_type'         => 'entry',
            'verification_method' => 'fingerprint',
            'qr_code'             => '',
            'fingerprint_id'      => $winningFp ?? $client->fingerprint_id ?? '',
            'access_time'         => now(),
            'status'              => $allowed ? 'allowed' : 'denied',
            'notes'               => json_encode([
                'flow'             => 'identify_local_python',
                'similarity_pct'   => $validated['similarity_pct'] ?? 0,
                'winning_fp_id'    => $winningFp,
                'match_token_used' => ! empty($validated['match_token']),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return response()->json([
            'match'          => true,
            'allowed'        => $allowed,
            'similarity_pct' => $validated['similarity_pct'] ?? 0,
            'client'         => $client,
            'message'        => $allowed
                ? "¡Bienvenido/a {$client->first_name}!"
                : "Membresía vencida — {$client->full_name}",
        ]);
    }

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

    /**
     * Verifica que fingerprint_id pertenezca al cliente (principal o extras).
     */
    private function clientOwnsFingerprint(Client $client, string $fingerprintId): bool
    {
        if ($client->fingerprint_id === $fingerprintId) {
            return true;
        }

        return DB::table('fingerprint_extra_templates')
            ->where('client_id', $client->id)
            ->where('fingerprint_id', $fingerprintId)
            ->exists();
    }

    /**
     * Valida match_token HMAC emitido por fingerprint-server (FP_MATCH_SECRET).
     */
    private function verifyFingerprintMatchToken(
        string $token,
        int $clientId,
        string $fingerprintId,
        int $similarityPct,
    ): bool {
        $secret = config('services.fingerprint.match_secret');
        if (! $secret || $fingerprintId === '') {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0])) {
            return false;
        }

        $ts = (int) $parts[0];
        $sig = $parts[1];
        $ttl = (int) config('services.fingerprint.match_token_ttl', 30);

        if (abs(time() - $ts) > $ttl) {
            return false;
        }

        $message = sprintf('%d|%s|%d|%d', $clientId, $fingerprintId, $similarityPct, $ts);
        $expected = hash_hmac('sha256', $message, $secret);

        return hash_equals($expected, $sig);
    }
}
