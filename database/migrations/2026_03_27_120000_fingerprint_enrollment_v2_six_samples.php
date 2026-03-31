<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enrollment v2: 6 muestras guiadas, metadata por muestra, marcas legacy en clientes.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedTinyInteger('fingerprint_enrollment_version')->default(1)->after('fingerprint_registered_at');
            $table->unsignedTinyInteger('fingerprint_sample_count')->nullable()->after('fingerprint_enrollment_version');
            $table->boolean('fingerprint_legacy_enrollment')->default(false)->after('fingerprint_sample_count');
        });

        Schema::table('fingerprint_extra_templates', function (Blueprint $table) {
            $table->string('capture_variant', 64)->nullable()->after('scan_index');
            $table->unsignedSmallInteger('blur_score')->nullable()->after('quality');
            $table->decimal('useful_area_ratio', 6, 4)->nullable()->after('blur_score');
            $table->timestamp('captured_at')->nullable()->after('useful_area_ratio');
        });

        // Registros existentes: considerados legacy (típicamente 1+3 muestras)
        DB::table('clients')
            ->whereNotNull('fingerprint_id')
            ->update([
                'fingerprint_legacy_enrollment' => true,
                'fingerprint_sample_count' => 4,
            ]);
    }

    public function down(): void
    {
        Schema::table('fingerprint_extra_templates', function (Blueprint $table) {
            $table->dropColumn(['capture_variant', 'blur_score', 'useful_area_ratio', 'captured_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'fingerprint_enrollment_version',
                'fingerprint_sample_count',
                'fingerprint_legacy_enrollment',
            ]);
        });
    }
};
