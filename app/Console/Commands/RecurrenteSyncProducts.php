<?php

namespace App\Console\Commands;

use App\Models\MembershipPlan;
use App\Services\RecurrenteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Comando: php artisan recurrente:sync-products
 *
 * Itera los planes de membresía sin recurrente_product_id
 * y los crea como productos en Recurrente.
 */
class RecurrenteSyncProducts extends Command
{
    protected $signature   = 'recurrente:sync-products
                                {--dry-run : Simular sin hacer llamadas reales}';

    protected $description = 'Sincroniza planes de membresía como productos en Recurrente';

    public function __construct(private RecurrenteService $recurrente)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $plans = MembershipPlan::whereNull('recurrente_product_id')->get();
        $total = $plans->count();

        if ($total === 0) {
            $this->info('✅ Todos los planes ya están sincronizados con Recurrente.');
            return self::SUCCESS;
        }

        $this->info("📦 Sincronizando {$total} planes con Recurrente...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failed = 0;

        foreach ($plans as $plan) {
            try {
                if ($dryRun) {
                    $this->line("  [DRY-RUN] Plan #{$plan->id}: {$plan->name} @ Q{$plan->price}");
                    $bar->advance();
                    continue;
                }

                // Precio en centavos (GTQ)
                // Recurrente usa enteros: Q125.00 → 12500
                $priceInCents = (int) round(floatval($plan->price) * 100);

                // POST /api/products
                $response = $this->recurrente->createProduct([
                    'name'           => $plan->name,
                    'price_in_cents' => $priceInCents,
                    'currency'       => 'GTQ',
                    'description'    => $plan->description ?? "Membresía {$plan->name} - {$plan->duration_days} días",
                ]);

                $plan->update([
                    'recurrente_product_id' => $response['id'] ?? null,
                ]);

                $synced++;
                Log::info("Recurrente sync: plan #{$plan->id} → recurrente_product_id={$response['id']}");

            } catch (\Exception $e) {
                $failed++;
                Log::error("Recurrente sync error for plan #{$plan->id}: " . $e->getMessage());
                $this->warn("  ⚠ Plan #{$plan->id}: " . $e->getMessage());
            }

            $bar->advance();
            usleep(300_000); // 300ms entre requests
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Planes sincronizados: {$synced}");
        if ($failed > 0) {
            $this->warn("⚠️  Fallidos: {$failed}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
