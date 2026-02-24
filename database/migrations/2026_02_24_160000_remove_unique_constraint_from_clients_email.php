<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Removes unique constraint from email to allow duplicate client emails
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Drop the unique constraint on email
            $table->dropUnique('clients_email_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Restore the unique constraint on email
            $table->string('email')->unique()->change();
        });
    }
};
