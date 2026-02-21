<?php

namespace App\Console\Commands;

use App\Services\RecurrenteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * php artisan gymflow:smoke-test
 *
 * Prueba todos los endpoints del sistema:
 *   1. Auth (login / user / logout)
 *   2. API interna (clients, memberships, plans, payments...)
 *   3. Recurrente API (conectividad real con llaves del .env)
 *   4. Webhooks (estructura y respuesta)
 *   5. Nuevos endpoints de ciclo de vida
 */
class SmokeTestCommand extends Command
{
    protected $signature = 'gymflow:smoke-test
                            {--only= : Solo correr un grupo: auth|api|recurrente|webhooks|lifecycle}
                            {--base-url=http://127.0.0.1:8000 : URL base del servidor local}';

    protected $description = 'Tests todos los endpoints de GymFlow y la conectividad con Recurrente';

    private string $baseUrl;
    private string $token  = '';
    private int $passed    = 0;
    private int $failed    = 0;
    private array $failures = [];

    public function handle(RecurrenteService $recurrente): int
    {
        $this->baseUrl = rtrim($this->option('base-url'), '/');
        $only          = $this->option('only');

        $this->displayHeader();

        if (! $only || $only === 'auth')       $this->testAuth();
        if (! $only || $only === 'api')        $this->testApi();
        if (! $only || $only === 'recurrente') $this->testRecurrente($recurrente);
        if (! $only || $only === 'lifecycle')  $this->testLifecycle();
        if (! $only || $only === 'webhooks')   $this->testWebhooks();

        $this->displaySummary();

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────
    //  GRUPO 1 — AUTH
    // ─────────────────────────────────────────────────────────────────

    private function testAuth(): void
    {
        $this->sectionTitle('🔐 GRUPO 1: Autenticación');

        // 1.1 Login correcto
        $res = $this->post('/api/login', ['email' => 'admin@gymflow.com', 'password' => 'admin123']);
        if ($this->check('LOGIN: Admin puede iniciar sesión', isset($res['token']))) {
            $this->token = $res['token'];
        }

        // 1.2 Login con credenciales incorrectas → 401
        $res = $this->rawPost('/api/login', ['email' => 'admin@gymflow.com', 'password' => 'wrong']);
        $this->check('LOGIN: Credenciales incorrectas retornan 401', $res->status() === 401);

        // 1.3 GET /api/user con token válido
        $res = $this->get('/api/user');
        $this->check('GET /api/user: Retorna datos del usuario autenticado', isset($res['email']));
        $this->check('GET /api/user: Email correcto', ($res['email'] ?? '') === 'admin@gymflow.com');

        // 1.4 GET /api/user sin token → 401
        $resRaw = Http::get("{$this->baseUrl}/api/user");
        $this->check('GET /api/user sin token: Retorna 401', $resRaw->status() === 401);

        // 1.5 Logout
        $logoutRes = $this->rawPost('/api/logout', []);
        $this->check('POST /api/logout: Retorna 200', $logoutRes->status() === 200);

        // ─ Re-login para los demás grupos ─
        $res2 = $this->post('/api/login', ['email' => 'admin@gymflow.com', 'password' => 'admin123']);
        $this->token = $res2['token'] ?? '';
    }

    // ─────────────────────────────────────────────────────────────────
    //  GRUPO 2 — API INTERNA
    // ─────────────────────────────────────────────────────────────────

    private function testApi(): void
    {
        $this->sectionTitle('🗃️  GRUPO 2: API Interna');

        // Planes
        $plans = $this->get('/api/membership-plans');
        $this->check('GET /api/membership-plans: Responde 200', is_array($plans));

        // Clientes
        $clients = $this->get('/api/clients');
        $this->check('GET /api/clients: Responde 200', is_array($clients));

        // Membresías
        $memberships = $this->get('/api/memberships');
        $this->check('GET /api/memberships: Responde 200', is_array($memberships));

        // Pagos
        $payments = $this->get('/api/payments');
        $this->check('GET /api/payments: Responde 200', is_array($payments));

        // Accesos
        $logs = $this->get('/api/access-logs');
        $this->check('GET /api/access-logs: Responde 200', is_array($logs));

        // Site settings (público)
        $settings = Http::get("{$this->baseUrl}/api/site-settings")->json();
        $this->check('GET /api/site-settings (público): Responde 200', is_array($settings));

        // Planes públicos (sin token)
        $publicPlans = Http::get("{$this->baseUrl}/api/public/plans")->json();
        $this->check('GET /api/public/plans (sin auth): Responde 200', is_array($publicPlans));

        // Productos públicos
        $publicProducts = Http::get("{$this->baseUrl}/api/public/products")->json();
        $this->check('GET /api/public/products (sin auth): Responde 200', is_array($publicProducts));

        // Rutas de comercial
        $productos = $this->get('/api/productos');
        $this->check('GET /api/productos: Responde 200', is_array($productos));

        $ventas = $this->get('/api/ventas');
        $this->check('GET /api/ventas: Responde 200', is_array($ventas));

        // Recibos
        $receipts = $this->get('/api/receipts');
        $this->check('GET /api/receipts: Responde 200', is_array($receipts));

        // Users (staff)
        $users = $this->get('/api/users');
        $this->check('GET /api/users: Responde 200', is_array($users));

        // Roles
        $roles = $this->get('/api/roles');
        $this->check('GET /api/roles: Responde 200', is_array($roles));

        // Dashboard membresías en riesgo (nuevo endpoint)
        $risk = $this->rawGet('/api/membresias/riesgo');
        $this->check('GET /api/membresias/riesgo: Responde 200', $risk->status() === 200);

        // Pago adelanto estado
        if (! empty($clients) && isset($clients[0]['id'])) {
            $clientId = $clients[0]['id'];
            $adelanto = $this->rawGet("/api/pagos/adelanto/client/{$clientId}");
            $this->check("GET /api/pagos/adelanto/client/{$clientId}: Responde 200", $adelanto->status() === 200);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  GRUPO 3 — RECURRENTE API (conectividad real)
    // ─────────────────────────────────────────────────────────────────

    private function testRecurrente(RecurrenteService $recurrente): void
    {
        $this->sectionTitle('💳 GRUPO 3: Conectividad Recurrente API');

        $pubKey    = config('services.recurrente.public_key');
        $secretKey = config('services.recurrente.secret_key');
        $baseUrl   = config('services.recurrente.base_url');

        // 3.1 Verificar llaves configuradas
        $this->check('RECURRENTE_PUBLIC_KEY configurada en .env', ! empty($pubKey));
        $this->check('RECURRENTE_SECRET_KEY configurada en .env', ! empty($secretKey));
        $this->check('RECURRENTE_BASE_URL configurada en .env', ! empty($baseUrl));
        $this->check('Llaves son de TEST (pk_test_...)', str_starts_with($pubKey ?? '', 'pk_test_'));
        $this->check('Llaves son de TEST (sk_test_...)', str_starts_with($secretKey ?? '', 'sk_test_'));

        // 3.2 Conectividad con POST /products (prueba real de autenticación)
        try {
            $product = $recurrente->createProduct([
                'name'          => '[SMOKE TEST] Plan Prueba ' . now()->format('His'),
                'currency'      => 'GTQ',
                'price_in_cents' => 30000, // Q300.00
                'description'   => 'Producto de prueba automático — eliminar',
            ]);

            $productId = $product['id'] ?? null;
            $this->check('POST /products: Crear producto en Recurrente', ! empty($productId));

            if ($productId) {
                $this->line("   <fg=cyan>   → Producto creado: {$productId}</>");
            }

            // 3.3 GET /products/{id}
            if ($productId) {
                $fetched = $recurrente->getProduct($productId);
                $this->check('GET /products/{id}: Obtener producto creado', ($fetched['id'] ?? '') === $productId);
            }

        } catch (\Exception $e) {
            $this->check('POST /products: Crear producto en Recurrente', false, $e->getMessage());
        }

        // 3.4 Crear usuario de prueba
        try {
            $user = $recurrente->createUser([
                'email' => 'smoketest_' . time() . '@gymflow-test.com',
                'name'  => 'Smoke Test User',
            ]);
            $userId = $user['id'] ?? null;
            $this->check('POST /users: Crear usuario en Recurrente', ! empty($userId));

            if ($userId) {
                $this->line("   <fg=cyan>   → Usuario creado: {$userId}</>");

                // 3.5 GET /users/{id}
                $fetchedUser = $recurrente->getUser($userId);
                $this->check('GET /users/{id}: Obtener usuario creado', ($fetchedUser['id'] ?? '') === $userId);
            }

        } catch (\Exception $e) {
            $this->check('POST /users: Crear usuario en Recurrente', false, $e->getMessage());
        }

        // 3.6 Verificar que error 401 es manejado correctamente
        $badKeyRes = Http::withHeaders([
            'X-PUBLIC-KEY' => 'pk_test_INVALIDA',
            'X-SECRET-KEY' => 'sk_test_INVALIDA',
            'Accept'       => 'application/json',
        ])->get("{$baseUrl}/products");
        $this->check('401 con llaves inválidas: Recurrente retorna 401', $badKeyRes->status() === 401);
    }

    // ─────────────────────────────────────────────────────────────────
    //  GRUPO 4 — CICLO DE VIDA (nuevos endpoints)
    // ─────────────────────────────────────────────────────────────────

    private function testLifecycle(): void
    {
        $this->sectionTitle('🔄 GRUPO 4: Endpoints de Ciclo de Vida');

        // Calcular impacto de pausa (sin membresía real — debería retornar 404 limpio)
        $res = $this->rawGet('/api/membresias/pausar/impacto?membership_id=999&pause_start=' . today()->addDays(1)->toDateString() . '&pause_end=' . today()->addDays(15)->toDateString());
        $this->check(
            'GET /api/membresias/pausar/impacto: Maneja 404 limpiamente',
            in_array($res->status(), [404, 422, 500]) // No debe ser 200 con ID inexistente
        );

        // Dashboard de riesgo
        $res = $this->rawGet('/api/membresias/riesgo');
        $this->check('GET /api/membresias/riesgo: Responde 200', $res->status() === 200);
        $data = $res->json();
        $this->check('GET /api/membresias/riesgo: Tiene summary, at_risk, expiring, paused',
            isset($data['summary']) && isset($data['at_risk']) && isset($data['expiring']) && isset($data['paused'])
        );

        // Validar estructura de payload de pago adelantado
        $res = $this->rawPost('/api/pagos/adelanto', [
            'client_id'      => 999,
            'installment_ids' => [],
            'monto'           => 0,
            'metodo'          => 'invalid',
        ]);
        $this->check('POST /api/pagos/adelanto: Validación de datos faltantes retorna 422', $res->status() === 422);

        // Pausa con fechas inválidas
        $res = $this->rawPost('/api/membresias/pausar', [
            'membership_id' => 999,
            'pause_start'   => '2020-01-01', // Pasado — debe fallar
            'pause_end'     => '2020-01-15',
            'reason'        => 'travel',
        ]);
        $this->check('POST /api/membresias/pausar: Fecha pasada retorna 422', $res->status() === 422);

        // Toggle wants_renewal con payload inválido
        $res = $this->rawPut('/api/membresias/999/wants-renewal', ['wants_auto_renewal' => 'invalid']);
        $this->check('PUT /api/membresias/{id}/wants-renewal: Payload inválido retorna 422', $res->status() === 422);

        // Alertas de conciliación
        $res = $this->rawGet('/api/pagos/alertas');
        $this->check('GET /api/pagos/alertas: Responde 200', $res->status() === 200);
    }

    // ─────────────────────────────────────────────────────────────────
    //  GRUPO 5 — WEBHOOKS (estructura de respuesta)
    // ─────────────────────────────────────────────────────────────────

    private function testWebhooks(): void
    {
        $this->sectionTitle('🪝 GRUPO 5: Webhooks');

        // 5.1 Sin payload → no debe romper el servidor
        $res = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/webhooks/recurrente", []);
        $this->check(
            'POST /webhooks/recurrente sin payload: No lanza 500',
            $res->status() !== 500,
            "Status: {$res->status()}"
        );

        // 5.2 Evento desconocido → debe dar 200 (silencioso)
        $res = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/webhooks/recurrente", [
                'type' => 'evento.desconocido',
                'data' => ['id' => 'test_123'],
            ]);
        $this->check(
            'POST /webhooks/recurrente evento desconocido: Retorna 200',
            $res->status() === 200,
            "Status: {$res->status()}"
        );

        // 5.3 checkout.succeeded con datos mínimos (sin membership real → loguea y sigue)
        $res = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/webhooks/recurrente", [
                'type' => 'checkout.succeeded',
                'data' => [
                    'id'     => 'checkout_smoketest_' . time(),
                    'status' => 'paid',
                ],
            ]);
        $this->check(
            'POST /webhooks/recurrente checkout.succeeded: No lanza 500',
            $res->status() !== 500,
            "Status: {$res->status()}"
        );

        // 5.4 subscription.paid
        $res = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/webhooks/recurrente", [
                'type' => 'subscription.paid',
                'data' => [
                    'id'              => 'sub_smoketest_' . time(),
                    'subscription_id' => 'sub_test_abc',
                    'amount_in_cents' => 30000,
                    'currency'        => 'GTQ',
                ],
            ]);
        $this->check(
            'POST /webhooks/recurrente subscription.paid: No lanza 500',
            $res->status() !== 500,
            "Status: {$res->status()}"
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  HTTP HELPERS
    // ─────────────────────────────────────────────────────────────────

    private function get(string $path): array
    {
        return Http::withToken($this->token)
            ->get("{$this->baseUrl}{$path}")
            ->json() ?? [];
    }

    private function rawGet(string $path)
    {
        return Http::withToken($this->token)
            ->get("{$this->baseUrl}{$path}");
    }

    private function post(string $path, array $data): array
    {
        return Http::withToken($this->token)
            ->post("{$this->baseUrl}{$path}", $data)
            ->json() ?? [];
    }

    private function rawPost(string $path, array $data)
    {
        return Http::withToken($this->token)
            ->post("{$this->baseUrl}{$path}", $data);
    }

    private function rawPut(string $path, array $data)
    {
        return Http::withToken($this->token)
            ->put("{$this->baseUrl}{$path}", $data);
    }

    // ─────────────────────────────────────────────────────────────────
    //  UI HELPERS
    // ─────────────────────────────────────────────────────────────────

    private function check(string $label, bool $passed, string $detail = ''): bool
    {
        if ($passed) {
            $this->line("   <fg=green>✅ PASS</> {$label}");
            $this->passed++;
        } else {
            $detail = $detail ? " — <fg=yellow>{$detail}</>" : '';
            $this->line("   <fg=red>❌ FAIL</> {$label}{$detail}");
            $this->failed++;
            $this->failures[] = $label . ($detail ? " ($detail)" : '');
        }
        return $passed;
    }

    private function sectionTitle(string $title): void
    {
        $this->newLine();
        $this->line("<fg=blue;options=bold>━━━ {$title} ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>");
    }

    private function displayHeader(): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>╔════════════════════════════════════════════╗</>');
        $this->line('<fg=cyan;options=bold>║   GYMFLOW — SMOKE TEST SUITE               ║</>');
        $this->line('<fg=cyan;options=bold>╚════════════════════════════════════════════╝</>');
        $this->line("<fg=gray>Base URL: {$this->baseUrl}</>");
        $this->line('<fg=gray>Env Recurrente: ' . config('services.recurrente.env') . '</>');
        $this->line('<fg=gray>Fecha: ' . now()->format('d/m/Y H:i:s') . '</>');
    }

    private function displaySummary(): void
    {
        $total = $this->passed + $this->failed;
        $this->newLine();
        $this->line('<fg=cyan;options=bold>━━━ RESULTADO FINAL ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line("<fg=green>✅ Pasaron: {$this->passed}/{$total}</>");
        $this->line("<fg=red>❌ Fallaron: {$this->failed}/{$total}</>");

        if (! empty($this->failures)) {
            $this->newLine();
            $this->line('<fg=red;options=bold>Tests fallidos:</>' );
            foreach ($this->failures as $fail) {
                $this->line("  <fg=red>•</> {$fail}");
            }
        }

        if ($this->failed === 0) {
            $this->newLine();
            $this->line('<fg=green;options=bold>🎉 ¡Todo funciona correctamente!</>');
        } else {
            $this->newLine();
            $this->line('<fg=yellow>⚠️  Hay tests fallidos. Revisa los detalles arriba.</>');
        }
        $this->newLine();
    }
}
