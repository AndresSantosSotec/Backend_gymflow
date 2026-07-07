<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el campo plan_type a membership_plans.
 *
 * Permite clasificar cada plan según el servicio que representa:
 *   membership        → Mensualidad/Membresía general del gimnasio
 *   personal_training → Servicio de entrenamiento personalizado
 *   nutrition         → Plan nutricional / asesoría dietética
 *   course            → Curso específico (spinning, zumba, box, etc.)
 *   other             → Cualquier otro servicio adicional
 *
 * Cada plan tiene su propio ciclo de vencimiento independiente,
 * calculado desde la fecha en que el cliente realiza el pago.
 * Esto permite cobros "desfasados": la mensualidad puede vencer el 1
 * y el entrenamiento personalizado vencer el 15 del mismo mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->enum('plan_type', [
                'membership',
                'personal_training',
                'nutrition',
                'course',
                'other',
            ])->default('membership')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropColumn('plan_type');
        });
    }
};
