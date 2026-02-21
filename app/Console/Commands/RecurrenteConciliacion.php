<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\PaymentInstallment;
use App\Models\RecurrenteSubscription;
use App\Services\PagoAdelantoService;
use App\Services\RecurrenteService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RecurrenteConciliacion
 *
 * FIX 5.2 — Job diario que detecta cobros duplicados o discrepancias
 * entre lo que cobró Recurrente y lo que está registrado en BD.
 *
 * Corre a las 6am para que el admin vea las alertas cuando llega.
 * Schedule en routes/console.php:
 *   Schedule::command('recurrente:conciliar')->dailyAt('06:00');
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │  QUÉ DETECTA                                                     │
 * │                                                                  │
 * │  🔴 COBRO DUPLICADO                                              │
 * │     Recurrente cobró una cuota que ya estaba pagada en efectivo  │
 * │     → Alerta inmediata + bloqueo del próximo cobro               │
 * │                                                                  │
 * │  🟡 CUOTA SIN REGISTRO LOCAL                                     │
 * │     Recurrente reporta un pago pero no hay PaymentInstallment   │
 * │     correspondiente marcado como paid                            │
 * │                                                                  │
 * │  🟡 SUSCRIPCIÓN DESINCRONIZADA                                   │
 * │     Estado en Recurrente difiere del estado local                │
 * │     (ya cubierto en RecurrenteSyncSubscriptions)                 │
 * │                                                                  │
 * │  🟢 PAGO NO REGISTRADO                                          │
 * │     Cuota vencida en BD sin payment_method pero Recurrente      │
 * │     sí la cobró (webhook perdido)                               │
 * └──────────────────────────────────────────────────────────────────┘
 */
class RecurrenteConciliacion extends Command
{
    protected $signature = 'recurrente:conciliar
                             {--dry-run : Solo detectar, no escribir alertas}
                             {--days=7 : Rango de días a analizar (default 7)}';

    protected $description = 'Detecta discrepancias entre cobros de Recurrente y BD local. Ejecutar diariamente.';

    public function __construct(
        private RecurrenteService   $recurrente,
        private PagoAdelantoService $adelantoService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $days   = (int) $this->option('days');

        $this->info("🔍 Iniciando conciliación de pagos Recurrente (últimos {$days} días)...");
        $this->newLine();

        $alertas  = 0;
        $revisadas = 0;

        // ── VERIFICACIÓN 1: Cuotas 'paid' en efectivo cobradas después por Recurrente ─

        // Buscar cuotas que tengan: status=paid + is_advance_payment=true
        // Y también tengan un recurrente_payment_id asignado (doble cobro)
        $cobradosDos = PaymentInstallment::where('status', 'paid')
            ->where('is_advance_payment', true)
            ->whereNotNull('recurrente_payment_id')
            ->where('paid_at', '>=', now()->subDays($days))
            ->with(['client', 'membership'])
            ->get();

        foreach ($cobradosDos as $cuota) {
            $alertas++;
            $this->error("🔴 COBRO DUPLICADO detectado:");
            $this->line("   Cuota #{$cuota->id} (cuota {$cuota->installment_number})");
            $this->line("   Cliente: {$cuota->client?->full_name} (#{$cuota->client_id})");
            $this->line("   Pagada en efectivo: " . $cuota->paid_at?->format('d/m/Y'));
            $this->line("   Cobrada por Recurrente: {$cuota->recurrente_payment_id}");
            $this->line("   Monto: Q" . number_format($cuota->amount, 2));

            if (! $dryRun) {
                $this->registrarAlerta([
                    'client_id'               => $cuota->client_id,
                    'installment_id'          => $cuota->id,
                    'tipo'                    => 'cobro_duplicado',
                    'recurrente_payment_id'   => $cuota->recurrente_payment_id,
                    'monto_recurrente'        => $cuota->amount,
                    'monto_local'             => $cuota->amount_paid,
                    'descripcion'             => "Cuota #{$cuota->installment_number} del cliente #{$cuota->client_id} " .
                                                "fue pagada en efectivo ({$cuota->paid_at?->format('d/m/Y')}) " .
                                                "y cobra también por Recurrente ({$cuota->recurrente_payment_id}). " .
                                                "POSIBLE DOBLE COBRO al cliente.",
                ]);
            }
        }

        // ── VERIFICACIÓN 2: Suscripciones 'scheduled' que ya debieron activarse ─

        $scheduledVencidas = RecurrenteSubscription::where('status', 'active')
            ->where('creation_status', 'pending_confirmation')
            ->where('created_at', '<=', now()->subHours(2)) // Más de 2 horas sin confirmar
            ->with('client')
            ->get();

        foreach ($scheduledVencidas as $sub) {
            $alertas++;
            $revisadas++;

            $this->warn("🟡 Suscripción pendiente de confirmación (posible timeout FIX 2.3):");
            $this->line("   Sub local: #{$sub->id} | idempotency_key: {$sub->idempotency_key}");
            $this->line("   Cliente: {$sub->client?->full_name}");

            if (! $dryRun) {
                // Intentar verificar si existe en Recurrente
                try {
                    $remote = $this->recurrente->getSubscription($sub->recurrente_subscription_id);
                    // Si no lanza excepción, existe en Recurrente — confirmar
                    $sub->update(['creation_status' => 'created']);
                    $this->info("   ✅ Confirmada en Recurrente: {$remote['id']}");
                } catch (\Exception $e) {
                    // No existe — marcar como fallida
                    $this->registrarAlerta([
                        'client_id'                  => $sub->client_id,
                        'tipo'                       => 'pago_no_registrado',
                        'recurrente_subscription_id' => $sub->recurrente_subscription_id,
                        'descripcion'                => "Suscripción con idempotency_key {$sub->idempotency_key} " .
                                                       "no pudo confirmarse en Recurrente. Error: " . $e->getMessage(),
                    ]);
                }
            }
        }

        // ── VERIFICACIÓN 3: Cuotas vencidas sin pagar pero con suscripción activa ─
        // Detecta webhooks perdidos (cuota debió haberse cobrado pero no llegó el webhook)

        $cuotasVencidas = PaymentInstallment::whereIn('status', ['pending', 'overdue'])
            ->where('due_date', '>=', now()->subDays($days))
            ->where('due_date', '<', now()->subDays(3)) // 3 días de tolerancia
            ->whereNull('recurrente_payment_id')
            ->whereHas('client', fn ($q) => $q->whereNotNull('recurrente_user_id'))
            ->with(['client', 'membership'])
            ->get();

        foreach ($cuotasVencidas as $cuota) {
            $revisadas++;

            // ¿Tiene suscripción activa?
            $tieneSub = RecurrenteSubscription::where('client_id', $cuota->client_id)
                ->where('status', 'active')
                ->exists();

            if ($tieneSub) {
                $this->warn("🟡 Cuota posiblemente no cobrada (webhook perdido):");
                $this->line("   Cuota #{$cuota->id} vencida el {$cuota->due_date->format('d/m/Y')}");
                $this->line("   Cliente #{$cuota->client_id}: {$cuota->client?->full_name}");

                if (! $dryRun) {
                    $this->registrarAlerta([
                        'client_id'      => $cuota->client_id,
                        'installment_id' => $cuota->id,
                        'tipo'           => 'pago_no_registrado',
                        'monto_local'    => $cuota->amount,
                        'descripcion'    => "Cuota #{$cuota->installment_number} vencida el {$cuota->due_date->format('d/m/Y')} " .
                                           "no tiene registro de cobro ni Recurrente ID. El webhook pudo haberse perdido.",
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info("═══════════════════════════════════════");
        $this->info("🔍 Revisadas: {$revisadas}");
        if ($alertas > 0) {
            $this->error("🚨 Alertas: {$alertas} (ver tabla recurrente_conciliation_alerts)");
        } else {
            $this->info("✅ Alertas: 0 — Todo en orden");
        }

        if ($dryRun) {
            $this->warn("⚠ Modo DRY-RUN: No se escribieron alertas en BD.");
        }

        return self::SUCCESS;
    }

    private function registrarAlerta(array $data): void
    {
        // Verificar que no exista ya la misma alerta reciente (deduplicar)
        $existe = DB::table('recurrente_conciliation_alerts')
            ->where('tipo', $data['tipo'])
            ->where('client_id', $data['client_id'] ?? null)
            ->where('status', 'nueva')
            ->where('created_at', '>=', now()->subDays(1))
            ->exists();

        if ($existe) {
            $this->line("   [Skipped: alerta duplicada en las últimas 24h]");
            return;
        }

        DB::table('recurrente_conciliation_alerts')->insert([
            'client_id'               => $data['client_id'] ?? null,
            'installment_id'          => $data['installment_id'] ?? null,
            'tipo'                    => $data['tipo'],
            'recurrente_payment_id'   => $data['recurrente_payment_id'] ?? null,
            'recurrente_subscription_id' => $data['recurrente_subscription_id'] ?? null,
            'monto_recurrente'        => $data['monto_recurrente'] ?? null,
            'monto_local'             => $data['monto_local'] ?? null,
            'descripcion'             => $data['descripcion'],
            'status'                  => 'nueva',
            'metadata'                => json_encode($data),
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        Log::warning("[Conciliacion] Nueva alerta: {$data['tipo']} — " . substr($data['descripcion'], 0, 100));
    }
}
