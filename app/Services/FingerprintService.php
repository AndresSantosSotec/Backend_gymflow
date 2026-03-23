<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Client;
use Exception;

class FingerprintService
{
    /**
     * URL del servidor Java con el plugin de lectura de huella
     */
    private string $baseUrl;

    /**
     * Timeout para captura: espera a que el usuario coloque el dedo (largo).
     */
    private int $timeout = 33;

    /**
     * Timeout para operaciones de sincronización con el servidor Python
     * (register, delete, verify).  Si el servidor no está activo fallará
     * rápido y el guardado en BD continuará de inmediato.
     */
    private int $deviceSyncTimeout = 3;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->baseUrl = config('services.fingerprint.url', 'http://localhost:8089/api');
    }

    /**
     * Obtener el estado del dispositivo de huella digital
     */
    public function getDeviceStatus(): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/device/status");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Error connecting to fingerprint device',
                'status' => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error('Fingerprint device status error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Capturar huella digital del dispositivo
     */
    public function captureFingerprint(): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/fingerprint/capture");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()['error'] ?? 'Failed to capture fingerprint',
                'needs_admin' => $response->json()['needs_admin'] ?? false,
                'status' => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error('Fingerprint capture error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Registrar huella digital en el servidor Java
     */
    public function registerFingerprintWithDevice(Client $client, string $fingerprintTemplate, array $extraTemplates = []): array
    {
        try {
            $payload = [
                'client_id' => $client->id,
                'client_name' => $client->full_name,
                'fingerprint_template' => $fingerprintTemplate,
                'device_id' => config('services.fingerprint.device_id', 'default'),
            ];

            if (!empty($extraTemplates)) {
                $payload['extra_templates'] = array_values($extraTemplates);
            }

            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout($this->deviceSyncTimeout)
                ->post("{$this->baseUrl}/fingerprint/register", $payload);

            if ($response->successful()) {
                $data = $response->json();

                Log::info("Fingerprint registered for client {$client->id}", $data);

                return [
                    'success' => true,
                    'fingerprint_id' => $data['fingerprint_id'] ?? null,
                    'device_id' => $data['device_id'] ?? null,
                    'quality' => $data['quality'] ?? null,
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to register fingerprint with device',
                'status' => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error("Fingerprint registration error for client {$client->id}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verificar huella digital contra dispositivo
     */
    public function verifyFingerprintWithDevice(string $fingerprintId): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout($this->deviceSyncTimeout)
                ->post("{$this->baseUrl}/fingerprint/verify", [
                    'fingerprint_id' => $fingerprintId,
                    'device_id' => config('services.fingerprint.device_id', 'default'),
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'match' => $data['match'] ?? false,
                    'similarity' => $data['similarity'] ?? 0,
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'match' => false,
                'error' => 'Failed to verify fingerprint',
                'status' => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error("Fingerprint verification error: " . $e->getMessage());
            return [
                'success' => false,
                'match' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Eliminar huella digital del dispositivo
     */
    public function deleteFingerprintFromDevice(string $fingerprintId): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout($this->deviceSyncTimeout)
                ->delete("{$this->baseUrl}/fingerprint/{$fingerprintId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to delete fingerprint from device',
                'status' => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error("Fingerprint deletion error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Identificar a qué cliente pertenece una huella (búsqueda 1:N).
     * Llama al endpoint /api/fingerprint/identify del servidor Python,
     * que compara el template recibido contra todos los almacenados en SQLite.
     */
    public function identifyFingerprint(string $fingerprintTemplate, float $threshold = 0.50): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(10)
                ->post("{$this->baseUrl}/fingerprint/identify", [
                    'fingerprint_template' => $fingerprintTemplate,
                    'threshold'            => $threshold,
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error'   => 'Python server identify error: ' . $response->status(),
            ];
        } catch (Exception $e) {
            Log::warning("Fingerprint identify error: " . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Sincronizar todas las huellas registradas con el dispositivo
     */
    public function syncAllFingerprints(): array
    {
        try {
            $clients = Client::whereNotNull('fingerprint_id')->get();

            $synced = 0;
            $failed = 0;
            $errors = [];

            foreach ($clients as $client) {
                /** @var \Illuminate\Http\Client\Response $response */
                $response = Http::timeout($this->timeout)
                    ->post("{$this->baseUrl}/fingerprint/sync", [
                        'client_id' => $client->id,
                        'fingerprint_id' => $client->fingerprint_id,
                        'fingerprint_template' => $client->fingerprint_template,
                        'device_id' => config('services.fingerprint.device_id', 'default'),
                    ]);

                if ($response->successful()) {
                    $synced++;
                } else {
                    $failed++;
                    $errors[] = "Client {$client->id}: Status " . $response->status();
                }
            }

            Log::info("Fingerprint sync completed: {$synced} synced, {$failed} failed");

            return [
                'success' => $failed === 0,
                'synced' => $synced,
                'failed' => $failed,
                'errors' => $errors,
            ];
        } catch (Exception $e) {
            Log::error("Fingerprint sync error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener lista de huellas registradas en el dispositivo
     */
    public function listFingerprints(): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/fingerprint/list");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'fingerprints' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to list fingerprints',
                'status' => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error("Fingerprint list error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test connection to fingerprint server
     */
    public function testConnection(): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/health");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Connected to fingerprint server',
                    'version' => $response->json()['version'] ?? 'unknown',
                ];
            }

            return [
                'success' => false,
                'error' => 'Fingerprint server returned error',
                'status' => $response->status(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Cannot connect to fingerprint server',
                'url' => $this->baseUrl,
                'details' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener huella por ID (incluye imagen base64 almacenada).
     */
    public function getFingerprintById(string $fingerprintId): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/fingerprint/{$fingerprintId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Fingerprint not found',
                'status' => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error("getFingerprintById error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verificar huella en vivo contra un fingerprint_id almacenado.
     * Retorna imagen de la captura en vivo para que el frontend la muestre.
     */
    public function verifyLive(string $fingerprintId): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(30)
                ->post("{$this->baseUrl}/fingerprint/verify-live", [
                    'fingerprint_id' => $fingerprintId,
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error'   => 'Verify-live failed',
                'status'  => $response->status(),
            ];
        } catch (Exception $e) {
            Log::error("verifyLive error: " . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
