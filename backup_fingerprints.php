<?php
/**
 * backup_fingerprints.php
 * Genera una copia de seguridad de todos los registros de huella
 * antes de ejecutar la limpieza en producción.
 *
 * Uso:
 *   cd D:\Gymflow\Backend-Gymflow
 *   php backup_fingerprints.php
 *
 * Genera:
 *   backup_fp_<fecha>.sql            → SQL de restauración completo
 *   backup_fp_template_<id>.b64      → template base64 de cada cliente (separado)
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$date   = date('Y-m-d_His');
$sqlFile = __DIR__ . "/backup_fp_{$date}.sql";

$clients = DB::table('clients')
    ->whereNotNull('fingerprint_id')
    ->get();

if ($clients->isEmpty()) {
    echo "No hay clientes con huella en la base de datos. Nada que respaldar.\n";
    exit(0);
}

$lines = [];
$lines[] = "-- ============================================================";
$lines[] = "-- BACKUP huellas digitales — generado {$date}";
$lines[] = "-- Para restaurar: ejecuta este SQL en gymflow_db";
$lines[] = "-- ============================================================";
$lines[] = "";
$lines[] = "-- Primero limpia los extras huerfanos del periodo de prueba";
$lines[] = "DELETE FROM fingerprint_extra_templates WHERE client_id IN (" .
           $clients->pluck('id')->join(',') . ");";
$lines[] = "";

foreach ($clients as $c) {
    echo "Respaldando client id={$c->id}  {$c->first_name} {$c->last_name}  fp={$c->fingerprint_id}\n";

    // Guardar template en archivo separado (demasiado grande para inline SQL)
    $b64File = __DIR__ . "/backup_fp_template_client{$c->id}.b64";
    file_put_contents($b64File, $c->fingerprint_template ?? '');
    echo "  Template guardado en: " . basename($b64File) . " (" . strlen($c->fingerprint_template ?? '') . " bytes)\n";

    $quality   = $c->fingerprint_quality    !== null ? (int)$c->fingerprint_quality    : 'NULL';
    $regAt     = $c->fingerprint_registered_at ?? null;
    $deviceId  = addslashes($c->fingerprint_device_id ?? 'default');
    $fpId      = addslashes($c->fingerprint_id);

    $lines[] = "-- Cliente id={$c->id}: {$c->first_name} {$c->last_name}";
    $lines[] = "-- Template en archivo: backup_fp_template_client{$c->id}.b64";
    $lines[] = "UPDATE clients SET";
    $lines[] = "  fingerprint_id            = '{$fpId}',";
    $lines[] = "  fingerprint_template      = LOAD_FILE('/path/to/backup_fp_template_client{$c->id}.b64'),";
    $lines[] = "  fingerprint_device_id     = '{$deviceId}',";
    $lines[] = "  fingerprint_quality       = {$quality},";
    $lines[] = "  fingerprint_registered_at = " . ($regAt ? "'{$regAt}'" : "NULL");
    $lines[] = "WHERE id = {$c->id};";
    $lines[] = "";
}

$lines[] = "-- Verificar restauración";
$lines[] = "SELECT id, first_name, last_name, fingerprint_id, fingerprint_registered_at";
$lines[] = "FROM clients WHERE fingerprint_id IS NOT NULL;";

file_put_contents($sqlFile, implode("\n", $lines));

echo "\n=== BACKUP COMPLETADO ===\n";
echo "SQL:       backup_fp_{$date}.sql\n";
echo "Templates: backup_fp_template_client<id>.b64\n";
echo "\nTotal clientes respaldados: " . $clients->count() . "\n";
echo "\nAhora puedes ejecutar fix_fingerprints_reenroll.sql con seguridad.\n";
