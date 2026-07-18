<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Próximas a vencer (7 días)
$expiringSoon = App\Models\Membership::with(['client','plan'])
    ->where('status', 'active')
    ->where('end_date', '<=', now()->addDays(7))
    ->where('end_date', '>=', now()->startOfDay())
    ->orderBy('end_date')
    ->limit(5)
    ->get();

echo "=== Próximas a vencer (7 días) ===\n";
foreach ($expiringSoon as $m) {
    echo $m->client->full_name . ' | ' . ($m->plan->name ?? 'N/A') . ' | ends: ' . $m->end_date . "\n";
}
echo "Total: " . $expiringSoon->count() . "\n\n";

// Ya vencidas
$expired = App\Models\Membership::with(['client','plan'])
    ->whereIn('status', ['active','expired'])
    ->where('end_date', '<', now()->startOfDay())
    ->orderByDesc('end_date')
    ->limit(5)
    ->get();

echo "=== Ya vencidas ===\n";
foreach ($expired as $m) {
    echo $m->client->full_name . ' | ' . ($m->plan->name ?? 'N/A') . ' | ends: ' . $m->end_date . " | status: " . $m->status . "\n";
}
echo "Total: " . $expired->count() . "\n";

