<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Almacena los escaneos adicionales (e1, e2, e3) de la huella de cada cliente.
     * La columna fingerprint_id sigue el mismo patrón que la huella principal
     * pero con sufijo "-e1", "-e2", "-e3" para que el servidor Python los trate
     * como registros independientes en el proceso de identificación 1:N.
     */
    public function up(): void
    {
        Schema::create('fingerprint_extra_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint_id')->unique();      // e.g. "FP-42-17xx-abc-e1"
            $table->longText('fingerprint_template');
            $table->unsignedTinyInteger('scan_index')->default(1); // 1, 2 ó 3
            $table->unsignedTinyInteger('quality')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_extra_templates');
    }
};
