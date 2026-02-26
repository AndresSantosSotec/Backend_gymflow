<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabla de productos de inscripción/matrícula (pagos únicos via Recurrente)
     */
    public function up(): void
    {
        Schema::create('registration_products', function (Blueprint $table) {
            $table->id();

            // Información básica del producto
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2); // Precio en quetzales

            // Imagen del producto
            $table->string('image_url')->nullable();

            // Estado del producto
            $table->boolean('published')->default(true);
            $table->integer('max_uses')->nullable(); // Límite de usos (opcional)
            $table->integer('uses_count')->default(0); // Contador de veces que se ha usado

            // URLs de redirección
            $table->string('success_url')->nullable();
            $table->string('cancel_url')->nullable();

            // Requisitos del checkout
            $table->enum('phone_requirement', ['none', 'optional', 'required'])->default('none');
            $table->enum('address_requirement', ['none', 'optional', 'required'])->default('none');
            $table->enum('billing_info_requirement', ['none', 'optional', 'required'])->default('none');

            // IDs de Recurrente
            $table->string('recurrente_product_id')->nullable()->index();
            $table->string('recurrente_price_id')->nullable();

            // Metadata opcional
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_products');
    }
};
