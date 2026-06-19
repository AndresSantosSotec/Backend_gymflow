<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = app(App\Services\CorpoFel\CorpoFelClient::class);

echo "=== Config FEL ===\n";
echo json_encode([
    'provider' => config('billing.provider'),
    'enabled' => config('billing.corpo_fel.enabled'),
    'use_test' => config('billing.corpo_fel.use_test'),
    'requestor' => config('billing.corpo_fel.requestor'),
    'entity_nit' => config('billing.corpo_fel.entity_nit'),
], JSON_PRETTY_PRINT) . "\n\n";

echo "=== Consulta NIT (prueba: 6407846) ===\n";
$nit = $client->consultNit('6407846');
echo json_encode([
    'success' => $nit['success'] ?? false,
    'error' => $nit['error'] ?? null,
    'sample' => is_array($nit['data'] ?? null) ? array_slice($nit['data'], 0, 5) : ($nit['raw'] ?? null),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
