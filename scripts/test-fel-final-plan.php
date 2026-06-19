<?php

/**
 * Plan final FEL — montos Q66.60 y descripción geográfica.
 * Deja una cuota pendiente recuperable de Q66.60 al terminar.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\Membership;
use App\Models\PaymentInstallment;
use App\Models\User;
use Illuminate\Support\Facades\Http;

$base = rtrim(env('APP_URL', 'http://localhost:8000'), '/');
$results = [];
$clientId = 26; // Pablo — NIT mamá 19959699
$geoDesc = 'Descripción geográfica: Zona 10, Ciudad de Guatemala, Departamento Guatemala, GT';
$amount = 66.60;

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

function ensureReceivable(float $amount, string $geoDesc, int $clientId): array
{
    $client = Client::findOrFail($clientId);
    $membership = Membership::where('client_id', $clientId)
        ->orderByDesc('id')
        ->first();

    if (!$membership) {
        throw new RuntimeException("Cliente #$clientId sin membresía para cuota pendiente");
    }

    $installment = PaymentInstallment::where('membership_id', $membership->id)
        ->where('client_id', $clientId)
        ->where('amount', $amount)
        ->whereIn('status', ['pending', 'partial'])
        ->orderByDesc('id')
        ->first();

    if (!$installment) {
        $maxNum = (int) PaymentInstallment::where('membership_id', $membership->id)->max('installment_number');
        $installment = PaymentInstallment::create([
            'membership_id' => $membership->id,
            'client_id' => $clientId,
            'installment_number' => $maxNum + 1,
            'amount' => $amount,
            'amount_paid' => 0,
            'due_date' => now()->addDays(15)->toDateString(),
            'status' => 'pending',
        ]);
    }

    $remaining = round((float) $installment->amount - (float) $installment->amount_paid, 2);

    return [
        'installment_id' => $installment->id,
        'membership_id' => $membership->id,
        'client' => trim("{$client->first_name} {$client->last_name}"),
        'amount' => (float) $installment->amount,
        'amount_paid' => (float) $installment->amount_paid,
        'saldo_pendiente' => $remaining,
        'description' => $geoDesc,
        'due_date' => $installment->due_date?->format('Y-m-d'),
        'status' => $installment->status,
    ];
}

echo "=== PLAN FINAL FEL — Q{$amount} | {$geoDesc} ===\n\n";

// Dejar cuota pendiente recuperable
try {
    $receivable = ensureReceivable($amount, $geoDesc, $clientId);
    record($results, 'Cuota pendiente Q66.60 (recuperable)', true, $receivable);
} catch (Throwable $e) {
    record($results, 'Cuota pendiente Q66.60 (recuperable)', false, ['error' => $e->getMessage()]);
}

$user = User::where('email', 'admin@gymflow.com')->firstOrFail();
$token = $user->createToken('fel-final-plan')->plainTextToken;
$headers = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];

// 1. FEL status
$r = Http::withHeaders($headers)->get("$base/api/fel/status");
record($results, 'GET /api/fel/status', $r->successful() && ($r->json('enabled') === true), [
    'body' => $r->json(),
]);

// 2. Consult NIT
$r = Http::withHeaders($headers)->post("$base/api/fel/consult-nit", ['nit' => '19959699']);
record($results, 'POST /api/fel/consult-nit', $r->successful() && ($r->json('success') === true), [
    'name' => $r->json('data.messageContent') ?? null,
]);

// 3. Pago efectivo + FEL Q66.60
$r = Http::withHeaders($headers)->post("$base/api/payments", [
    'client_id' => $clientId,
    'amount' => $amount,
    'payment_method' => 'cash',
    'status' => 'completed',
    'notes' => "{$geoDesc} — Pago efectivo FEL final",
    'issue_fel' => true,
]);
$fel = $r->json('fel');
record($results, 'POST /api/payments cash+issue_fel Q66.60', $r->successful() && ($fel['fel_status'] ?? '') === 'certified', [
    'payment_id' => $r->json('id'),
    'receipt' => $r->json('receipt.receipt_number') ?? null,
    'fel_uuid' => $fel['uuid'] ?? null,
    'description' => $geoDesc,
]);

// 4. Pago tarjeta auto-FEL Q66.60
$r2 = Http::withHeaders($headers)->post("$base/api/payments", [
    'client_id' => $clientId,
    'amount' => $amount,
    'payment_method' => 'card',
    'status' => 'completed',
    'notes' => "{$geoDesc} — Tarjeta auto-FEL final",
]);
$fel2 = $r2->json('fel');
record($results, 'POST /api/payments card auto-FEL Q66.60', $r2->successful() && ($fel2['fel_status'] ?? '') === 'certified', [
    'payment_id' => $r2->json('id'),
    'fel_uuid' => $fel2['uuid'] ?? null,
]);

// 5. Recibos con FEL
$r = Http::withHeaders($headers)->get("$base/api/receipts", ['per_page' => 3, 'sort_by' => 'created_at', 'sort_order' => 'desc']);
$latest = ($r->json('data') ?? [])[0] ?? null;
$eb = $latest['details']['electronic_billing'] ?? null;
record($results, 'GET /api/receipts FEL en details', $r->successful() && ($eb['fel_status'] ?? null) === 'certified', [
    'receipt' => $latest['receipt_number'] ?? null,
    'total' => $latest['total'] ?? null,
    'description' => $latest['description'] ?? null,
]);

// 6. Certificar manual
$r = Http::withHeaders($headers)->post("$base/api/payments", [
    'client_id' => 7,
    'amount' => $amount,
    'payment_method' => 'cash',
    'status' => 'completed',
    'notes' => "{$geoDesc} — Sin FEL para certificar manual",
    'issue_fel' => false,
]);
$receiptId = $r->json('receipt.id');
$skipped = ($r->json('fel.skipped') ?? false) || ($r->json('fel.fel_status') === 'skipped');
record($results, 'POST /api/payments cash sin FEL Q66.60', $r->successful() && $skipped, [
    'receipt_id' => $receiptId,
]);

if ($receiptId) {
    $r = Http::withHeaders($headers)->post("$base/api/fel/receipts/$receiptId/certify");
    $certFel = $r->json('fel') ?? [];
    record($results, 'POST /api/fel/receipts/{id}/certify', $r->successful() && (($certFel['fel_status'] ?? '') === 'certified'), [
        'uuid' => $certFel['uuid'] ?? null,
    ]);

    $certReceiptId = $r->json('receipt.id') ?? $receiptId;
    $rVoid = Http::withHeaders($headers)->post("$base/api/fel/receipts/$certReceiptId/void");
    $voidBody = $rVoid->json();
    $voidOk = (bool) ($voidBody['result']['success'] ?? false);
    $expectedVoid = (bool) ($voidBody['expected_in_pruebas'] ?? $voidBody['result']['expected_in_pruebas'] ?? false);
    record($results, 'POST /api/fel/receipts/{id}/void', $rVoid->successful() && ($voidOk || $expectedVoid), [
        'tr_code' => $voidBody['tr_code'] ?? $voidBody['result']['tr_code'] ?? null,
        'expected_in_pruebas' => $expectedVoid,
    ]);
}

// 7. Cuota pendiente — abono parcial Q5 (deja saldo ~61.60)
$inst = Http::withHeaders($headers)->get("$base/api/installments", [
    'client_id' => $clientId,
    'status' => 'pending',
]);
$installments = $inst->json();
if (isset($installments['data'])) {
    $installments = $installments['data'];
}
$targetInst = collect($installments)->first(fn ($i) => abs((float) ($i['amount'] ?? 0) - $amount) < 0.01);

if ($targetInst) {
    $r = Http::withHeaders($headers)->post("$base/api/installments/{$targetInst['id']}/pay", [
        'amount' => 5.00,
        'payment_method' => 'transfer',
        'notes' => "{$geoDesc} — Abono parcial cuota FEL",
        'issue_fel' => true,
    ]);
    record($results, 'POST /api/installments/{id}/pay + FEL (parcial Q5)', $r->successful(), [
        'installment_id' => $targetInst['id'],
        'fel' => $r->json('fel'),
        'saldo_restante' => round($amount - 5, 2),
    ]);
} else {
    record($results, 'POST /api/installments/{id}/pay + FEL', false, ['error' => 'No se encontró cuota Q66.60']);
}

// Reportar cuota pendiente final (datos recuperables)
try {
    $left = ensureReceivable($amount, $geoDesc, $clientId);
    record($results, 'Cuota Q66.60 dejada pendiente (datos recuperables)', true, $left);
} catch (Throwable $e) {
    record($results, 'Cuota Q66.60 dejada pendiente', false, ['error' => $e->getMessage()]);
}

$passed = count(array_filter($results, fn ($t) => $t['ok']));
$total = count($results);
echo "=== RESUMEN PLAN FINAL: $passed/$total ===\n";
echo "Datos dejados: cuota pendiente Q{$amount} | {$geoDesc}\n";
exit($passed === $total ? 0 : 1);
