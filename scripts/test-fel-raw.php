<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\Receipt;
use App\Services\CorpoFel\CorpoFelClient;
use App\Services\CorpoFel\FelDteBuilder;
use Carbon\Carbon;

$client = app(CorpoFelClient::class);
$builder = app(FelDteBuilder::class);

echo "--- AUTH RAW ---\n";
$auth = $client->authenticate(env('CORPO_FEL_USER', 'alan'), env('CORPO_FEL_PASSWORD', 'alan321'));
echo substr($auth['raw'] ?? 'NO RAW', 0, 3000) . "\n\n";

echo "--- CUI RAW ---\n";
$cui = $client->consultCui('2879007111601');
echo substr($cui['raw'] ?? 'NO RAW', 0, 3000) . "\n\n";

$mockClient = new Client(['nit' => '109205812', 'company_name' => 'GONZALEZ,GARCIA,,SHEILY,ANAYELI']);
$mockReceipt = new Receipt(['description' => 'Mensualidad', 'total' => 150, 'payment_type' => 'subscription']);
$mockReceipt->setRelation('client', $mockClient);
$xml = $builder->buildFromReceipt($mockReceipt, [
    'id' => '109205812', 'name' => 'GONZALEZ,GARCIA,,SHEILY,ANAYELI',
    'address' => 'CIUDAD', 'zip' => '01001', 'municipality' => 'GUATEMALA', 'department' => 'GUATEMALA',
]);

echo "--- CERTIFY RAW ---\n";
$cert = $client->certifyDocument($xml);
echo substr($cert['raw'] ?? 'NO RAW', 0, 5000) . "\n";
