<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Receipt;
use App\Services\CorpoFel\CorpoFelClient;

$receiptId = (int) ($argv[1] ?? 44);
$receipt = Receipt::find($receiptId);
if (!$receipt) {
    echo "Receipt not found\n";
    exit(1);
}

$eb = $receipt->details['electronic_billing'] ?? [];
$guid = $eb['uuid'] ?? null;
echo "receipt_id={$receipt->id} number={$receipt->receipt_number} uuid={$guid}\n";

if (!$guid) {
    echo "No UUID\n";
    exit(1);
}

$client = app(CorpoFelClient::class);
$pdf = $client->getDocumentPdf($guid);

echo json_encode([
    'success' => $pdf['success'] ?? false,
    'error' => $pdf['error'] ?? null,
    'result' => $pdf['parsed']['result'] ?? null,
    'description' => $pdf['parsed']['description'] ?? null,
    'has_data1' => !empty($pdf['parsed']['data1']),
    'has_data2' => !empty($pdf['parsed']['data2']),
    'data1_len' => isset($pdf['parsed']['data1']) ? strlen($pdf['parsed']['data1']) : 0,
    'data2_len' => isset($pdf['parsed']['data2']) ? strlen($pdf['parsed']['data2']) : 0,
    'data2_starts' => isset($pdf['parsed']['data2']) ? substr($pdf['parsed']['data2'], 0, 40) : null,
    'raw_snip' => substr($pdf['raw'] ?? '', 0, 1200),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
