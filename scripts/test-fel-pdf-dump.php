<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$client = app(App\Services\CorpoFel\CorpoFelClient::class);
$pdf = $client->getDocumentPdf('5454E927-4EDF-492B-8F2B-0DC4551715BE');

$parsed = $pdf['parsed'] ?? [];
foreach (['data1', 'data2', 'data3'] as $k) {
    if (empty($parsed[$k])) continue;
    $v = $parsed[$k];
    $bin = base64_decode($v, true);
    echo "=== $k len=" . strlen($v) . " ===\n";
    echo "starts: " . substr($v, 0, 30) . "\n";
    if ($bin !== false) {
        echo "decoded starts: " . substr($bin, 0, 30) . "\n";
        echo "is_pdf: " . (str_starts_with($bin, '%PDF') ? 'yes' : 'no') . "\n";
    }
}

// Search raw for PDF marker or base64 PDF
$raw = $pdf['raw'] ?? '';
if (preg_match('/ResponseData[123]>([^<]+)</', $raw, $m)) {
    echo "first ResponseData content starts: " . substr($m[1], 0, 30) . "\n";
}

// List all xpath fields in raw
libxml_use_internal_errors(true);
$xml = simplexml_load_string($raw);
if ($xml) {
    $nodes = $xml->xpath('//*[starts-with(local-name(), "Response") or starts-with(local-name(), "Data")]');
    foreach ($nodes as $n) {
        $name = $n->getName();
        $val = trim((string)$n);
        if ($val === '') continue;
        echo "node $name len=" . strlen($val) . " start=" . substr($val, 0, 25) . "\n";
    }
}
