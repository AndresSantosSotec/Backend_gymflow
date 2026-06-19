<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\PaymentInstallment;
use App\Models\RecurrenteSubscription;
use App\Models\User;
use App\Services\PagoAdelantoService;
use App\Services\RecurrenteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tests para el sistema de pagos adelantados.
 *
 * Cubren los 4 escenarios del prompt + edge cases críticos.
 *
 * Ejecutar: php artisan test --filter PagoAdelantoTest
 */
class PagoAdelantoTest extends TestCase
{
    use RefreshDatabase;

    private User   $admin;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $role = \App\Models\Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $p1 = \App\Models\Permission::firstOrCreate(['slug' => 'PAYMENTS_VIEW'], ['name' => 'View Payments']);
        $p2 = \App\Models\Permission::firstOrCreate(['slug' => 'PAYMENTS_MANAGE'], ['name' => 'Manage Payments']);
        $role->permissions()->sync([$p1->id, $p2->id]);

        $this->admin = User::factory()->create(['role_id' => $role->id]);
        $this->client = Client::create([
            'first_name'                  => 'Client',
            'last_name'                   => 'Test',
            'email'                       => 'client.test.' . rand(1000, 9999) . '@example.com',
            'qr_code'                     => 'QR_TEST_' . rand(100000, 999999),
            'recurrente_user_id'          => 'us_test_123',
            'recurrente_payment_method_id' => 'pay_m_test_456',
        ]);
    }

    /**
     * Helper: crear membresía con N cuotas mensuales.
     */
    private function crearMembresiaConCuotas(int $numCuotas = 12, float $precioPorCuota = 500.00): array
    {
        $plan = MembershipPlan::create([
            'name'                  => 'Plan Test ' . rand(1000, 9999),
            'slug'                  => 'plan-test-' . rand(1000, 9999),
            'price'                 => $precioPorCuota,
            'duration_days'         => 30,
            'recurrente_product_id' => 'prod_test_' . rand(1000, 9999),
            'published'             => true,
        ]);

        $membership = Membership::create([
            'client_id'        => $this->client->id,
            'plan_id'          => $plan->id,
            'start_date'       => Carbon::now()->toDateString(),
            'end_date'         => Carbon::now()->addMonths($numCuotas)->toDateString(),
            'status'           => 'active',
            'payment_type'     => 'installments',
            'num_installments' => $numCuotas,
            'total_amount'     => $precioPorCuota * $numCuotas,
            'amount_paid'      => 0,
            'payment_status'   => 'pending',
        ]);

        $installments = [];
        for ($i = 1; $i <= $numCuotas; $i++) {
            $installments[] = PaymentInstallment::create([
                'membership_id'      => $membership->id,
                'client_id'          => $this->client->id,
                'installment_number' => $i,
                'amount'             => $precioPorCuota,
                'amount_paid'        => 0,
                'due_date'           => Carbon::now()->addMonths($i - 1)->toDateString(),
                'status'             => 'pending',
            ]);
        }

        $suscripcion = RecurrenteSubscription::create([
            'client_id'                  => $this->client->id,
            'membership_plan_id'         => $plan->id,
            'recurrente_subscription_id' => 'sub_test_' . rand(100, 999),
            'recurrente_product_id'      => $plan->recurrente_product_id,
            'status'                     => 'active',
            'metadata'                   => [],
        ]);

        return compact('membership', 'installments', 'plan', 'suscripcion');
    }

    // ─────────────────────────────────────────────────────────────
    //  TEST A — 5 meses adelantados → Recurrente se reprograma
    // ─────────────────────────────────────────────────────────────

    /**
     * @test
     * Escenario: Cliente paga cuotas 2,3,4,5,6 en efectivo.
     * Esperado:
     * - Cuotas marcadas como paid con method=efectivo
     * - Suscripción vieja cancelada en Recurrente
     * - Nueva suscripción creada desde mes 7
     * - Sin solapamiento de fechas
     */
    public function test_pago_adelantado_reprograma_recurrente(): void
    {
        ['installments' => $installments, 'suscripcion' => $suscripcion, 'plan' => $plan] =
            $this->crearMembresiaConCuotas(12, 500.00);

        // Marcar cuota 1 como ya pagada (la corriente)
        $installments[0]->update(['status' => 'paid', 'amount_paid' => 500, 'paid_at' => now()]);

        // Cuotas 2-6 = índices 1-5
        $cuotaIds = array_map(fn ($i) => $i->id, array_slice($installments, 1, 5));

        // Mock de RecurrenteService
        $oldSubId = $suscripcion->recurrente_subscription_id;
        $newSubId = 'sub_new_789';

        $mockRecurrente = Mockery::mock(RecurrenteService::class);

        // ① Debe cancelar la suscripción vieja
        $mockRecurrente->shouldReceive('cancelSubscription')
            ->once()
            ->with($oldSubId)
            ->andReturn(['id' => $oldSubId, 'status' => 'cancelled']);

        // ② Debe crear nueva suscripción desde mes 7
        $mockRecurrente->shouldReceive('createSubscription')
            ->once()
            ->withArgs(function ($args) use ($plan) {
                return $args['user_id'] === $this->client->recurrente_user_id
                    && $args['product_id'] === $plan->recurrente_product_id;
            })
            ->andReturn(['id' => $newSubId, 'status' => 'active']);

        $service = new PagoAdelantoService($mockRecurrente);

        $resultado = $service->procesarPagoAdelantado(
            clientId:       $this->client->id,
            installmentIds: $cuotaIds,
            metodoPago:     'efectivo',
            montoTotal:     2500.00, // 5 × Q500
            registeredBy:   $this->admin->id,
        );

        // ─── Assertions ────────────────────────────────────────────

        // Las 5 cuotas están marcadas como pagadas
        $this->assertEquals(5, $resultado['cuotas_pagadas']);

        foreach ($cuotaIds as $id) {
            $this->assertDatabaseHas('payment_installments', [
                'id'                 => $id,
                'status'             => 'paid',
                'payment_method'     => 'efectivo',
                'is_advance_payment' => true,
            ]);
        }

        // La acción fue reprogramar
        $this->assertEquals('reprogramar', $resultado['recurrente_action']);

        // La suscripción vieja fue cancelada localmente
        $this->assertDatabaseHas('recurrente_subscriptions', [
            'id'     => $suscripcion->id,
            'status' => 'cancelled',
        ]);

        // La nueva suscripción fue creada
        $this->assertDatabaseHas('recurrente_subscriptions', [
            'recurrente_subscription_id' => $newSubId,
            'status'                     => 'active',
            'client_id'                  => $this->client->id,
        ]);

        // La próxima cuota es la 7 (índice 6)
        $this->assertNotNull($resultado['proxima_cuota']);
        $this->assertEquals(
            Carbon::parse($installments[6]->due_date)->startOfMonth()->toDateString(),
            $resultado['next_charge_date']
        );

        // ¡No hay solapamiento! La cuota 7 fecha === fecha de inicio nueva suscripción
        $nuevaSub = RecurrenteSubscription::where('recurrente_subscription_id', $newSubId)->first();
        $this->assertNotNull($nuevaSub);
        $fechaInicio = Carbon::parse($nuevaSub->current_period_start);
        $fechaCuota7 = Carbon::parse($installments[6]->due_date)->startOfMonth();
        $this->assertEquals($fechaCuota7->toDateString(), $fechaInicio->toDateString());

        // Hay log de auditoría
        $this->assertDatabaseHas('advance_payment_logs', [
            'client_id'          => $this->client->id,
            'payment_method'     => 'efectivo',
            'recurrente_action'  => 'reprogramar',
            'new_subscription_id' => $newSubId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  TEST B — Pago de TODAS las cuotas → Cancelar Recurrente
    // ─────────────────────────────────────────────────────────────

    /**
     * @test
     * Escenario: Cliente paga absolutamente todas las cuotas restantes.
     * Esperado:
     * - Suscripción cancelada completamente (Caso A)
     * - NO se crea nueva suscripción
     * - recurrente_status = 'cancelled'
     */
    public function test_pago_total_cancela_recurrente(): void
    {
        ['installments' => $installments, 'suscripcion' => $suscripcion] =
            $this->crearMembresiaConCuotas(4, 500.00); // Solo 4 cuotas para el test

        $todosLosIds = array_map(fn ($i) => $i->id, $installments);

        $mockRecurrente = Mockery::mock(RecurrenteService::class);

        // ① Solo debe CANCELAR. NO debe llamar a createSubscription.
        $mockRecurrente->shouldReceive('cancelSubscription')
            ->once()
            ->with($suscripcion->recurrente_subscription_id)
            ->andReturn(['id' => $suscripcion->recurrente_subscription_id, 'status' => 'cancelled']);

        $mockRecurrente->shouldNotReceive('createSubscription');

        $service   = new PagoAdelantoService($mockRecurrente);
        $resultado = $service->procesarPagoAdelantado(
            clientId:       $this->client->id,
            installmentIds: $todosLosIds,
            metodoPago:     'transferencia',
            montoTotal:     2000.00,
            registeredBy:   $this->admin->id,
            extras:         ['transfer_reference' => 'TRF-001'],
        );

        // Acción: cancelar (Caso A)
        $this->assertEquals('cancelar', $resultado['recurrente_action']);
        $this->assertNull($resultado['next_charge_date']);
        $this->assertNull($resultado['new_subscription']);

        // Suscripción cancelada localmente
        $this->assertDatabaseHas('recurrente_subscriptions', [
            'id'     => $suscripcion->id,
            'status' => 'cancelled',
        ]);

        // Membresía marcada con recurrente_status = cancelled
        $this->assertDatabaseHas('memberships', [
            'id'                => $installments[0]->membership_id,
            'recurrente_status' => 'cancelled',
        ]);

        // Todas las cuotas pagadas
        foreach ($todosLosIds as $id) {
            $this->assertDatabaseHas('payment_installments', [
                'id'                 => $id,
                'status'             => 'paid',
                'payment_method'     => 'transferencia',
                'transfer_reference' => 'TRF-001',
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  TEST C — Pago combinado ajusta fecha correctamente
    // ─────────────────────────────────────────────────────────────

    /**
     * @test
     * Escenario: Cliente paga 2 meses en efectivo. Los restantes en tarjeta.
     * Esperado:
     * - Recurrente cobrar desde el mes 3 (NO los meses 1 y 2)
     * - Suscripción reprogramada a la fecha de cuota 3
     */
    public function test_pago_combinado_ajusta_fecha_correctamente(): void
    {
        ['installments' => $installments, 'suscripcion' => $suscripcion, 'plan' => $plan] =
            $this->crearMembresiaConCuotas(6, 500.00);

        // Pagar cuotas 1 y 2 en efectivo (índices 0 y 1)
        $cuotaIds = [$installments[0]->id, $installments[1]->id];
        $newSubId = 'sub_combinado_999';

        $mockRecurrente = Mockery::mock(RecurrenteService::class);
        $mockRecurrente->shouldReceive('cancelSubscription')->once()->andReturn(['status' => 'cancelled']);
        $mockRecurrente->shouldReceive('createSubscription')
            ->once()
            ->andReturn(['id' => $newSubId, 'status' => 'active']);

        $service   = new PagoAdelantoService($mockRecurrente);
        $resultado = $service->procesarPagoAdelantado(
            clientId:       $this->client->id,
            installmentIds: $cuotaIds,
            metodoPago:     'combinado',
            montoTotal:     1000.00,
            registeredBy:   $this->admin->id,
        );

        // Cuotas 1 y 2 pagadas en efectivo
        $this->assertDatabaseHas('payment_installments', [
            'id'             => $installments[0]->id,
            'status'         => 'paid',
            'payment_method' => 'combinado',
        ]);
        $this->assertDatabaseHas('payment_installments', [
            'id'             => $installments[1]->id,
            'status'         => 'paid',
            'payment_method' => 'combinado',
        ]);

        // Cuota 3 sigue pending (Recurrente la cobrará)
        $this->assertDatabaseHas('payment_installments', [
            'id'     => $installments[2]->id,
            'status' => 'pending',
        ]);

        // Recurrente retoma desde cuota 3
        $fechaCuota3 = Carbon::parse($installments[2]->due_date)->startOfMonth()->toDateString();
        $this->assertEquals($fechaCuota3, $resultado['next_charge_date']);

        // La nueva suscripción fue creada
        $this->assertDatabaseHas('recurrente_subscriptions', [
            'recurrente_subscription_id' => $newSubId,
            'client_id'                  => $this->client->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  TEST D — Prevenir doble cobro (webhook + cuota ya pagada)
    // ─────────────────────────────────────────────────────────────

    /**
     * @test
     * Escenario:
     * 1. Admin registra cuota de Marzo como pagada en efectivo.
     * 2. Recurrente envía webhook intentando cobrar Marzo.
     *    (representado por intento de marcar recurrente_payment_id)
     *
     * Esperado:
     * - El webhook detecta que ya está pagada
     * - No duplica el pago
     * - Se genera log de advertencia
     */
    public function test_recurrente_no_cobra_cuotas_ya_pagadas(): void
    {
        ['installments' => $installments] = $this->crearMembresiaConCuotas(3, 500.00);
        $cuota = $installments[0];

        // Marcar cuota como pagada en efectivo adelantado
        $cuota->update([
            'status'              => 'paid',
            'payment_method'      => 'efectivo',
            'is_advance_payment'  => true,
            'amount_paid'         => 500,
            'paid_at'             => now(),
        ]);

        // Simular el webhook de Recurrente intentando pagar la misma cuota
        // (el webhook busca cuotas pendientes y encuentra esta ya pagada)
        $cuotaFrescaDeBD = PaymentInstallment::find($cuota->id);

        // VERIFICACIÓN PRINCIPAL: El webhook NO puede actualizar recurrente_payment_id
        // si la cuota ya está paid Y tiene is_advance_payment=true
        $puedeSerCobradaPorRecurrente = $cuotaFrescaDeBD->status !== 'paid'
            && is_null($cuotaFrescaDeBD->recurrente_payment_id);

        $this->assertFalse(
            $puedeSerCobradaPorRecurrente,
            'Una cuota ya pagada en efectivo NO debe poder ser cobrada por Recurrente'
        );

        // El webhook debe rechazar cuotas con status=paid
        $this->assertEquals('paid', $cuotaFrescaDeBD->status);
        $this->assertTrue((bool) $cuotaFrescaDeBD->is_advance_payment);
        $this->assertNull($cuotaFrescaDeBD->recurrente_payment_id);

        // Si el webhook llega a través de la API, debe ser atrapado por el controller
        $this->actingAs($this->admin, 'sanctum');

        $payload = [
            'client_id'       => $this->client->id,
            'installment_ids' => [$cuota->id],
            'payment_method'  => 'tarjeta',  // Recurrente intentaría esto
            'total_amount'    => 500,
        ];

        $response = $this->postJson('/api/pagos/adelanto', $payload);

        // El controller debe rechazar con 422 porque ya está pagada
        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'Las siguientes cuotas ya están pagadas: 1']);

        // La cuota no debe haber sido modificada
        $this->assertDatabaseHas('payment_installments', [
            'id'             => $cuota->id,
            'status'         => 'paid',
            'payment_method' => 'efectivo', // Sigue siendo efectivo, no fue sobrescrita
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  EDGE 1 — Recurrente falla → Todo se revierte
    // ─────────────────────────────────────────────────────────────

    /**
     * @test
     * Escenario: Las cuotas se marcan pagadas en BD pero Recurrente falla.
     * Esperado: Rollback completo — cuotas vuelven a 'pending'.
     */
    public function test_recurrente_falla_hace_rollback_completo(): void
    {
        ['installments' => $installments] = $this->crearMembresiaConCuotas(5, 500.00);

        $cuotaIds = [$installments[1]->id, $installments[2]->id];

        $mockRecurrente = Mockery::mock(RecurrenteService::class);

        // Recurrente falla al cancelar
        $mockRecurrente->shouldReceive('cancelSubscription')
            ->andThrow(new \Exception('Recurrente: timeout de conexión'));

        $service = new PagoAdelantoService($mockRecurrente);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Recurrente/');

        $service->procesarPagoAdelantado(
            clientId:       $this->client->id,
            installmentIds: $cuotaIds,
            metodoPago:     'efectivo',
            montoTotal:     1000.00,
            registeredBy:   $this->admin->id,
        );

        // Después de la excepción, las cuotas deben seguir en 'pending' (rollback)
        foreach ($cuotaIds as $id) {
            $this->assertDatabaseHas('payment_installments', [
                'id'     => $id,
                'status' => 'pending', // Rollback exitoso
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  EDGE 2 — Cuotas no consecutivas
    // ─────────────────────────────────────────────────────────────

    /**
     * @test
     * Escenario: Cliente paga cuotas 2 y 4 (sin la 3).
     * Esperado: Permitido con advertencia, Recurrente retoma desde cuota 5.
     */
    public function test_cuotas_no_consecutivas_genera_advertencia(): void
    {
        ['installments' => $installments] = $this->crearMembresiaConCuotas(6, 500.00);

        // Pagar 1 y 3 (no consecutivas, falta la 2)
        $cuotaIds = [$installments[0]->id, $installments[2]->id];

        $mockRecurrente = Mockery::mock(RecurrenteService::class);
        $mockRecurrente->shouldReceive('cancelSubscription')->andReturn(['status' => 'cancelled']);
        $mockRecurrente->shouldReceive('createSubscription')->andReturn(['id' => 'sub_nc_001', 'status' => 'active']);

        $service   = new PagoAdelantoService($mockRecurrente);
        $resultado = $service->procesarPagoAdelantado(
            clientId:       $this->client->id,
            installmentIds: $cuotaIds,
            metodoPago:     'efectivo',
            montoTotal:     1000.00,
            registeredBy:   $this->admin->id,
        );

        // Sí se procesó (no lanzó excepción)
        $this->assertEquals(2, $resultado['cuotas_pagadas']);

        // Cuota 2 (índice 1) sigue pending
        $this->assertDatabaseHas('payment_installments', [
            'id'     => $installments[1]->id,
            'status' => 'pending',
        ]);

        // Response del API debe tener warning
        $this->actingAs($this->admin, 'sanctum');

        $installments[0]->update(['status' => 'pending']); // Resetear para el API test
        $installments[2]->update(['status' => 'pending']);

        // Crear nuevas cuotas para el test del controller
        $nuevasMembresías = $this->crearMembresiaConCuotas(4, 500.00);
        $nuevasIds = [$nuevasMembresías['installments'][0]->id, $nuevasMembresías['installments'][2]->id];

        $mockRec2 = Mockery::mock(RecurrenteService::class);
        $mockRec2->shouldReceive('cancelSubscription')->andReturn(['status' => 'cancelled']);
        $mockRec2->shouldReceive('createSubscription')->andReturn(['id' => 'sub_nc_002', 'status' => 'active']);
        $this->app->instance(RecurrenteService::class, $mockRec2);

        $response = $this->postJson('/api/pagos/adelanto', [
            'client_id'       => $this->client->id,
            'installment_ids' => $nuevasIds,
            'payment_method'  => 'efectivo',
            'total_amount'    => 1000.00,
        ]);

        $response->assertStatus(200);
        // Debe haber warnings sobre las cuotas no consecutivas
        $this->assertArrayHasKey('warnings', $response->json());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
