<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Productos de pago único en Recurrente (inscripción, mensualidad, curso, etc.)
     */
    public function up(): void
    {
        Schema::create('recurrente_productos', function (Blueprint $table) {
            $table->id();

            $table->string('recurrente_product_id')->nullable()->index();
            $table->string('recurrente_price_id')->nullable();

            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('monto_centavos');
            $table->enum('tipo', ['inscripcion', 'mensualidad', 'curso', 'otro'])->default('inscripcion');
            $table->string('storefront_link')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurrente_productos');
    }
};
