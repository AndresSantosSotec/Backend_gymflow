<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega recurrente_price_id a membership_plans para poder
 * actualizar precios en la API de Recurrente (PATCH /products).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->string('recurrente_price_id')->nullable()->after('recurrente_product_id')
                  ->comment('ID del precio en Recurrente (price_xxx)');
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropColumn('recurrente_price_id');
        });
    }
};
