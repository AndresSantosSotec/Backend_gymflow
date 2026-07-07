<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Models\RecurrenteSubscription;
use App\Services\RecurrenteService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * FIX 3.1 — Job/comando diario que reconcilia suscripciones activas
 * contra la API de Recurrente.
 *
 * Escenario que resuelve:
 * - Suscripción cancelada en Recurrente pero el webhook no llegó
 * - Cliente sigue accediendo al gym sin pagar
 *
 * Programar en routes/console.php o Kernel.php:
 *   Schedule::command('recurrente:sync-subscriptions')->daily();
 *
 * FIX 3.2 — También gestiona el período de gracia para suscripciones
 * en estado 'past_due': las desactiva si pasaron GRACE_PERIOD_DAYS.
 */
class RecurrenteSyncSubscriptions extends Command
{
    protected $signature   = 'recurrente:sync-subscriptions
                                {--dry-run : Simular sin hacer cambios}
                                {--grace-days=5 : Días de gracia para past_due}';

    protected $description = 'Reconcilia estado de suscripciones con Recurrente. Ejecutar diariamente.';

    public function __construct(private RecurrenteService $recurrente)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun     = $this->option('dry-run');
        $graceDays  = (int) $this->option('grace-days');

        $this->info("🔄 Sincronizando suscripciones con Recurrente...");
        $this->newLine();

        $synced     = 0;
        $deactivated = 0;
        $errors     = 0;

        // ── 1. Reconciliar suscripciones activas ─────────────────────────
        $active = RecurrenteSubscription::where('status', 'active')
            ->with(['client', 'membershipPlan'])
            ->get();

        $this->info("📋 Verificando {$active->count()} suscripciones activas...");

        foreach ($active as $sub) {
            try {
                // Consultar estado real en Recurrente
                $remoteStatus = $this->recurrente->getSubscription($sub->recurrente_subscription_id);
                $actualStatus = $remoteStatus['status'] ?? 'unknown';

                if ($actualStatus !== 'active') {
                    // FIX 3.1 — El webhook no llegó: sincronizar estado ahora
                    Log::warning(
                        "[SyncSubscriptions] Suscripción {$sub->recurrente_subscription_id} " .
                        "está '{$actualStatus}' en Recurrente pero 'active' local. Reconciliando."
                    );

                    if (! $dryRun) {
                        $sub->update(['status' => $actualStatus, 'metadata' => $remoteStatus]);

                        // Desactivar solo la membresía del mismo plan;
                        // no tocar otros servicios activos del cliente.
                        if (in_array($actualStatus, ['cancelled', 'expired', 'past_due'])) {
                            $membershipQuery = Membership::where('client_id', $sub->client_id)
                                ->where('status', 'active');

                            if (! is_null($sub->membership_plan_id)) {
                                $membershipQuery->where('plan_id', $sub->membership_plan_id);
                            }

                            $membershipQuery->update([
                                'status' => 'cancelled',
                                'payment_status' => 'cancelled',
                                'recurrente_status' => 'cancelled',
                            ]);

                            $this->warn("  🚫 Membresía desactivada: cliente #{$sub->client_id} ({$sub->client?->name})");
                            $deactivated++;
                        }
                    } else {
                        $this->warn("  [DRY-RUN] Suscripción {$sub->recurrente_subscription_id}: local=active, remoto={$actualStatus}");
                    }
                }

                $synced++;
                usleep(300_000); // 300ms entre requests

            } catch (\Exception $e) {
                $errors++;
                Log::error("[SyncSubscriptions] Error verificando {$sub->recurrente_subscription_id}: " . $e->getMessage());
                $this->warn("  ⚠ Error en {$sub->recurrente_subscription_id}: " . $e->getMessage());
            }
        }

        // ── 2. Gestionar suscripciones past_due con período de gracia ────
        $pastDue = RecurrenteSubscription::where('status', 'past_due')
            ->where('updated_at', '<=', Carbon::now()->subDays($graceDays))
            ->with(['client', 'membershipPlan'])
            ->get();

        $this->newLine();
        $this->info("⏰ {$pastDue->count()} suscripciones past_due superaron período de gracia ({$graceDays} días)...");

        foreach ($pastDue as $sub) {
            // FIX 3.2 — Período de gracia expirado: desactivar membresía
            Log::warning("[SyncSubscriptions] Suscripción {$sub->recurrente_subscription_id} past_due expirado. Desactivando.");

            if (! $dryRun) {
                $sub->update(['status' => 'expired']);

                $membershipQuery = Membership::where('client_id', $sub->client_id)
                    ->where('status', 'active');

                if (! is_null($sub->membership_plan_id)) {
                    $membershipQuery->where('plan_id', $sub->membership_plan_id);
                }

                $membershipQuery->update([
                    'status' => 'expired',
                    'payment_status' => 'overdue',
                    'recurrente_status' => 'expired',
                ]);

                $deactivated++;
                $this->warn("  🚫 Membresía expirada: cliente #{$sub->client_id} ({$sub->client?->name})");
            } else {
                $this->warn("  [DRY-RUN] Membresía a expirar: cliente #{$sub->client_id}");
            }
        }

        $this->newLine();
        $this->info("✅ Verificadas: {$synced}");
        $this->info("🚫 Desactivadas: {$deactivated}");

        if ($errors > 0) {
            $this->warn("⚠  Errores: {$errors} (ver logs)");
        }

        return self::SUCCESS;
    }
}
