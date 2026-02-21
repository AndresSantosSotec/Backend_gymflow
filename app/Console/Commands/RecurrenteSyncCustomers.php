<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\RecurrenteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Comando: php artisan recurrente:sync-customers
 *
 * Itera todos los clientes sin recurrente_user_id
 * y los registra en la plataforma Recurrente.
 */
class RecurrenteSyncCustomers extends Command
{
    protected $signature   = 'recurrente:sync-customers
                                {--limit=0 : Número máximo de clientes a sincronizar (0 = todos)}
                                {--dry-run : Simular sin hacer llamadas reales}';

    protected $description = 'Sincroniza clientes del gym con la plataforma de pago Recurrente';

    public function __construct(private RecurrenteService $recurrente)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit  = (int) $this->option('limit');

        $query = Client::whereNull('recurrente_user_id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $clients = $query->get();
        $total   = $clients->count();

        if ($total === 0) {
            $this->info('✅ Todos los clientes ya están sincronizados.');
            return self::SUCCESS;
        }

        $this->info("📤 Sincronizando {$total} clientes con Recurrente...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced  = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($clients as $client) {
            try {
                if ($dryRun) {
                    $this->line("  [DRY-RUN] Cliente #{$client->id}: {$client->name}");
                    $bar->advance();
                    continue;
                }

                // Separar nombre en first/last para la API
                $nameParts = explode(' ', trim($client->name), 2);
                $firstName = $nameParts[0];
                $lastName  = $nameParts[1] ?? '-';

                // POST /api/users
                $response = $this->recurrente->createUser([
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'email'      => $client->email ?? "client{$client->id}@gymflow.local",
                    'phone'      => $client->phone ?? null,
                ]);

                $client->update([
                    'recurrente_user_id' => $response['id'] ?? null,
                ]);

                $synced++;
                Log::info("Recurrente sync: cliente #{$client->id} → recurrente_user_id={$response['id']}");

            } catch (\Exception $e) {
                $failed++;
                $errors[] = "Cliente #{$client->id} ({$client->name}): " . $e->getMessage();
                Log::error("Recurrente sync error for client #{$client->id}: " . $e->getMessage());
            }

            $bar->advance();
            usleep(200_000); // 200ms entre requests para no saturar la API
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Sincronizados: {$synced}");
        if ($failed > 0) {
            $this->warn("⚠️  Fallidos:       {$failed}");
            foreach ($errors as $err) {
                $this->line("   • {$err}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
