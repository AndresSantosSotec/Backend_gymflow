<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // binary() is at most 65535 bytes but stores raw binary, not suited for base64 PNG strings.
            // longText supports up to 4 GB — enough for any fingerprint image from DigitalPersona WebSDK.
            $table->longText('fingerprint_template')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->binary('fingerprint_template')->nullable()->change();
        });
    }
};
