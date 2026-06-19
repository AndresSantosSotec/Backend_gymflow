<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\Payment;
use App\Models\Receipt;
use App\Services\ElectronicBillingService;
use App\Services\FelPaymentService;

$client = Client::find(26) ?? Client::find(5);
if (!$client) {
    echo "No test client\n";
    exit(1);
}

$payment = Payment::create([
    'client_id' => $client->id,
    'amount' => 75,
    'payment_method' => 'transfer',
    'status' => 'completed',
    'notes' => 'Void test payment',
    'paid_at' => now(),
]);

$fel = app(FelPaymentService::class);
$result = $fel->processAfterPayment($payment, true);

echo "Certify: " . json_encode([
    'success' => $result['success'] ?? false,
    'fel_status' => $result['fel_status'] ?? null,
    'uuid' => $result['uuid'] ?? null,
    'error' => $result['error'] ?? null,
], JSON_UNESCAPED_UNICODE) . "\n";

if (!($result['success'] ?? false)) {
    exit(1);
}

$receipt = Receipt::find($result['receipt_id']);
sleep(2);

$void = app(ElectronicBillingService::class)->cancelInvoice($receipt->fresh());
echo "Void: " . json_encode([
    'success' => $void['success'] ?? false,
    'message' => $void['message'] ?? $void['error'] ?? null,
], JSON_UNESCAPED_UNICODE) . "\n";
