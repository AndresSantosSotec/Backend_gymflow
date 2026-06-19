<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Receipt;
use App\Services\CorpoFel\CorpoFelClient;
use App\Services\ElectronicBillingService;

$receiptId = (int) ($argv[1] ?? 0);
if (!$receiptId) {
    $receipt = Receipt::whereNotNull('details->electronic_billing->uuid')
        ->where('details->electronic_billing->fel_status', 'certified')
        ->orderByDesc('id')
        ->first();
} else {
    $receipt = Receipt::find($receiptId);
}

if (!$receipt) {
    echo "No certified receipt found\n";
    exit(1);
}

$billing = $receipt->details['electronic_billing'] ?? [];
echo "Receipt: {$receipt->receipt_number} UUID: " . ($billing['uuid'] ?? 'n/a') . "\n";

$client = app(CorpoFelClient::class);
$info = $client->getDocumentInfo($billing['uuid']);
$decoded = $info['parsed']['data1_decoded'] ?? '';
$emissionDate = null;
if ($decoded && preg_match('/"Fecha_de_emision"\s*:\s*"([^"]+)"/', $decoded, $m)) {
    $emissionDate = $m[1];
}
echo "Emission from GET_INFODTE: " . ($emissionDate ?? 'unknown') . "\n\n";

$service = app(ElectronicBillingService::class);
$result = $service->cancelInvoice($receipt);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
