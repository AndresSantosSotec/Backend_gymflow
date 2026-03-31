<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\AccessLog;
use App\Services\FingerprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;


class ClientController extends Controller
{
    /**
     * Display a listing of the resource with advanced filtering.
     */
    public function index(Request $request)
    {
        $query = Client::query();

        // Búsqueda general
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('dni', 'like', "%{$search}%")
                  ->orWhere('qr_code', 'like', "%{$search}%");
            });
        }

        // Filtro por estado
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filtro: solo con membresía activa
        if ($request->boolean('active_membership')) {
            $query->withActiveMemership();
        }

        // Filtro: sin membresía / membresía vencida
        if ($request->boolean('expired_membership')) {
            $query->withExpiredMembership();
        }

        // Filtro: con huella digital
        if ($request->has('has_fingerprint')) {
            if ($request->boolean('has_fingerprint')) {
                $query->withFingerprint();
            } else {
                $query->withoutFingerprint();
            }
        }

        // Filtro por género
        if ($request->has('gender') && $request->gender) {
            $query->where('gender', $request->gender);
        }

        // Ordenamiento
        $sortBy = $request->sort_by ?? 'created_at';
        $sortDir = $request->sort_dir ?? 'desc';
        $allowedSorts = ['first_name', 'last_name', 'email', 'created_at', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir);
        }

        // Incluir relaciones opcionales
        $relations = ['memberships'];
        if ($request->boolean('with_payments')) {
            $relations[] = 'payments';
        }
        if ($request->boolean('with_access_logs')) {
            $relations[] = 'accessLogs';
        }

        $version = Cache::rememberForever('clients_v', function () {
            return time();
        });


        $cacheKey = 'clients_v' . $version . '_' . md5(json_encode($request->all()));

        $clients = Cache::remember($cacheKey, 60, function () use ($query, $relations, $request) {
            return $query->with($relations)->paginate($request->per_page ?? 15);
        });

        return response()->json($clients);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'phone_secondary' => 'nullable|string|max:20',
            'dni' => 'nullable|string|max:255',
            'nit' => 'nullable|string',
            'company_name' => 'nullable|string',
            'fiscal_address' => 'nullable|string',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:M,F,other',
            'address' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'notes' => 'nullable|string',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'weight_kg' => 'nullable|numeric|min:0|max:500',
            'height_cm' => 'nullable|numeric|min:0|max:300',
            'medical_conditions' => 'nullable|string',
            'referral_source' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            \Illuminate\Support\Facades\Log::error('Client Validation Failed', $validator->errors()->toArray());
        }

        $validated = $validator->validate();

        // Convert empty email to null
        if (isset($validated['email']) && trim($validated['email']) === '') {
            $validated['email'] = null;
        }

        $validated['qr_code'] = 'GYM-' . Str::upper(Str::random(10));
        $validated['status'] = 'active';

        $client = Client::create($validated);

        // Limpiar cache de listado de clientes
        $this->clearCache();

        return response()->json($client->load('memberships'), 201);
    }

    /**
     * Display the specified resource with all relationships.
     */
    public function show(string $id)
    {
        $client = Client::with([
            'memberships.plan',
            'payments' => function ($q) {
                $q->orderBy('created_at', 'desc')->limit(20);
            },
            'accessLogs' => function ($q) {
                $q->orderBy('access_time', 'desc')->limit(20);
            },
            'installments',
        ])->findOrFail($id);

        // Agregar estadísticas
        $client->stats = [
            'total_payments' => $client->payments()->sum('amount'),
            'total_visits' => $client->accessLogs()->where('status', 'allowed')->count(),
            'last_visit' => $client->accessLogs()->where('status', 'allowed')->orderBy('access_time', 'desc')->value('access_time'),
            'pending_installments' => $client->installments()->whereIn('status', ['pending', 'partial'])->count(),
            'overdue_installments' => $client->installments()->where('status', '!=', 'paid')->where('due_date', '<', now())->count(),
            'member_since_days' => $client->created_at->diffInDays(now()),
        ];

        return response()->json($client);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $client = Client::findOrFail($id);

        // Normalize data before validation
        $data = $request->all();
        if (isset($data['status'])) {
            $data['status'] = strtolower($data['status']);
        }
        // Convert empty strings to null for nullable fields BEFORE validation
        $preCastNullable = ['email', 'phone', 'phone_secondary', 'dni', 'nit', 'company_name',
                           'fiscal_address', 'birth_date', 'address', 'photo_url', 'notes',
                           'emergency_contact_name', 'emergency_contact_phone', 'medical_conditions',
                           'referral_source'];
        foreach ($preCastNullable as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) === '') {
                $data[$field] = null;
            }
        }

        $validator = \Illuminate\Support\Facades\Validator::make($data, [
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'phone_secondary' => 'nullable|string|max:20',
            'dni' => 'nullable|string|max:255',
            'nit' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'fiscal_address' => 'nullable|string',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:M,F,other',
            'address' => 'nullable|string',
            'photo_url' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive,suspended',
            'notes' => 'nullable|string',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'weight_kg' => 'nullable|numeric|min:0|max:500',
            'height_cm' => 'nullable|numeric|min:0|max:300',
            'medical_conditions' => 'nullable|string',
            'referral_source' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            \Illuminate\Support\Facades\Log::error('Client Update Validation Failed', [
                'client_id' => $id,
                'errors' => $validator->errors()->toArray(),
                'data' => $data
            ]);
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Convert empty strings to null for nullable fields
        $nullableFields = ['email', 'phone', 'phone_secondary', 'dni', 'nit', 'company_name',
                          'fiscal_address', 'birth_date', 'address', 'photo_url', 'notes',
                          'emergency_contact_name', 'emergency_contact_phone', 'medical_conditions',
                          'referral_source'];

        foreach ($nullableFields as $field) {
            if (isset($validated[$field]) && is_string($validated[$field]) && trim($validated[$field]) === '') {
                $validated[$field] = null;
            }
        }

        $client->update($validated);

        return response()->json($client->load('memberships'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $client = Client::findOrFail($id);
        $client->delete();

        return response()->json(['message' => 'Client deleted successfully']);
    }

    // ─── Métodos Especiales ───

    /**
     * Get client by QR code.
     */
    public function getByQR(string $qrCode)
    {
        $client = Client::where('qr_code', $qrCode)
            ->with('memberships')
            ->first();

        if (!$client) {
            return response()->json([
                'message' => 'Cliente no encontrado con ese código QR',
            ], 404);
        }

        return response()->json($client);
    }

    /**
     * Get client by DNI/DPI.
     */
    public function getByDni(string $dni)
    {
        $client = Client::where('dni', $dni)
            ->with('memberships')
            ->first();

        if (!$client) {
            return response()->json([
                'message' => 'Cliente no encontrado con ese DPI/DNI',
            ], 404);
        }

        return response()->json($client);
    }

    /**
     * Upload client photo.
     */
    public function uploadPhoto(Request $request, string $id)
    {
        $client = Client::findOrFail($id);

        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        // Delete old photo if exists
        if ($client->photo_url && Storage::disk('public')->exists($client->photo_url)) {
            Storage::disk('public')->delete($client->photo_url);
        }

        $path = $request->file('photo')->store('clients/photos', 'public');
        $client->update(['photo_url' => $path]);

        return response()->json([
            'message' => 'Foto actualizada exitosamente',
            'photo_url' => $path,
            'full_url' => asset('storage/' . $path),
            'client' => $client,
        ]);
    }

    /**
     * Remove client photo.
     */
    public function removePhoto(string $id)
    {
        $client = Client::findOrFail($id);

        if ($client->photo_url && Storage::disk('public')->exists($client->photo_url)) {
            Storage::disk('public')->delete($client->photo_url);
        }

        $client->update(['photo_url' => null]);

        return response()->json([
            'message' => 'Foto eliminada exitosamente',
            'client' => $client,
        ]);
    }

    // ─── Fingerprint Endpoints (Listos para integración con lector biométrico) ───

    /**
     * Register a fingerprint for a client.
     *
     * INTEGRACIÓN CON LECTOR BIOMÉTRICO:
     * 1. El frontend captura la huella con el SDK del lector (ej: DigitalPersona, SecuGen)
     * 2. El SDK genera un "template" (string binaria o base64)
     * 3. Se envía el template + metadata a este endpoint
     * 4. El backend almacena el template y lo registra con el servidor Java
     */
    public function registerFingerprint(Request $request, string $id)
    {
        $client = Client::findOrFail($id);

        // Si ya tiene huella, eliminarla automáticamente antes de re-registrar
        if ($client->fingerprint_id) {
            \Log::info("Auto-removing existing fingerprint for client {$id} before re-registration", [
                'old_fingerprint_id' => $client->fingerprint_id,
            ]);
            $fingerprintService = new FingerprintService();
            try {
                $fingerprintService->deleteFingerprintFromDevice($client->fingerprint_id);
            } catch (\Exception $e) {
                \Log::warning("Could not delete old fingerprint from device: {$e->getMessage()}");
            }
            \Illuminate\Support\Facades\DB::table('fingerprint_extra_templates')
                ->where('client_id', $client->id)
                ->delete();
            $client->removeFingerprint();
            $client->refresh();
        }

        $minEnrollmentSamples = (int) config('services.fingerprint.min_enrollment_samples', 6);
        $extrasRequired = max(0, $minEnrollmentSamples - 1);

        $validated = $request->validate([
            'fingerprint_template' => 'required|string',
            // v2: exactamente 5 extras para 6 muestras totales (compat: si min=4 en .env, ajusta)
            'extra_templates'      => 'required|array|size:' . $extrasRequired,
            'extra_templates.*'    => 'string',
            'quality_samples'      => 'nullable|array',
            'quality_samples.*'    => 'nullable|integer|min:0|max:100',
            'capture_variants'     => 'nullable|array',
            'capture_variants.*'   => 'nullable|string|max:64',
            'blur_samples'         => 'nullable|array',
            'blur_samples.*'       => 'nullable|integer|min:0|max:65535',
            'useful_area_samples'  => 'nullable|array',
            'useful_area_samples.*' => 'nullable|numeric|min:0|max:1',
            'captured_at_samples'  => 'nullable|array',
            'captured_at_samples.*' => 'nullable|date',
            'device_id'            => 'nullable|string|max:255',
            'quality'              => 'nullable|integer|min:0|max:100',
        ]);

        // ── Configuración de umbrales de enrolamiento (desde .env / defaults) ──
        $minEnrollmentQuality = (int) config('services.fingerprint.min_enrollment_quality', 40);

        // ── Construir lista completa de (template, quality, orden, variante) ────
        $allTemplates = array_merge(
            [$validated['fingerprint_template']],
            $validated['extra_templates'] ?? []
        );
        $qualitySamples = $validated['quality_samples'] ?? [];
        $captureVariants = $validated['capture_variants'] ?? [];
        $blurSamples = $validated['blur_samples'] ?? [];
        $usefulAreaSamples = $validated['useful_area_samples'] ?? [];
        $capturedAtSamples = $validated['captured_at_samples'] ?? [];

        $samplesWithQuality = [];
        foreach ($allTemplates as $i => $tpl) {
            $q = $qualitySamples[$i] ?? $validated['quality'] ?? null;
            $samplesWithQuality[] = [
                'template' => $tpl,
                'quality' => $q,
                'capture_order' => $i + 1,
                'capture_variant' => $captureVariants[$i] ?? null,
                'blur_score' => $blurSamples[$i] ?? null,
                'useful_area_ratio' => isset($usefulAreaSamples[$i]) ? (float) $usefulAreaSamples[$i] : null,
                'captured_at' => $capturedAtSamples[$i] ?? null,
            ];
        }

        // ── Rechazar muestras de calidad insuficiente ───────────────────────────
        $validSamples = array_filter(
            $samplesWithQuality,
            fn($s) => $s['quality'] === null || $s['quality'] >= $minEnrollmentQuality
        );
        $validSamples = array_values($validSamples);

        if (count($validSamples) < $minEnrollmentSamples) {
            return response()->json([
                'message'          => "Se requieren al menos {$minEnrollmentSamples} muestras de calidad suficiente (≥ {$minEnrollmentQuality}).",
                'samples_provided' => count($allTemplates),
                'samples_valid'    => count($validSamples),
                'min_required'     => $minEnrollmentSamples,
                'min_quality'      => $minEnrollmentQuality,
            ], 422);
        }

        // ── Elegir la mejor muestra como template principal (calidad compuesta) ─
        $scoreFn = static function (array $s): float {
            $q = (float) ($s['quality'] ?? 0);
            $blur = $s['blur_score'];
            if ($blur !== null && $blur > 0) {
                $q -= min(30.0, (float) $blur / 200.0 * 30.0);
            }

            return $q;
        };
        $bestIdx = 0;
        $bestScore = -PHP_FLOAT_MAX;
        foreach ($validSamples as $i => $s) {
            $sc = $scoreFn($s);
            if ($sc > $bestScore) {
                $bestScore = $sc;
                $bestIdx = $i;
            }
        }
        $primarySample = $validSamples[$bestIdx];
        $extraSamples = [];
        foreach ($validSamples as $i => $s) {
            if ($i !== $bestIdx) {
                $extraSamples[] = $s;
            }
        }
        usort($extraSamples, fn ($a, $b) => ($a['capture_order'] ?? 0) <=> ($b['capture_order'] ?? 0));

        // ── Sincronizar con servidor Python (timeout 3s; no bloqueante) ─────────
        $fingerprintService = new FingerprintService();
        $requestedDeviceId = $validated['device_id'] ?? $request->input('metadata.source') ?? null;
        $imageBase64 = $request->input('metadata.image_base64');
        $deviceResponse = $fingerprintService->registerFingerprintWithDevice(
            $client,
            $primarySample['template'],
            array_column($extraSamples, 'template'),
            $requestedDeviceId,
            $imageBase64
        );

        // ID: del servidor Python si lo devolvió, o generado aquí
        $fingerprintId = $deviceResponse['fingerprint_id']
            ?? 'FP-' . $client->id . '-' . now()->timestamp . '-' . Str::random(8);

        // ── Guardar template principal en clients ───────────────────────────────
        $client->registerFingerprint(
            $fingerprintId,
            $primarySample['template'],
            $validated['device_id'] ?? config('services.fingerprint.device_id', 'default'),
            $primarySample['quality'] ?? $deviceResponse['quality'] ?? null,
            2,
            count($validSamples),
            false,
        );

        // ── Guardar extras con metadata ─────────────────────────────────────────
        \Illuminate\Support\Facades\DB::table('fingerprint_extra_templates')
            ->where('client_id', $client->id)
            ->delete();

        if (!empty($extraSamples)) {
            $extraRows = [];
            foreach ($extraSamples as $idx => $sample) {
                $extraRows[] = [
                    'client_id' => $client->id,
                    'fingerprint_id' => $fingerprintId . '-e' . ($idx + 1),
                    'fingerprint_template' => $sample['template'],
                    'scan_index' => $idx + 1,
                    'quality' => $sample['quality'],
                    'capture_variant' => $sample['capture_variant'] ?? null,
                    'blur_score' => $sample['blur_score'] ?? null,
                    'useful_area_ratio' => $sample['useful_area_ratio'] ?? null,
                    'captured_at' => !empty($sample['captured_at'])
                        ? \Carbon\Carbon::parse($sample['captured_at'])
                        : now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            \Illuminate\Support\Facades\DB::table('fingerprint_extra_templates')->insert($extraRows);
        }

        \Illuminate\Support\Facades\Log::info('Fingerprint enrolled', [
            'client_id' => $client->id,
            'fingerprint_id' => $fingerprintId,
            'samples_total' => count($allTemplates),
            'samples_valid' => count($validSamples),
            'primary_quality' => $primarySample['quality'],
            'extras_count' => count($extraSamples),
            'python_responded' => !empty($deviceResponse['fingerprint_id']),
            'enrollment_version' => 2,
        ]);

        return response()->json([
            'message' => 'Huella digital registrada exitosamente',
            'fingerprint_id' => $fingerprintId,
            'registered_at' => $client->fingerprint_registered_at,
            'primary_quality' => $primarySample['quality'],
            'extras_count' => count($extraSamples),
            'samples_used' => count($validSamples),
            'primary_capture_order' => $primarySample['capture_order'] ?? null,
            'primary_capture_variant' => $primarySample['capture_variant'] ?? null,
            'enrollment_version' => 2,
            'fingerprint_legacy_enrollment' => false,
            'client' => $client,
        ], 201);
    }


    /**
     * Remove (delete) a client's fingerprint.
     */
    public function removeFingerprint(string $id)
    {
        $client = Client::findOrFail($id);

        if (!$client->fingerprint_id) {
            return response()->json([
                'message' => 'Este cliente no tiene huella digital registrada.',
            ], 422);
        }

        // Eliminar del dispositivo/servidor Java
        $fingerprintService = new FingerprintService();
        $deviceResponse = $fingerprintService->deleteFingerprintFromDevice($client->fingerprint_id);

        // Eliminar de la base de datos sin importar el resultado del dispositivo
        // (en caso de que el dispositivo esté desconectado)
        \Illuminate\Support\Facades\DB::table('fingerprint_extra_templates')
            ->where('client_id', $client->id)
            ->delete();
        $client->removeFingerprint();

        return response()->json([
            'message' => 'Huella digital eliminada exitosamente',
            'client' => $client,
        ]);
    }

    /**
     * Get fingerprint status for a client.
     */
    public function fingerprintStatus(string $id)
    {
        $client = Client::findOrFail($id);

        $extrasCount = \Illuminate\Support\Facades\DB::table('fingerprint_extra_templates')
            ->where('client_id', $client->id)
            ->count();

        return response()->json([
            'has_fingerprint' => $client->has_fingerprint,
            'fingerprint_id' => $client->fingerprint_id,
            'device_id' => $client->fingerprint_device_id,
            'quality' => $client->fingerprint_quality,
            'registered_at' => $client->fingerprint_registered_at,
            'fingerprint_enrollment_version' => $client->fingerprint_enrollment_version ?? 1,
            'fingerprint_sample_count' => $client->fingerprint_sample_count,
            'fingerprint_legacy_enrollment' => (bool) ($client->fingerprint_legacy_enrollment ?? false),
            'requires_reenrollment' => (bool) ($client->fingerprint_legacy_enrollment ?? false)
                || ($extrasCount > 0 && $extrasCount < 5 && $client->has_fingerprint),
            'extras_stored' => $extrasCount,
        ]);
    }

    // ─── Regeneración de QR ───

    /**
     * Regenerate QR code for a client.
     */
    public function regenerateQR(string $id)
    {
        $client = Client::findOrFail($id);

        $newQR = 'GYM-' . Str::upper(Str::random(10));
        $client->update(['qr_code' => $newQR]);

        return response()->json([
            'message' => 'Código QR regenerado exitosamente',
            'qr_code' => $newQR,
            'client' => $client,
        ]);
    }

    // ─── Statistics ───

    /**
     * Dashboard statistics for clients module.
     */
    public function statistics()
    {
        $today = Carbon::today();

        $totalClients = Client::count();
        $activeClients = Client::active()->count();
        $inactiveClients = Client::inactive()->count();
        $suspendedClients = Client::suspended()->count();

        // Clientes con membresía activa
        $withActiveMembership = Client::withActiveMemership()->count();

        // Clientes con huella registrada
        $withFingerprint = Client::withFingerprint()->count();
        $withoutFingerprint = Client::withoutFingerprint()->count();

        // Nuevos clientes este mes
        $newThisMonth = Client::whereMonth('created_at', $today->month)
            ->whereYear('created_at', $today->year)
            ->count();

        // Nuevos la semana pasada
        $newLastWeek = Client::whereBetween('created_at', [
            $today->copy()->subWeek()->startOfWeek(),
            $today->copy()->subWeek()->endOfWeek(),
        ])->count();

        // Membresías que vencen en los próximos 7 días
        $expiringMemberships = Client::whereHas('memberships', function ($q) use ($today) {
            $q->where('status', 'active')
              ->whereBetween('end_date', [$today, $today->copy()->addDays(7)]);
        })->count();

        // Distribución por género
        $genderDistribution = Client::selectRaw('gender, count(*) as count')
            ->whereNotNull('gender')
            ->groupBy('gender')
            ->pluck('count', 'gender');

        return response()->json([
            'total' => $totalClients,
            'active' => $activeClients,
            'inactive' => $inactiveClients,
            'suspended' => $suspendedClients,
            'with_active_membership' => $withActiveMembership,
            'with_fingerprint' => $withFingerprint,
            'without_fingerprint' => $withoutFingerprint,
            'new_this_month' => $newThisMonth,
            'new_last_week' => $newLastWeek,
            'expiring_memberships_7d' => $expiringMemberships,
            'gender_distribution' => $genderDistribution,
        ]);
    }

    /**
     * Toggle client status (active/inactive/suspended).
     */
    public function toggleStatus(Request $request, string $id)
    {
        $client = Client::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:active,inactive,suspended',
        ]);

        $client->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Estado actualizado a: ' . $validated['status'],
            'client' => $client->load('memberships'),
        ]);
    }

    /**
     * Get a lightweight list of clients with fingerprints for public simulation.
     */
    public function getFingerprintClients()
    {
        $clients = Client::whereNotNull('fingerprint_id')
            ->select('id', 'first_name', 'last_name', 'fingerprint_id', 'photo_url')
            ->get();

        return response()->json($clients);
    }

    private function clearCache()
    {
        Cache::put('clients_v', time());
    }
}
