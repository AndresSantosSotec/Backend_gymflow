<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Removes unique constraints from email and dni to allow duplicate values
     * Fingerprint ID remains unique since each fingerprint should belong to one person
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        try {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropUnique('clients_email_unique');
            });
        } catch (\Throwable $e) {}

        try {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropUnique('clients_dni_unique');
            });
        } catch (\Throwable $e) {}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This will fail if there are duplicate values in the database
        Schema::table('clients', function (Blueprint $table) {
            $table->unique('email');
            $table->unique('dni');
        });
    }
};
