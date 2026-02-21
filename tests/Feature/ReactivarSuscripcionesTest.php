<?php

namespace Tests\Feature;

use App\Jobs\ReactivarSuscripcionesJob;
use App\Models\Client;
use App\Models\Membership;
use App\Models\MembershipPause;
use App\Models\MembershipPlan;
use App\Models\PaymentInstallment;
use App\Models\RecurrenteSubscription;
use App\Services\PagoAdelantoService;
use App\Services\RecurrenteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests del ciclo de vida: adelantos → reactivación automática
 *
 * Cómo correr:
 *   php artisan test --filter ReactivarSuscripcionesTest
 *
 * Requiere: RefreshDatabase (SQLite en memory para CI)
 */
class ReactivarSuscripcionesTest extends TestCase
{
    use RefreshDatabase;

    private RecurrenteService $recurrenteMock;
    private PagoAdelantoService $adelantoMock;
    private Client $client;
    private MembershipPlan $plan;
    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->recurrenteMock = \Mockery::mock(RecurrenteService::class);
        $this->adelantoMock   = \Mockery::mock(PagoAdelantoService::class);

        $this->app->instance(RecurrenteService::class, $this->recurrenteMock);
        $this->app->instance(PagoAdelantoService::class, $this->adelantoMock);

        // Setup datos base
        $this->plan = MembershipPlan::create([
            'name'                  => 'Plan Premium',
            'price'                 => 300.00,
            'duration_days'         => 30,
            'recurrente_product_id' => 'prod_test_123',
            'is_active'             => true,
        ]);

        $this->client = Client::create([
            'first_name'                  => 'Juan',
            'last_name'                   => 'Pérez',
            'email'                       => 'juan@test.com',
            'qr_code'                     => 'QR_TEST_001',
            'recurrente_user_id'          => 'us_test_abc',
            'recurrente_payment_method_id' => 'pay_m_test_xyz',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  CASO 1 — Flujo principal: reactivación automática
    // ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * Dado: membresía con advance_end_date = HOY y wants_auto_renewal = true
     * Cuando: corre ReactivarSuscripcionesJob
     * Entonces: Recurrente crea nueva suscripción y status → active
     */
    public function test_reactiva_automaticamente_cuando_vence_adelanto()
    {
        $this->membership = Membership::create([
            'client_id'        => $this->client->id,
            'plan_id'          => $this->plan->id,
            'name'             => 'Plan Premium',
            'price'            => 300.00,
            'duration_days'    => 30,
            'start_date'       => today()->subMonths(5),
            'end_date'         => today()->addDays(1),
            'status'           => Membership::STATUS_ADVANCE_EXPIRING,
            'advance_end_date' => today()->toDateString(),
            'wants_auto_renewal' => true,
            'payment_type'     => 'installments',
            'total_amount'     => 1500.00,
            'payment_status'   => 'partial',
        ]);

        // Audit mock (llamado 1x por reactivación)
        $this->adelantoMock
            ->shouldReceive('writeAuditLog')
            ->once()
            ->andReturn(true);

        // Recurrente crea suscripción exitosamente
        $this->recurrenteMock
            ->shouldReceive('createSubscription')
            ->once()
            ->with(\Mockery::on(fn($data) =>
                $data['user_id']    === 'us_test_abc' &&
                $data['product_id'] === 'prod_test_123'
            ))
            ->andReturn(['id' => 'sub_nuevo_123', 'status' => 'active']);

        // Correr el job sincrónicamente
        app(ReactivarSuscripcionesJob::class)->handle($this->recurrenteMock, $this->adelantoMock);

        // Verificar estados en BD
        $this->membership->refresh();
        $this->assertEquals(Membership::STATUS_ACTIVE, $this->membership->status);
        $this->assertNotNull($this->membership->reactivated_at);
        $this->assertNull($this->membership->advance_end_date);

        // Verificar que se creó la sub local
        $this->assertDatabaseHas('recurrente_subscriptions', [
            'client_id'                  => $this->client->id,
            'recurrente_subscription_id' => 'sub_nuevo_123',
            'creation_status'            => 'created',
        ]);

        // Verificar email enviado al cliente
        Mail::assertSent(fn ($mail) => $mail->hasTo('juan@test.com'));
    }

    /**
     * @test
     * Dado: membresía con wants_auto_renewal = false
     * Cuando: corre el Job
     * Entonces: NO se reactiva (skip silencioso)
     */
    public function test_no_reactiva_si_wants_auto_renewal_es_false()
    {
        $this->membership = Membership::create([
            'client_id'          => $this->client->id,
            'plan_id'            => $this->plan->id,
            'name'               => 'Plan Premium',
            'price'              => 300.00,
            'duration_days'      => 30,
            'start_date'         => today()->subMonths(5),
            'end_date'           => today()->addDays(1),
            'status'             => Membership::STATUS_ADVANCE_ACTIVE,
            'advance_end_date'   => today()->toDateString(),
            'wants_auto_renewal' => false,      // ← NO quiere reactivación
            'payment_type'       => 'single',
            'total_amount'       => 300,
            'payment_status'     => 'paid',
        ]);

        // Recurrente NO debe ser llamado
        $this->recurrenteMock->shouldNotReceive('createSubscription');

        app(ReactivarSuscripcionesJob::class)->handle($this->recurrenteMock, $this->adelantoMock);

        // Status no cambió
        $this->membership->refresh();
        $this->assertEquals(Membership::STATUS_ADVANCE_ACTIVE, $this->membership->status);
    }

    /**
     * @test
     * ANOMALÍA A — Cliente canceló manualmente antes de que corra el Job
     */
    public function test_anomalia_a_skip_si_membresia_ya_cancelada()
    {
        $this->membership = Membership::create([
            'client_id'          => $this->client->id,
            'plan_id'            => $this->plan->id,
            'name'               => 'Plan Premium',
            'price'              => 300.00,
            'duration_days'      => 30,
            'start_date'         => today()->subMonths(5),
            'end_date'           => today()->addDays(1),
            'status'             => Membership::STATUS_CANCELLED,  // ← Ya cancelada
            'advance_end_date'   => today()->toDateString(),
            'wants_auto_renewal' => true,
            'payment_type'       => 'single',
            'total_amount'       => 300,
            'payment_status'     => 'paid',
        ]);

        // El scope advancedExpiringToday filtra por status IN(advance_active, advance_expiring)
        // entonces cancelled no aparece → no se llama nada
        $this->recurrenteMock->shouldNotReceive('createSubscription');

        app(ReactivarSuscripcionesJob::class)->handle($this->recurrenteMock, $this->adelantoMock);

        $this->membership->refresh();
        $this->assertEquals(Membership::STATUS_CANCELLED, $this->membership->status);
    }

    /**
     * @test
     * ANOMALÍA B — payment_method_id expiró durante los adelantos
     */
    public function test_anomalia_b_payment_method_expirado_pasa_a_en_riesgo()
    {
        $this->membership = Membership::create([
            'client_id'          => $this->client->id,
            'plan_id'            => $this->plan->id,
            'name'               => 'Plan Premium',
            'price'              => 300.00,
            'duration_days'      => 30,
            'start_date'         => today()->subMonths(5),
            'end_date'           => today()->addDays(1),
            'status'             => Membership::STATUS_ADVANCE_EXPIRING,
            'advance_end_date'   => today()->toDateString(),
            'wants_auto_renewal' => true,
            'payment_type'       => 'single',
            'total_amount'       => 300,
            'payment_status'     => 'paid',
        ]);

        // getPaymentMethods retorna lista vacía → el token no existe
        $this->recurrenteMock
            ->shouldReceive('getPaymentMethods')
            ->once()
            ->with('us_test_abc')
            ->andReturn([]); // ← token no encontrado

        // Después de limpiar el token, intenta crear la sub con recurrente_user_id vacío
        // y falla → at_risk
        $this->recurrenteMock
            ->shouldReceive('createSubscription')
            ->andThrow(new \Exception("No hay payment_method_id válido"));

        $this->adelantoMock
            ->shouldReceive('writeAuditLog')
            ->never();

        app(ReactivarSuscripcionesJob::class)->handle($this->recurrenteMock, $this->adelantoMock);

        $this->membership->refresh();
        $this->assertEquals(Membership::STATUS_AT_RISK, $this->membership->status);
        $this->assertNotNull($this->membership->reactivation_error);
        $this->assertNull($this->client->fresh()->recurrente_payment_method_id);
    }

    /**
     * @test
     * ANOMALÍA E — Job falla pero tiene retry: la segunda ejecución usa idempotency_key
     */
    public function test_anomalia_e_idempotencia_en_retry_del_job()
    {
        $this->membership = Membership::create([
            'client_id'          => $this->client->id,
            'plan_id'            => $this->plan->id,
            'name'               => 'Plan Premium',
            'price'              => 300.00,
            'duration_days'      => 30,
            'start_date'         => today()->subMonths(5),
            'end_date'           => today()->addDays(1),
            'status'             => Membership::STATUS_ADVANCE_EXPIRING,
            'advance_end_date'   => today()->toDateString(),
            'wants_auto_renewal' => true,
            'payment_type'       => 'single',
            'total_amount'       => 300,
            'payment_status'     => 'paid',
        ]);

        // Simular que YA existe la suscripción con idempotency_key (retry del Job)
        $idempotencyKey = 'reactivar_' . $this->membership->id . '_' . today()->format('Ymd');
        RecurrenteSubscription::create([
            'client_id'                  => $this->client->id,
            'membership_plan_id'         => $this->plan->id,
            'recurrente_subscription_id' => 'sub_ya_creada_123',
            'recurrente_product_id'      => 'prod_test_123',
            'status'                     => 'active',
            'idempotency_key'            => $idempotencyKey,
            'creation_status'            => 'created', // ← ya se creó
        ]);

        // Recurrente NO debe ser llamado de nuevo
        $this->recurrenteMock->shouldNotReceive('createSubscription');

        $this->adelantoMock->shouldReceive('writeAuditLog')->never();

        app(ReactivarSuscripcionesJob::class)->handle($this->recurrenteMock, $this->adelantoMock);

        // La membresía igual debe quedar en active (idempotent)
        $this->membership->refresh();
        $this->assertEquals(Membership::STATUS_ACTIVE, $this->membership->status);
    }

    // ─────────────────────────────────────────────────────────────────
    //  CASO 1 — Alerta 7 días antes
    // ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * Dado: membresía advance_active con advance_end_date = HOY+7
     * Cuando: corre el Job
     * Entonces: status → advance_expiring (alerta emitida)
     */
    public function test_emite_alerta_7_dias_antes_del_vencimiento()
    {
        $this->membership = Membership::create([
            'client_id'          => $this->client->id,
            'plan_id'            => $this->plan->id,
            'name'               => 'Plan Premium',
            'price'              => 300.00,
            'duration_days'      => 30,
            'start_date'         => today()->subMonths(4),
            'end_date'           => today()->addMonths(1),
            'status'             => Membership::STATUS_ADVANCE_ACTIVE,
            'advance_end_date'   => today()->addDays(7)->toDateString(),  // ← en 7 días
            'wants_auto_renewal' => true,
            'payment_type'       => 'single',
            'total_amount'       => 300,
            'payment_status'     => 'partial',
        ]);

        $this->adelantoMock
            ->shouldReceive('writeAuditLog')
            ->once()
            ->with(\Mockery::on(fn ($args) => true));

        // NO debe crear suscripción (aún no vence)
        $this->recurrenteMock->shouldNotReceive('createSubscription');

        app(ReactivarSuscripcionesJob::class)->handle($this->recurrenteMock, $this->adelantoMock);

        $this->membership->refresh();
        $this->assertEquals(Membership::STATUS_ADVANCE_EXPIRING, $this->membership->status);

        // Email de alerta enviado al cliente
        Mail::assertSent(fn ($mail) => $mail->hasTo('juan@test.com'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  CASO 2 — Pausa: reanudar pausas vencidas
    // ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * Dado: membresía pausada con pause_end = HOY
     * Cuando: corre el Job
     * Entonces: Recurrente crea nueva sub y status → active
     */
    public function test_reanuda_pausa_que_termina_hoy()
    {
        $this->membership = Membership::create([
            'client_id'     => $this->client->id,
            'plan_id'       => $this->plan->id,
            'name'          => 'Plan Premium',
            'price'         => 300.00,
            'duration_days' => 30,
            'start_date'    => today()->subMonths(2),
            'end_date'      => today()->addMonths(3),
            'status'        => Membership::STATUS_PAUSED,
            'payment_type'  => 'single',
            'total_amount'  => 300,
            'payment_status' => 'partial',
        ]);

        MembershipPause::create([
            'membership_id' => $this->membership->id,
            'client_id'     => $this->client->id,
            'pause_start'   => today()->subDays(14)->toDateString(),
            'pause_end'     => today()->toDateString(),        // ← vence HOY
            'pause_days'    => 14,
            'reason'        => 'travel',
            'status'        => 'active',
        ]);

        $this->recurrenteMock
            ->shouldReceive('createSubscription')
            ->once()
            ->andReturn(['id' => 'sub_post_pausa_456', 'status' => 'active']);

        app(ReactivarSuscripcionesJob::class)->handle($this->recurrenteMock, $this->adelantoMock);

        $this->membership->refresh();
        $this->assertEquals(Membership::STATUS_ACTIVE, $this->membership->status);

        $this->assertDatabaseHas('membership_pauses', [
            'membership_id' => $this->membership->id,
            'status'        => 'completed',
        ]);

        $this->assertDatabaseHas('recurrente_subscriptions', [
            'recurrente_subscription_id' => 'sub_post_pausa_456',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  CASO 2 — PausarMembresiaService: validaciones
    // ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * Dado: límite de pausa excedido
     * Cuando: se intenta pausar
     * Entonces: Exception con mensaje de límite
     */
    public function test_pausa_falla_si_excede_limite_de_dias()
    {
        $this->membership = Membership::create([
            'client_id'         => $this->client->id,
            'plan_id'           => $this->plan->id,
            'name'              => 'Plan Premium',
            'price'             => 300.00,
            'duration_days'     => 30,
            'start_date'        => today(),
            'end_date'          => today()->addDays(180),
            'status'            => Membership::STATUS_ACTIVE,
            'max_pause_days'    => 30,         // ← Límite de 30 días
            'total_paused_days' => 25,         // ← Ya usó 25 días
            'payment_type'      => 'single',
            'total_amount'      => 300,
            'payment_status'    => 'partial',
        ]);

        $service = app(\App\Services\PausarMembresiaService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Límite de días de pausa excedido/');

        $service->pausar(
            membershipId: $this->membership->id,
            pauseStart:   today()->toDateString(),
            pauseEnd:     today()->addDays(30)->toDateString(), // 30 días solicitados, pero solo 5 disponibles
            reason:       'other',
            notes:        '',
            adminId:      1,
        );
    }

    /**
     * @test
     * Dado: membresía pausa ya activa
     * Cuando: se intenta crear otra pausa
     * Entonces: Exception "ya tiene una pausa activa"
     */
    public function test_pausa_falla_si_ya_existe_una_pausa_activa()
    {
        $this->membership = Membership::create([
            'client_id'      => $this->client->id,
            'plan_id'        => $this->plan->id,
            'name'           => 'Plan Premium',
            'price'          => 300.00,
            'duration_days'  => 30,
            'start_date'     => today(),
            'end_date'       => today()->addDays(180),
            'status'         => Membership::STATUS_PAUSED,
            'max_pause_days' => 90,
            'payment_type'   => 'single',
            'total_amount'   => 300,
            'payment_status' => 'partial',
        ]);

        MembershipPause::create([
            'membership_id' => $this->membership->id,
            'client_id'     => $this->client->id,
            'pause_start'   => today()->toDateString(),
            'pause_end'     => today()->addDays(30)->toDateString(),
            'pause_days'    => 30,
            'reason'        => 'travel',
            'status'        => 'active',  // ← Ya existe pausa activa
        ]);

        $service = app(\App\Services\PausarMembresiaService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/ya tiene una pausa activa/');

        $service->pausar(
            membershipId: $this->membership->id,
            pauseStart:   today()->addDays(35)->toDateString(),
            pauseEnd:     today()->addDays(50)->toDateString(),
            reason:       'travel',
            notes:        '',
            adminId:      1,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  CASO 4 — Reactivar tarjeta
    // ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * Dado: cuotas anteriores sin pagar
     * Cuando: se intenta reactivar tarjeta
     * Entonces: Exception porque deben estar pagadas primero
     */
    public function test_reactivar_tarjeta_falla_si_cuotas_anteriores_sin_pagar()
    {
        $this->membership = Membership::create([
            'client_id'      => $this->client->id,
            'plan_id'        => $this->plan->id,
            'name'           => 'Plan Premium',
            'price'          => 300.00,
            'duration_days'  => 30,
            'start_date'     => today()->subMonths(3),
            'end_date'       => today()->addMonths(3),
            'status'         => Membership::STATUS_ADVANCE_ACTIVE,
            'payment_type'   => 'installments',
            'num_installments' => 6,
            'total_amount'   => 1800,
            'payment_status' => 'partial',
        ]);

        // Cuota 1 SIN PAGAR
        $cuota1 = PaymentInstallment::create([
            'membership_id'      => $this->membership->id,
            'client_id'          => $this->client->id,
            'installment_number' => 1,
            'amount'             => 300,
            'due_date'           => today()->subMonths(2),
            'status'             => 'overdue', // ← Sin pagar
        ]);

        // Cuota 2 (la que queremos reactivar desde aquí)
        $cuota2 = PaymentInstallment::create([
            'membership_id'      => $this->membership->id,
            'client_id'          => $this->client->id,
            'installment_number' => 2,
            'amount'             => 300,
            'due_date'           => today()->subMonths(1),
            'status'             => 'paid',
        ]);

        $service = app(\App\Services\PausarMembresiaService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/cuotas anteriores sin pagar/');

        $service->reactivarTarjeta(
            clientId:          $this->client->id,
            paymentMethodId:   'pay_m_test_xyz',
            fromInstallmentId: $cuota2->id,
            adminId:           1,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  MODELO — Accessors y métodos
    // ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * recalculateAdvanceEndDate() con 5 cuotas adelantadas pagadas
     * → advance_end_date = último día del mes de la cuota más reciente
     */
    public function test_calcular_estados_de_adelanto_correctamente()
    {
        $this->membership = Membership::create([
            'client_id'      => $this->client->id,
            'plan_id'        => $this->plan->id,
            'name'           => 'Plan Premium',
            'price'          => 300.00,
            'duration_days'  => 30,
            'start_date'     => today()->subMonths(1),
            'end_date'       => today()->addDays(180),
            'status'         => Membership::STATUS_ACTIVE,
            'payment_type'   => 'installments',
            'num_installments' => 6,
            'total_amount'   => 1800,
            'payment_status' => 'partial',
        ]);

        // Simular 5 cuotas adelantadas pagadas, la última vence en 3 meses
        $ultimaFecha = today()->addMonths(4);
        foreach (range(1, 5) as $i) {
            PaymentInstallment::create([
                'membership_id'      => $this->membership->id,
                'client_id'          => $this->client->id,
                'installment_number' => $i,
                'amount'             => 300,
                'amount_paid'        => 300,
                'due_date'           => today()->addMonths($i),
                'status'             => 'paid',
                'is_advance_payment' => true,
            ]);
        }

        $this->membership->recalculateAdvanceEndDate();
        $this->membership->refresh();

        // advance_end_date debería ser en el futuro (> EXPIRING_ALERT_DAYS)
        $this->assertEquals(Membership::STATUS_ADVANCE_ACTIVE, $this->membership->status);
        $this->assertNotNull($this->membership->advance_end_date);

        // Verificar que puede_pausar funciona correctamente
        $this->assertTrue($this->membership->canPause(15));
        $this->assertFalse($this->membership->canPause(200)); // excede max_pause_days=60
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
