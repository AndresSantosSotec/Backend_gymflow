<?php

/**
 * Verifica inventario, membresías y preparación FEL.
 * Uso: php scripts/verify-inventory-memberships-fel.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\MembershipPlan;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

$base = rtrim(env('APP_URL', 'http://localhost:8000'), '/');
$results = [];

function record(array &$results, string $name, bool $ok, array $details = []): void
{
    $results[] = array_merge(['test' => $name, 'ok' => $ok], $details);
    echo '[' . ($ok ? 'OK' : 'FAIL') . "] $name\n";
    if ($details) {
        echo json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n";
}

$user = User::where('email', 'admin@gymflow.com')->first();
if (!$user) {
    echo "ERROR: admin@gymflow.com no existe\n";
    exit(1);
}
$token = $user->createToken('verify-inv-mem-fel')->plainTextToken;
$headers = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];

echo "=== Verificación Inventario + Membresías + FEL ===\n\n";

// ── FEL config ─────────────────────────────────────────────────────────────
$felCfg = config('billing.corpo_fel');
record($results, 'FEL habilitado', ($felCfg['enabled'] ?? false) === true, [
    'use_test' => $felCfg['use_test'] ?? null,
    'entity_nit' => $felCfg['entity_nit'] ?? null,
    'auto_certify_cash' => $felCfg['auto_certify_cash'] ?? null,
    'auto_certify_non_cash' => $felCfg['auto_certify_non_cash'] ?? null,
]);

$r = Http::withHeaders($headers)->get("$base/api/fel/status");
record($results, 'GET /api/fel/status', $r->successful() && ($r->json('enabled') === true), [
    'body' => $r->json(),
]);

// ── Inventario ─────────────────────────────────────────────────────────────
$productCount = Producto::count();
$withStock = Producto::where('stock', '>', 0)->count();
record($results, 'Productos en BD', $productCount > 0, [
    'total' => $productCount,
    'con_stock' => $withStock,
]);

$r = Http::withHeaders($headers)->get("$base/api/productos");
record($results, 'GET /api/productos', $r->successful(), [
    'count' => is_array($r->json()) ? count($r->json()) : 0,
]);

if (Schema::hasTable('movimiento_inventarios')) {
    $r = Http::withHeaders($headers)->get("$base/api/inventario");
    record($results, 'GET /api/inventario (movimientos)', $r->successful(), [
        'count' => is_array($r->json()) ? count($r->json()) : 0,
    ]);
} else {
    record($results, 'Tabla movimiento_inventarios', false, ['note' => 'tabla no existe']);
}

// Venta de prueba (sin FEL automático en SaleController — solo recibo)
$product = Producto::where('stock', '>', 0)->first();
if ($product) {
    $r = Http::withHeaders($headers)->post("$base/api/ventas", [
        'estado' => 'PAGADA',
        'detalles' => [
            ['producto_id' => $product->id, 'cantidad' => 1],
        ],
    ]);
    $ventaId = $r->json('id');
    $receiptId = $r->json('receipt.id') ?? null;
    $stockAfter = Producto::find($product->id)?->stock;
    record($results, 'POST /api/ventas (inventario descuenta stock)', $r->successful() && $ventaId, [
        'status' => $r->status(),
        'venta_id' => $ventaId,
        'receipt_id' => $receiptId,
        'producto' => $product->nombre,
        'stock_despues' => $stockAfter,
        'error' => $r->json('message') ?? null,
        'fel_en_venta' => 'NO automático — certificar desde Recibos si aplica',
    ]);
} else {
    record($results, 'POST /api/ventas', false, ['note' => 'sin productos con stock']);
}

// ── Membresías ─────────────────────────────────────────────────────────────
$plan = MembershipPlan::where('published', true)->first() ?? MembershipPlan::first();
$client = Client::whereNotNull('nit')->where('nit', '!=', '')->first()
    ?? Client::where('id', 5)->first();

if ($plan && $client) {
    $r = Http::withHeaders($headers)->post("$base/api/memberships/assign", [
        'client_id' => $client->id,
        'plan_id' => $plan->id,
        'payment_method' => 'CARD',
        'amount' => (float) $plan->price,
        'payment_type' => 'single',
        'reference' => 'Verify script ' . now()->format('Y-m-d H:i:s'),
        'issue_fel' => true,
    ]);
    $fel = $r->json('fel');
    record($results, 'POST /api/memberships/assign CARD+single+FEL', $r->successful() && ($fel['fel_status'] ?? '') === 'certified', [
        'client_id' => $client->id,
        'plan' => $plan->name,
        'membership_id' => $r->json('membership.id'),
        'fel_status' => $fel['fel_status'] ?? null,
        'uuid' => $fel['uuid'] ?? null,
    ]);
} else {
    record($results, 'POST /api/memberships/assign', false, [
        'plan' => $plan?->id,
        'client' => $client?->id,
    ]);
}

// Cliente con NIT para certificación
$clientWithNit = Client::whereNotNull('nit')->where('nit', '!=', '')->count();
record($results, 'Clientes con NIT (requisito FEL)', $clientWithNit > 0, ['count' => $clientWithNit]);

// ── Resumen ────────────────────────────────────────────────────────────────
$ok = count(array_filter($results, fn ($r) => $r['ok']));
$total = count($results);
echo "=== RESUMEN: {$ok}/{$total} ===\n\n";

echo "Gaps conocidos para certificación FEL:\n";
echo "  • POS/Ventas inventario: crea recibo pero NO certifica FEL automático (manual en Recibos)\n";
echo "  • UI Inventario.tsx: sin checkbox FEL (solo ajustes de stock)\n";
echo "  • Producción Iron Fit: descomentar bloque PRODUCCIÓN en .env + credenciales Corpo\n";

exit($ok === $total ? 0 : 1);
