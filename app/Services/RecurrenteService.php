<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\RequestException;

/**
 * RecurrenteService
 *
 * Centraliza todas las llamadas a la API de Recurrente.
 * Documentación: https://recurrente.com/docs/api
 *
 * Autenticación via headers:
 *   X-PUBLIC-KEY: {public_key}
 *   X-SECRET-KEY: {secret_key}
 */
class RecurrenteService
{
    protected string $baseUrl;
    protected string $publicKey;
    protected string $secretKey;

    public function __construct()
    {
        $this->baseUrl   = rtrim(config('services.recurrente.base_url'), '/');
        $this->publicKey = config('services.recurrente.public_key');
        $this->secretKey = config('services.recurrente.secret_key');
    }

    // ─────────────────────────────────────────────
    //  HTTP HELPER
    // ─────────────────────────────────────────────

    /**
     * Realiza una petición HTTP a la API de Recurrente.
     *
     * @param  string  $method   GET | POST | PATCH | DELETE
     * @param  string  $endpoint Ruta relativa, ej: '/users'
     * @param  array   $body     Cuerpo del request (solo para POST/PATCH)
     * @return array   Respuesta decodificada como array
     * @throws \Exception si la API devuelve error
     */
    protected function request(string $method, string $endpoint, array $body = []): array
    {
        $url = $this->baseUrl . $endpoint;

        Log::info("[Recurrente] → {$method} {$url}", [
            'body' => $body,
        ]);

        try {
            $http = Http::withHeaders([
                'X-PUBLIC-KEY' => $this->publicKey,
                'X-SECRET-KEY' => $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->timeout(30);

            $response = match (strtoupper($method)) {
                'GET'    => $http->get($url),
                'POST'   => $http->post($url, $body),
                'PATCH'  => $http->patch($url, $body),
                'DELETE' => $http->delete($url),
                default  => throw new \InvalidArgumentException("HTTP method [{$method}] not supported"),
            };

            $data = $response->json() ?? [];

            Log::info("[Recurrente] ← {$response->status()} {$url}", [
                'response' => $data,
            ]);

            if ($response->failed()) {
                $message = $data['message'] ?? $data['error'] ?? "HTTP {$response->status()} from Recurrente";
                Log::error("[Recurrente] Error {$response->status()}: {$message}", [
                    'url'  => $url,
                    'body' => $body,
                    'data' => $data,
                ]);
                throw new \Exception("Recurrente API error ({$response->status()}): {$message}");
            }

            return $data;

        } catch (RequestException $e) {
            Log::error("[Recurrente] Request exception: " . $e->getMessage());
            throw new \Exception("Error de conexión con Recurrente: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────
    //  USUARIOS / CLIENTES
    // ─────────────────────────────────────────────

    /**
     * Crear un usuario en Recurrente.
     * POST /api/users
     *
     * @param  array $data  ['first_name', 'last_name', 'email', 'phone']
     * @return array        Respuesta con 'id' del usuario creado
     */
    public function createUser(array $data): array
    {
        return $this->request('POST', '/users', $data);
    }

    /**
     * Obtener un usuario de Recurrente.
     * GET /api/users/{id}
     */
    public function getUser(string $recurrenteUserId): array
    {
        return $this->request('GET', "/users/{$recurrenteUserId}");
    }

    // ─────────────────────────────────────────────
    //  PRODUCTOS / PLANES
    // ─────────────────────────────────────────────

    /**
     * Crear un producto en Recurrente.
     * POST /api/products
     *
     * @param  array $data  ['name', 'price_in_cents', 'currency']
     * @return array        Respuesta con 'id' del producto
     */
    public function createProduct(array $data): array
    {
        return $this->request('POST', '/products', $data);
    }

    /**
     * Obtener un producto de Recurrente.
     * GET /api/products/{id}
     */
    public function getProduct(string $productId): array
    {
        return $this->request('GET', "/products/{$productId}");
    }

    // ─────────────────────────────────────────────
    //  CHECKOUTS (pago hosteado)
    // ─────────────────────────────────────────────

    /**
     * Crear un checkout hosteado en Recurrente.
     * POST /api/checkouts
     *
     * Pide al cliente que ingrese su tarjeta vía la página de Recurrente.
     *
     * @param  array $data  [
     *   'user_id',
     *   'items' => [['product_id', 'quantity']],
     *   'success_url',
     *   'cancel_url',
     * ]
     * @return array ['checkout_url', 'id', ...]
     */
    public function createCheckout(array $data): array
    {
        return $this->request('POST', '/checkouts', $data);
    }

    // ─────────────────────────────────────────────
    //  PAGOS ÚNICOS (cobro con tarjeta guardada)
    // ─────────────────────────────────────────────

    /**
     * Cobrar un pago único con tarjeta guardada.
     * POST /api/one_time_payments
     *
     * @param  array $data  [
     *   'payment_method_id',
     *   'items' => [['name', 'amount_in_cents', 'currency']],
     * ]
     * @return array
     */
    public function createOneTimePayment(array $data): array
    {
        return $this->request('POST', '/one_time_payments', $data);
    }

    // ─────────────────────────────────────────────
    //  SUSCRIPCIONES
    // ─────────────────────────────────────────────

    /**
     * Crear una suscripción recurrente.
     * POST /api/subscriptions
     *
     * @param  array $data  ['user_id', 'product_id']
     * @return array
     */
    public function createSubscription(array $data): array
    {
        return $this->request('POST', '/subscriptions', $data);
    }

    /**
     * Cancelar una suscripción.
     * DELETE /api/subscriptions/{id}
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->request('DELETE', "/subscriptions/{$subscriptionId}");
    }

    /**
     * Obtener estado de una suscripción.
     * GET /api/subscriptions/{id}
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', "/subscriptions/{$subscriptionId}");
    }

    // ─────────────────────────────────────────────
    //  MÉTODOS DE PAGO
    // ─────────────────────────────────────────────

    /**
     * Obtener métodos de pago guardados de un usuario.
     * GET /api/users/{user_id}/payment_methods
     */
    public function getPaymentMethods(string $recurrenteUserId): array
    {
        return $this->request('GET', "/users/{$recurrenteUserId}/payment_methods");
    }
}
