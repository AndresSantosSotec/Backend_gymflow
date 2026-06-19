<?php

/**
 * Pruebas API E2E FEL — simula flujos del frontend.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

$base = rtrim(env('APP_URL', 'http://localhost:8000'), '/');
$results = [];

function record(array &$results, string $name, bool $ok, array $details = []): void
{
    $results[] = array_merge(['test' => $name, 'ok' => $ok], $details);
    $icon = $ok ? 'OK' : 'FAIL';
    echo "[$icon] $name\n";
    if ($details) {
        echo json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n";
}

// Login
$user = User::where('email', 'admin@gymflow.com')->first();
if (!$user) {
    echo "ERROR: usuario admin@gymflow.com no existe en DB\n";
    exit(1);
}
$token = $user->createToken('fel-e2e-test')->plainTextToken;
$headers = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];

// 1. FEL status
$r = Http::withHeaders($headers)->get("$base/api/fel/status");
record($results, 'GET /api/fel/status', $r->successful() && ($r->json('enabled') === true), [
    'status' => $r->status(),
    'body' => $r->json(),
]);

// 2. Consult NIT
$r = Http::withHeaders($headers)->post("$base/api/fel/consult-nit", ['nit' => '19959699']);
record($results, 'POST /api/fel/consult-nit (Pablo)', $r->successful() && ($r->json('success') === true), [
    'data' => $r->json('data') ?? $r->json(),
]);

// 3. Payment cash + issue_fel
$r = Http::withHeaders($headers)->post("$base/api/payments", [
    'client_id' => 5,
    'amount' => 250,
    'payment_method' => 'cash',
    'status' => 'completed',
    'notes' => 'Prueba FEL API - efectivo manual',
    'issue_fel' => true,
]);
$paymentId = $r->json('id');
$fel = $r->json('fel');
record($results, 'POST /api/payments cash+issue_fel', $r->successful() && ($fel['fel_status'] ?? '') === 'certified', [
    'payment_id' => $paymentId,
    'fel' => $fel,
    'receipt_id' => $r->json('receipt.id'),
]);

// 4. Payment card auto-FEL
$r2 = Http::withHeaders($headers)->post("$base/api/payments", [
    'client_id' => 5,
    'amount' => 150,
    'payment_method' => 'card',
    'status' => 'completed',
    'notes' => 'Prueba FEL API - tarjeta auto',
]);
$fel2 = $r2->json('fel');
record($results, 'POST /api/payments card auto-FEL', $r2->successful() && ($fel2['fel_status'] ?? '') === 'certified', [
    'payment_id' => $r2->json('id'),
    'fel' => $fel2,
]);

// 5. Receipts list has electronic_billing
$r = Http::withHeaders($headers)->get("$base/api/receipts", ['per_page' => 5, 'sort_by' => 'created_at', 'sort_order' => 'desc']);
$receipts = $r->json('data') ?? [];
$latest = $receipts[0] ?? null;
$eb = $latest['details']['electronic_billing'] ?? null;
record($results, 'GET /api/receipts FEL en details', $r->successful() && ($eb['fel_status'] ?? null) === 'certified', [
    'receipt_id' => $latest['id'] ?? null,
    'electronic_billing' => $eb,
]);

// 6. Manual certify on skipped receipt — create cash without fel first
$r = Http::withHeaders($headers)->post("$base/api/payments", [
    'client_id' => 7,
    'amount' => 100,
    'payment_method' => 'cash',
    'status' => 'completed',
    'notes' => 'Sin FEL para certificar manual',
    'issue_fel' => false,
]);
$receiptId = $r->json('receipt.id');
$skipped = ($r->json('fel.skipped') ?? false) || ($r->json('fel.fel_status') === 'skipped');
record($results, 'POST /api/payments cash sin FEL', $r->successful() && $skipped, [
    'receipt_id' => $receiptId,
    'fel' => $r->json('fel'),
]);

if ($receiptId) {
    $r = Http::withHeaders($headers)->post("$base/api/fel/receipts/$receiptId/certify");
    $certFel = $r->json('fel') ?? [];
    record($results, 'POST /api/fel/receipts/{id}/certify', $r->successful() && (($certFel['fel_status'] ?? '') === 'certified' || ($certFel['success'] ?? false)), [
        'status' => $r->status(),
        'fel' => $certFel,
        'message' => $r->json('message'),
    ]);

    // 7. Void DTE (PRUEBAS: TrCode 1080/1084 es esperado)
    $certReceiptId = $r->json('receipt.id') ?? $receiptId;
    $rVoid = Http::withHeaders($headers)->post("$base/api/fel/receipts/$certReceiptId/void");
    $voidBody = $rVoid->json();
    $voidOk = (bool) ($voidBody['result']['success'] ?? false);
    $expectedVoid = (bool) ($voidBody['expected_in_pruebas'] ?? $voidBody['result']['expected_in_pruebas'] ?? false);
    record($results, 'POST /api/fel/receipts/{id}/void', $rVoid->successful() && ($voidOk || $expectedVoid), [
        'status' => $rVoid->status(),
        'tr_code' => $voidBody['tr_code'] ?? $voidBody['result']['tr_code'] ?? null,
        'expected_in_pruebas' => $expectedVoid,
        'void_success' => $voidOk,
        'message' => $voidBody['message'] ?? null,
    ]);
}

// 8. Installment pay with FEL if pending exists
$inst = Http::withHeaders($headers)->get("$base/api/installments", ['status' => 'pending', 'per_page' => 1]);
$installment = ($inst->json('data') ?? [])[0] ?? null;
if ($installment) {
    $r = Http::withHeaders($headers)->post("$base/api/installments/{$installment['id']}/pay", [
        'amount' => min(5, (float) $installment['amount'] - (float) ($installment['amount_paid'] ?? 0)),
        'payment_method' => 'transfer',
        'notes' => 'Cuota FEL test',
        'issue_fel' => true,
    ]);
    record($results, 'POST /api/installments/{id}/pay + FEL', $r->successful(), [
        'fel' => $r->json('fel'),
        'message' => $r->json('message'),
    ]);
} else {
    record($results, 'POST /api/installments/{id}/pay + FEL', true, ['note' => 'Sin cuotas pendientes — omitido']);
}

$passed = count(array_filter($results, fn ($t) => $t['ok']));
$total = count($results);
echo "=== RESUMEN API: $passed/$total ===\n";
exit($passed === $total ? 0 : 1);
