<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

$receiptId = (int) ($argv[1] ?? 44);
$user = User::where('email', 'admin@gymflow.com')->firstOrFail();
$token = $user->createToken('fel-files')->plainTextToken;
$base = rtrim(env('APP_URL', 'http://localhost:8000'), '/');
$headers = ['Authorization' => "Bearer $token"];

foreach (['pdf', 'xml'] as $type) {
    $r = Http::withHeaders($headers)->get("$base/api/fel/receipts/$receiptId/$type");
    $body = $r->body();
    $cd = $r->header('Content-Disposition');
    echo "[$type] status={$r->status()} disposition=$cd start=" . substr($body, 0, 12) . " size=" . strlen($body) . PHP_EOL;
}
