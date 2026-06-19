<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$client = app(App\Services\CorpoFel\CorpoFelClient::class);
$pdf = $client->getDocumentPdf('5454E927-4EDF-492B-8F2B-0DC4551715BE');
$d1 = $pdf['parsed']['data1'] ?? '';
$bin = base64_decode($d1, true);
echo 'data1 starts: ' . substr($d1, 0, 20) . PHP_EOL;
echo 'decoded starts: ' . substr($bin ?: '', 0, 8) . PHP_EOL;
echo 'is_pdf: ' . (str_starts_with($bin ?: '', '%PDF') ? 'yes' : 'no') . PHP_EOL;
echo 'size: ' . strlen($bin ?: '') . PHP_EOL;
