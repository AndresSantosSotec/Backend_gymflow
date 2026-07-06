#!/usr/bin/env php
<?php
/**
 * Verifica alineación del pipeline de huellas (token HMAC + config).
 *
 * Uso: php scripts/verify-fingerprint-pipeline.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$secret = config('services.fingerprint.match_secret');
$pythonUrl = rtrim(config('services.fingerprint.url'), '/') . '/fingerprint/identify';

echo "=== Verificación pipeline huellas ===\n\n";

echo "1. Laravel FINGERPRINT_MATCH_SECRET: ";
if ($secret) {
    echo "OK (".strlen($secret)." chars)\n";
} else {
    echo "NO CONFIGURADO — paso 2 solo valida fingerprint_id\n";
}

echo "2. Python URL: {$pythonUrl}\n";

$ch = curl_init($pythonUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 3,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => '{}',
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "3. Python server: ";
if ($code === 400) {
    echo "OK (responde, pide template)\n";
} elseif ($code > 0) {
    echo "HTTP {$code}\n";
} else {
    echo "NO ALCANZABLE — inicia fingerprint-server en :8089\n";
}

if ($secret) {
    $clientId = 1;
    $fpId = 'FP-TEST-VERIFY';
    $simPct = 88;
    $ts = time();
    $msg = sprintf('%d|%s|%d|%d', $clientId, $fpId, $simPct, $ts);
    $sig = hash_hmac('sha256', $msg, $secret);
    $token = "{$ts}.{$sig}";
    $ttl = (int) config('services.fingerprint.match_token_ttl', 30);
    $parts = explode('.', $token, 2);
    $check = hash_equals(
        hash_hmac('sha256', sprintf('%d|%s|%d|%d', $clientId, $fpId, $simPct, (int) $parts[0]), $secret),
        $parts[1]
    );
    echo "4. Token HMAC round-trip: ".($check ? "OK" : "FALLO")." (ttl={$ttl}s)\n";
    echo "   → FP_MATCH_SECRET en fingerprint-server/.env debe ser idéntico\n";
}

echo "\nChecklist manual:\n";
echo "  [ ] Reiniciar fingerprint-server tras cambios\n";
echo "  [ ] Mismo FP_MATCH_SECRET en Backend .env y fingerprint-server/.env\n";
echo "  [ ] Probe identify usa JPEG 256×256 (normalizeFingerprintProbe)\n";
echo "  [ ] 2.º escaneo confirma solo al candidato del 1.er (logs Double-scan CONFIRMED)\n";
echo "  [ ] log-fingerprint-access rechaza client_id ajeno (422)\n";
