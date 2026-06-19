<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\CorpoFel\CorpoFelClient;
use App\Services\CorpoFel\FelDteBuilder;

$cfg = config('billing.corpo_fel');
echo "=== Config FEL cargada ===\n";
echo json_encode([
    'use_test' => $cfg['use_test'],
    'entity_nit' => $cfg['entity_nit'],
    'requestor' => substr($cfg['requestor'], 0, 8) . '...',
    'establecimiento' => $cfg['codigo_establecimiento'],
    'emisor' => $cfg['emisor_nombre'],
    'comercial' => $cfg['emisor_nombre_comercial'],
    'frase' => ($cfg['frase_tipo'] ?? '1') . '/' . ($cfg['frase_escenario'] ?? '2'),
    'afiliacion_iva' => $cfg['afiliacion_iva'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$client = app(CorpoFelClient::class);

echo "=== GetNIT emisor 29602629 ===\n";
$nit = $client->consultNit('29602629');
echo json_encode([
    'success' => $nit['success'] ?? false,
    'name' => $nit['data']['messageContent'] ?? $nit['data']['message'] ?? null,
    'error' => $nit['error'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== Establecimientos SAT ===\n";
$est = $client->getEstablishments('29602629');
$items = $est['parsed']['data1_json'] ?? $est['parsed']['data2_json'] ?? null;
if (is_array($items)) {
    echo json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo json_encode([
        'success' => $est['success'] ?? false,
        'error' => $est['error'] ?? null,
        'snippet' => substr($est['parsed']['data1'] ?? $est['raw'] ?? '', 0, 500),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Frases SAT ===\n";
$phrases = $client->getPhrases('29602629');
echo json_encode([
    'success' => $phrases['success'] ?? false,
    'data' => $phrases['parsed']['data1_json'] ?? $phrases['parsed']['data1'] ?? null,
    'error' => $phrases['error'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== Frase en XML (muestra) ===\n";
if (preg_match('/<dte:Frase[^>]+>/', '<?xml?><dte:Frase TipoFrase="' . ($cfg['frase_tipo'] ?? '1') . '" CodigoEscenario="' . ($cfg['frase_escenario'] ?? '2') . '"/>', $m)) {
    echo $m[0] . "\n";
}
