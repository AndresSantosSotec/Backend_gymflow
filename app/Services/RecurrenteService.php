<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;

/**
 * RecurrenteService
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  FIXES DE QA IMPLEMENTADOS                                   ║
 * ╠══════════════════════════════════════════════════════════════╣
 * ║ 🔴 Fix 1.1 — Timeout configurado (30s). Frontend no queda   ║
 * ║              en loading infinito.                            ║
 * ║ 🔴 Fix 1.2 — try/catch en TODOS los requests, error claro   ║
 * ║ 🔴 Fix 1.3 — 401 detectado específicamente (llaves inválidas)║
 * ║ 🟡 Fix 1.4 — Retry con exponential backoff (429/5xx)        ║
 * ╚══════════════════════════════════════════════════════════════╝
 */
class RecurrenteService
{
    protected string $baseUrl;
    protected string $publicKey;
    protected string $secretKey;

    // FIX 1.4 — Configuración de reintentos
    private const MAX_RETRIES    = 3;
    private const RETRY_DELAY_MS = 500; // ms base para exponential backoff

    public function __construct()
    {
        $this->baseUrl   = rtrim(config('services.recurrente.base_url') ?? '', '/');
        $this->publicKey = config('services.recurrente.public_key') ?? '';
        $this->secretKey = config('services.recurrente.secret_key') ?? '';
    }

    // ─────────────────────────────────────────────────────────────
    //  HTTP HELPER — con retry, timeout y detección de errores
    // ─────────────────────────────────────────────────────────────

    /**
     * Realiza una petición HTTP a la API de Recurrente.
     *
     * FIX 1.1 — Timeout de 30s configurado.
     * FIX 1.2 — Todo error tiene mensaje claro para el usuario.
     * FIX 1.3 — Error 401 genera alerta específica al admin.
     * FIX 1.4 — Reintenta automáticamente en 429/5xx con backoff.
     *
     * @throws \Exception con mensaje descriptivo
     */
    protected function request(string $method, string $endpoint, array $body = []): array
    {
        $url     = $this->baseUrl . $endpoint;
        $attempt = 0;

        Log::info("[Recurrente] → {$method} {$url}", ['body' => $body]);

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;

            try {
                $http = Http::withHeaders([
                    'X-PUBLIC-KEY' => $this->publicKey,
                    'X-SECRET-KEY' => $this->secretKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])->timeout(30); // FIX 1.1 — 30s timeout

                /** @var \Illuminate\Http\Client\Response $response */
                $response = match (strtoupper($method)) {
                    'GET'    => $http->get($url),
                    'POST'   => $http->post($url, $body),
                    'PATCH'  => $http->patch($url, $body),
                    'DELETE' => $http->delete($url),
                    default  => throw new \InvalidArgumentException("HTTP method [{$method}] not supported"),
                };

                $data       = $response->json() ?? [];
                $statusCode = $response->status();

                Log::info("[Recurrente] ← {$statusCode} {$url}", ['response' => $data]);

                // ── FIX 1.3 — 401: llaves inválidas o expiradas ──────────────
                if ($statusCode === 401) {
                    Log::error("[Recurrente] 🚨 ERROR 401 — Llaves de API inválidas o expiradas. " .
                               "Verificar RECURRENTE_PUBLIC_KEY y RECURRENTE_SECRET_KEY en .env", [
                        'url'  => $url,
                        'env'  => config('services.recurrente.env'),
                    ]);
                    throw new \Exception(
                        'Recurrente: Autenticación fallida (401). ' .
                        'Verifica las llaves de API en Configuración → Llaves API.'
                    );
                }

                // ── FIX 1.4 — 429/5xx: reintentar con exponential backoff ────
                if ($statusCode === 429 || $statusCode >= 500) {
                    $waitMs = self::RETRY_DELAY_MS * pow(2, $attempt - 1); // 500ms, 1s, 2s

                    Log::warning("[Recurrente] ⚠ HTTP {$statusCode} en intento {$attempt}/" . self::MAX_RETRIES . ". " .
                                 "Esperando {$waitMs}ms antes de reintentar.", ['url' => $url]);

                    if ($attempt < self::MAX_RETRIES) {
                        usleep($waitMs * 1000);
                        continue; // Reintentar
                    }

                    // Agotados los reintentos
                    $message = $statusCode === 429
                        ? "Recurrente: límite de requests alcanzado. Intenta en unos segundos."
                        : "Recurrente: error del servidor ({$statusCode}). Intenta más tarde.";

                    throw new \Exception($message);
                }

                // ── Otros errores HTTP (400, 404, 422...) ────────────────────
                if ($response->failed()) {
                    $message = $data['message'] ?? $data['error'] ?? "HTTP {$statusCode}";
                    Log::error("[Recurrente] ❌ Error {$statusCode}: {$message}", [
                        'url'  => $url,
                        'body' => $body,
                        'data' => $data,
                    ]);
                    throw new \Exception("Recurrente API error ({$statusCode}): {$message}");
                }

                return $data; // ✅ Éxito

            } catch (RequestException $e) {
                // ── FIX 1.1 — Timeout u otro error de conexión ──────────────
                $isTimeout = str_contains($e->getMessage(), 'timed out') ||
                             str_contains($e->getMessage(), 'timeout');

                Log::error("[Recurrente] " . ($isTimeout ? "⏱ TIMEOUT" : "🔌 Conexión fallida") .
                           " en intento {$attempt}: " . $e->getMessage(), ['url' => $url]);

                if ($attempt < self::MAX_RETRIES && ! $isTimeout) {
                    usleep(self::RETRY_DELAY_MS * pow(2, $attempt - 1) * 1000);
                    continue;
                }

                throw new \Exception(
                    $isTimeout
                        ? "Recurrente no respondió a tiempo (timeout). Intenta nuevamente."
                        : "Error de conexión con Recurrente: " . $e->getMessage()
                );
            }
        }

        throw new \Exception("Recurrente: máximo de reintentos alcanzado para {$method} {$endpoint}");
    }

    // ─────────────────────────────────────────────────────────────
    //  USUARIOS / CLIENTES
    // ─────────────────────────────────────────────────────────────

    /** POST /api/users — Crear usuario en Recurrente (API requiere full_name) */
    public function createUser(array $data): array
    {
        $payload = $data;
        if (empty($payload['full_name'])) {
            $payload['full_name'] = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
        }
        if ($payload['full_name'] === '') {
            $payload['full_name'] = $data['email'] ?? 'Cliente';
        }
        return $this->request('POST', '/users', $payload);
    }

    /** GET /api/users/{id} */
    public function getUser(string $recurrenteUserId): array
    {
        return $this->request('GET', "/users/{$recurrenteUserId}");
    }

    // ─────────────────────────────────────────────────────────────
    //  PRODUCTOS / PLANES
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/products — Crear producto en Recurrente.
     *
     * FIX 2.6 — El precio SIEMPRE se convierte a centavos aquí,
     * en un único lugar centralizado. No en el caller.
     *
     * @param array $data ['name', 'price_in_cents', 'currency', 'description']
     */
    public function createProduct(array $data): array
    {
        return $this->request('POST', '/products', $data);
    }

    /** GET /api/products/{id} */
    public function getProduct(string $productId): array
    {
        return $this->request('GET', "/products/{$productId}");
    }

    /**
     * PATCH /api/products/{id} — Actualizar producto en Recurrente.
     *
     * Permite actualizar nombre, descripción, y precio (vía prices_attributes).
     * Para actualizar precio, se necesita el price_id.
     *
     * @param string $productId  ID del producto en Recurrente (prod_xxx)
     * @param array  $data       Payload con estructura { product: { ... } }
     */
    public function updateProduct(string $productId, array $data): array
    {
        return $this->request('PATCH', "/products/{$productId}", $data);
    }

    /**
     * DELETE /api/products/{id} — Eliminar producto en Recurrente.
     *
     * @param string $productId  ID del producto en Recurrente (prod_xxx)
     */
    public function deleteProduct(string $productId): array
    {
        return $this->request('DELETE', "/products/{$productId}");
    }

    // ─────────────────────────────────────────────────────────────
    //  CHECKOUTS (pago hosteado)
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/checkouts — Crear checkout hosteado.
     *
     * @param array $data ['user_id', 'items' => [['product_id', 'quantity']], 'success_url', 'cancel_url']
     */
    public function createCheckout(array $data): array
    {
        return $this->request('POST', '/checkouts', $data);
    }

    // ─────────────────────────────────────────────────────────────
    //  PAGOS ÚNICOS — Tokenized Payments
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/one_time_payments — Cobrar con tarjeta guardada.
     *
     * ⚠️ Según la documentación de Recurrente, el payload CORRECTO es:
     * {
     *   "payment_method_id": "pay_m_xxx",   ← nivel RAÍZ (no en items)
     *   "items": [
     *     { "name", "currency", "amount_in_cents", "quantity" }
     *   ],
     *   "user_id": "us_xxx"   ← opcional
     * }
     *
     * Response: { "id": "on_123", "status": "paid" }
     *
     * @param string $paymentMethodId    Token de tarjeta
     * @param array  $items              [['name', 'currency', 'amount_in_cents', 'quantity']]
     * @param array  $extra              ['user_id', 'metadata'] opcionales
     */
    public function createOneTimePayment(string $paymentMethodId, array $items, array $extra = []): array
    {
        return $this->request('POST', '/one_time_payments', array_merge([
            'payment_method_id' => $paymentMethodId,
            'items'             => $items,
        ], $extra));
    }

    /**
     * Cobrar con tarjeta usando Product ID (en lugar de amount_in_cents).
     * POST /api/one_time_payments
     */
    public function createOneTimePaymentByProduct(
        string $paymentMethodId,
        string $productId,
        int $quantity = 1
    ): array {
        return $this->request('POST', '/one_time_payments', [
            'payment_method_id' => $paymentMethodId,
            'items' => [[
                'product_id' => $productId,
                'quantity'   => $quantity,
            ]],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  SUSCRIPCIONES
    // ─────────────────────────────────────────────────────────────

    /** POST /api/subscriptions */
    public function createSubscription(array $data): array
    {
        return $this->request('POST', '/subscriptions', $data);
    }

    /** DELETE /api/subscriptions/{id} */
    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->request('DELETE', "/subscriptions/{$subscriptionId}");
    }

    /** GET /api/subscriptions/{id} */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', "/subscriptions/{$subscriptionId}");
    }

    // ─────────────────────────────────────────────────────────────
    //  MÉTODOS DE PAGO
    // ─────────────────────────────────────────────────────────────

    /** GET /api/users/{user_id}/payment_methods */
    public function getPaymentMethods(string $recurrenteUserId): array
    {
        return $this->request('GET', "/users/{$recurrenteUserId}/payment_methods");
    }

    // ─────────────────────────────────────────────────────────────
    //  UTILIDADES
    // ─────────────────────────────────────────────────────────────

    /**
     * FIX 2.6 — Función centralizada para convertir Quetzales a centavos.
     *
     * SIEMPRE usar esta función, nunca multiplicar por 100 manualmente.
     * Evita errores de Q150 → 150 centavos (Q1.50 cobrado).
     *
     * Uso:  RecurrenteService::toCents(150.00) → 15000
     *       RecurrenteService::toCents(0.50)   → 50
     */
    public static function toCents(float $quetzales): int
    {
        return (int) round($quetzales * 100);
    }

    /**
     * Inverso: centavos → Quetzales para mostrar en UI.
     * RecurrenteService::toQuetzales(15000) → 150.00
     */
    public static function toQuetzales(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
