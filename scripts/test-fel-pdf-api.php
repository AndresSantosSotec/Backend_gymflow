<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

$receiptId = (int) ($argv[1] ?? 44);
$user = User::where('email', 'admin@gymflow.com')->firstOrFail();
$token = $user->createToken('pdf-api-test')->plainTextToken;
$base = rtrim(env('APP_URL', 'http://localhost:8000'), '/');

$r = Http::withHeaders([
    'Authorization' => "Bearer $token",
    'Accept' => 'application/json',
])->get("$base/api/fel/receipts/$receiptId/pdf");

echo "status={$r->status()}\n";
echo 'content-type=' . ($r->header('Content-Type')[0] ?? '') . "\n";
echo 'body_start=' . substr($r->body(), 0, 8) . "\n";
echo 'size=' . strlen($r->body()) . "\n";

if ($r->status() !== 200) {
    echo $r->body() . "\n";
    exit(1);
}

exit(str_starts_with($r->body(), '%PDF') ? 0 : 1);
