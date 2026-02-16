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
            // ─── Fingerprint / Biometrics (Preparado para integración futura) ───
            // Template binario de la huella (almacena el hash/template del sensor)
            $table->binary('fingerprint_template')->nullable()->after('fingerprint_id');
            // Marca/modelo del dispositivo que capturó la huella
            $table->string('fingerprint_device_id')->nullable()->after('fingerprint_template');
            // Calidad de la captura (0-100)
            $table->unsignedTinyInteger('fingerprint_quality')->nullable()->after('fingerprint_device_id');
            // Cuándo fue registrada
            $table->timestamp('fingerprint_registered_at')->nullable()->after('fingerprint_quality');

            // ─── Campos adicionales del cliente ───
            // Género
            $table->enum('gender', ['M', 'F', 'other'])->nullable()->after('birth_date');
            // Teléfono secundario / WhatsApp
            $table->string('phone_secondary')->nullable()->after('phone');
            // Peso y altura (útil para programas fitness)
            $table->decimal('weight_kg', 5, 2)->nullable()->after('address');
            $table->decimal('height_cm', 5, 2)->nullable()->after('weight_kg');
            // Condiciones médicas / alergias
            $table->text('medical_conditions')->nullable()->after('height_cm');
            // Fuente de referencia (cómo conocieron el gym)
            $table->string('referral_source')->nullable()->after('medical_conditions');

            // ─── Índices ───
            $table->index('status');
            $table->index('phone');
            $table->index('created_at');
        });

        // ─── Mejorar access_logs para soportar huellas ───
        Schema::table('access_logs', function (Blueprint $table) {
            $table->string('fingerprint_id')->nullable()->after('qr_code');
            $table->enum('verification_method', ['qr', 'fingerprint', 'manual'])->default('qr')->after('access_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'fingerprint_template',
                'fingerprint_device_id',
                'fingerprint_quality',
                'fingerprint_registered_at',
                'gender',
                'phone_secondary',
                'weight_kg',
                'height_cm',
                'medical_conditions',
                'referral_source',
            ]);
            $table->dropIndex(['status']);
            $table->dropIndex(['phone']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('access_logs', function (Blueprint $table) {
            $table->dropColumn(['fingerprint_id', 'verification_method']);
        });
    }
};
