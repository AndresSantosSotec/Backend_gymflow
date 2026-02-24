<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Elimina los índices UNIQUE de email y dni en clients para permitir duplicados.
 * Si el índice ya no existe (p. ej. otra migración lo quitó), no hace nada.
 *
 * En el servidor ejecutar: php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $indexes = DB::select("
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = 'clients'
              AND INDEX_NAME IN ('clients_email_unique', 'clients_dni_unique')
        ", [config('database.connections.mysql.database')]);

        $names = array_column($indexes, 'INDEX_NAME');

        Schema::table('clients', function (Blueprint $table) use ($names) {
            if (in_array('clients_email_unique', $names, true)) {
                $table->dropUnique('clients_email_unique');
            }
            if (in_array('clients_dni_unique', $names, true)) {
                $table->dropUnique('clients_dni_unique');
            }
        });
    }

    public function down(): void
    {
        // Solo si quieres revertir (falla si ya hay emails/dni duplicados)
        Schema::table('clients', function (Blueprint $table) {
            $table->unique('email');
            $table->unique('dni');
        });
    }
};
