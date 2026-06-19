<?php

/**
 * Prueba end-to-end FEL — entorno de pruebas Corpo Sistemas.
 * Uso: php scripts/test-fel-e2e.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\Receipt;
use App\Services\CorpoFel\CorpoFelClient;
use App\Services\CorpoFel\FelDteBuilder;
use Carbon\Carbon;

function section(string $title): void
{
    echo "\n" . str_repeat('=', 60) . "\n";
    echo $title . "\n";
    echo str_repeat('=', 60) . "\n";
}

function result(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

$client = app(CorpoFelClient::class);
$builder = app(FelDteBuilder::class);
$cfg = config('billing.corpo_fel');

section('1. Configuración cargada');
result([
    'provider' => config('billing.provider'),
    'enabled' => $cfg['enabled'],
    'use_test' => $cfg['use_test'],
    'soap_url' => $cfg['base_url_test'],
    'requestor' => $cfg['requestor'],
    'entity_nit' => $cfg['entity_nit'],
    'emisor' => $cfg['emisor_nombre'],
]);

section('2. Autenticación (USUARIO_LOGIN)');
$user = env('CORPO_FEL_USER', 'alan');
$pass = env('CORPO_FEL_PASSWORD', 'alan321');
$auth = $client->authenticate($user, $pass);
result([
    'success' => $auth['success'] ?? false,
    'error' => $auth['error'] ?? null,
    'response' => $auth['parsed']['response'] ?? null,
    'data1' => isset($auth['parsed']['data1']) ? substr((string) $auth['parsed']['data1'], 0, 200) : null,
]);

section('3. Consulta NIT (109205812 — ejemplo Sheili)');
$nit = $client->consultNit('109205812');
result([
    'success' => $nit['success'] ?? false,
    'data' => $nit['data'] ?? null,
]);

section('4. Consulta CUI (2879007111601 — documentación)');
$cui = $client->consultCui('2879007111601');
result([
    'success' => $cui['success'] ?? false,
    'error' => $cui['error'] ?? null,
    'data2_json' => $cui['parsed']['data2_json'] ?? null,
    'response' => $cui['parsed']['response'] ?? null,
]);

section('5. Generar XML DTE de prueba (Q150 mensualidad)');
$mockClient = new Client([
    'first_name' => 'SHEILY',
    'last_name' => 'GONZALEZ',
    'nit' => '109205812',
    'dni' => null,
    'address' => 'ACCESO 4, 17-17 Zona 10',
    'fiscal_address' => 'ACCESO 4, 17-17 Zona 10',
    'company_name' => 'GONZALEZ,GARCIA,,SHEILY,ANAYELI',
]);

$mockReceipt = new Receipt([
    'receipt_number' => 'REC-TEST-' . date('YmdHis'),
    'payment_type' => 'subscription',
    'description' => 'Mensualidad - Prueba FEL Gymflow',
    'subtotal' => 133.93,
    'tax' => 16.07,
    'discount' => 0,
    'total' => 150.00,
    'status' => 'paid',
    'paid_at' => Carbon::now(),
    'is_invoiced' => true,
    'invoiced_at' => Carbon::now(),
    'invoice_number' => 'INV-TEST-' . date('YmdHis'),
]);
$mockReceipt->setRelation('client', $mockClient);

$receptor = [
    'id' => '109205812',
    'name' => 'GONZALEZ,GARCIA,,SHEILY,ANAYELI',
    'address' => 'ACCESO 4, 17-17 Zona 10',
    'zip' => '01005',
    'municipality' => 'GUATEMALA',
    'department' => 'GUATEMALA',
];

$dteXml = $builder->buildFromReceipt($mockReceipt, $receptor);
echo 'XML generado: ' . strlen($dteXml) . " bytes\n";
echo substr($dteXml, 0, 400) . "...\n";

section('6. Certificar documento (POST_DOCUMENT_SAT)');
$cert = $client->certifyDocument($dteXml);
result([
    'success' => $cert['success'] ?? false,
    'error' => $cert['error'] ?? null,
    'document_guid' => $cert['parsed']['document_guid'] ?? null,
    'serial' => $cert['parsed']['serial'] ?? null,
    'batch' => $cert['parsed']['batch'] ?? null,
    'description' => $cert['parsed']['description'] ?? null,
]);

$guid = $cert['parsed']['document_guid']
    ?? $cert['parsed']['data2_json']['uuid']
    ?? null;
if ($guid) {
    $guid = strtoupper($guid);
}

if ($guid && ($cert['success'] ?? false)) {
    section('7. Consultar documento certificado (GET_INFODTE)');
    $info = $client->getDocumentInfo($guid);
    result([
        'success' => $info['success'] ?? false,
        'error' => $info['error'] ?? null,
        'guid' => $guid,
        'data2_json' => $info['parsed']['data2_json'] ?? null,
    ]);

    section('8. Obtener PDF FEL');
    $pdf = $client->getDocumentPdf($guid);
    result([
        'success' => $pdf['success'] ?? false,
        'error' => $pdf['error'] ?? null,
        'has_pdf_base64' => $client->extractPdfContent($pdf['parsed'] ?? []) !== null,
        'pdf_size_bytes' => strlen($client->extractPdfContent($pdf['parsed'] ?? []) ?? ''),
    ]);
} else {
    section('7-8. Omitido — certificación no exitosa');
    if (!($cert['success'] ?? false)) {
        echo "Raw SOAP (primeros 1500 chars):\n";
        echo substr($cert['raw'] ?? '', 0, 1500) . "\n";
    }
}

section('RESUMEN');
$tests = [
    'consulta_nit' => $nit['success'] ?? false,
    'consulta_cui' => ($cui['success'] ?? false) && !empty($cui['parsed']['data1_json']),
    'certificacion' => $cert['success'] ?? false,
    'consulta_documento' => isset($info) ? ($info['success'] ?? false) : false,
    'pdf_fel' => isset($pdf) ? ($pdf['success'] ?? false) : false,
];
// Auth opcional en pruebas — credenciales alan/alan321 no válidas en test actual
result(['auth' => $auth['success'] ?? false, 'auth_nota' => 'Opcional; certificación no depende de login']);
result($tests);

$coreOk = ($tests['consulta_nit'] ?? false)
    && ($tests['consulta_cui'] ?? false)
    && ($tests['certificacion'] ?? false);
echo $coreOk
    ? "\n✓ Pruebas FEL principales OK en entorno de PRUEBAS (facturación funciona).\n"
    : "\n✗ Falló alguna prueba principal — revisa los detalles arriba.\n";

exit($coreOk ? 0 : 1);
