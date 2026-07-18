<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$inst = App\Models\PaymentInstallment::with(['membership.plan','client'])
    ->where('status','!=','paid')
    ->where('due_date','<',now()->startOfDay())
    ->first();

if ($inst) {
    $client = $inst->client;
    echo "Client keys: " . implode(', ', array_keys($client->toArray())) . "\n";
    echo "full_name: " . $client->full_name . "\n";
    echo "first_name: " . $client->first_name . "\n";
    echo "last_name: " . $client->last_name . "\n";
    echo "name: " . ($client->name ?? 'N/A') . "\n";
    echo "Plan: " . ($inst->membership->plan->name ?? 'N/A') . "\n";
} else {
    echo "No overdue installments found\n";
}
