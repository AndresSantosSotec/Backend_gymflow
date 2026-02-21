<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos necesarios para el manejo de pagos adelantados
 * y sincronización con Recurrente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── payment_installments: campos de método de pago y Recurrente ──
        Schema::table('payment_installments', function (Blueprint $table) {
            // Método con que se pagó esta cuota específica
            $table->string('payment_method')->nullable()->after('status')
                  ->comment('efectivo | transferencia | tarjeta | recurrente | combinado');

            // Referencia de transferencia bancaria
            $table->string('transfer_reference')->nullable()->after('payment_method')
                  ->comment('Número de referencia de transferencia');

            // Indica si esta cuota fue pagada por Recurrente (webhook)
            $table->string('recurrente_payment_id')->nullable()->after('transfer_reference')
                  ->comment('ID del pago en Recurrente si fue cobrado automáticamente');

            // Flag para adelanto: indica que fue pagado antes de su vencimiento
            $table->boolean('is_advance_payment')->default(false)->after('recurrente_payment_id')
                  ->comment('true si fue registrado como pago adelantado en efectivo/transferencia');

            // Quién registró el pago (admin/staff)
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete()
                  ->after('is_advance_payment');
        });

        // ── memberships: campos de estado de Recurrente ────────────────
        Schema::table('memberships', function (Blueprint $table) {
            // Estado de la suscripción de Recurrente vinculada
            // null = no tiene | active | scheduled | cancelled | paused
            $table->string('recurrente_status')->nullable()->after('payment_status')
                  ->comment('Estado de la suscripción Recurrente: active|scheduled|cancelled');

            // Cuándo se reprogramó la suscripción (para auditoría)
            $table->timestamp('recurrente_rescheduled_at')->nullable()->after('recurrente_status');

            // log de cambios de método de pago (JSON)
            $table->json('payment_method_log')->nullable()->after('recurrente_rescheduled_at')
                  ->comment('Historial de cambios de método de pago con timestamps');
        });

        // ── Tabla de log de operaciones de pago adelantado ─────────────
        // Para auditoría completa de cada operación
        Schema::create('advance_payment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();

            // Cuotas afectadas (IDs)
            $table->json('installment_ids')->comment('IDs de cuotas marcadas como pagadas');

            // Método de pago
            $table->string('payment_method')->comment('efectivo|transferencia|combinado');
            $table->decimal('total_amount', 10, 2);
            $table->string('transfer_reference')->nullable();
            $table->text('notes')->nullable();

            // Acción ejecutada en Recurrente
            $table->string('recurrente_action')->nullable()
                  ->comment('none|cancelled|rescheduled|adjusted');

            // IDs de Recurrente involucrados
            $table->string('old_subscription_id')->nullable();
            $table->string('new_subscription_id')->nullable();
            $table->date('next_charge_date')->nullable()
                  ->comment('Fecha desde la que Recurrente retoma el cobro');

            // Resultado de la operación
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();

            $table->timestamps();
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_payment_logs');

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['recurrente_status', 'recurrente_rescheduled_at', 'payment_method_log']);
        });

        Schema::table('payment_installments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method', 'transfer_reference', 'recurrente_payment_id',
                'is_advance_payment', 'registered_by',
            ]);
        });
    }
};
