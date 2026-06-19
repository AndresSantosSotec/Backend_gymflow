<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration: Ciclo de vida de membresías con adelantos
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │  ESTADOS DEL NUEVO CICLO                                         │
 * │                                                                  │
 * │  active             → Cobro normal por Recurrente (estado orig.) │
 * │  advance_active     → Pagando con efectivo/transferencia         │
 * │  advance_expiring   → Quedan ≤7 días de adelanto (alerta previa) │
 * │  at_risk            → Recurrente falló al reactivar              │
 * │  paused             → Pausa aprobada (viaje/lesión)              │
 * │  expired            → Sin membresía vigente                       │
 * │  cancelled          → Cancelada explícitamente                   │
 * └──────────────────────────────────────────────────────────────────┘
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Campos de ciclo de vida en memberships ─────────────────
        Schema::table('memberships', function (Blueprint $table) {
            // Fecha en que vence el último mes adelantado
            $table->date('advance_end_date')
                  ->nullable()
                  ->after('payment_method_log')
                  ->comment('Último día cubierto por adelantos. Nulo si no hay adelantos activos.');

            // ¿El cliente quiere que Recurrente se reactive al vencer el adelanto?
            $table->boolean('wants_auto_renewal')
                  ->default(true)
                  ->after('advance_end_date')
                  ->comment('Si true, ReactivarSuscripcionesJob reactivará Recurrente automáticamente.');

            // Cuándo se reactivó Recurrente tras un adelanto
            $table->timestamp('reactivated_at')
                  ->nullable()
                  ->after('wants_auto_renewal');

            // Último error del Job de reactivación
            $table->text('reactivation_error')
                  ->nullable()
                  ->after('reactivated_at');

            // Cuándo ocurrió ese error
            $table->timestamp('reactivation_error_at')
                  ->nullable()
                  ->after('reactivation_error');

            // ── Campos de pausa ───────────────────────────────────────
            // Máximo días de pausa permitidos (configurable por cliente)
            $table->unsignedSmallInteger('max_pause_days')
                  ->default(60)
                  ->after('reactivation_error_at');

            // Total días ya usados en pausas (para validar contra max)
            $table->unsignedSmallInteger('total_paused_days')
                  ->default(0)
                  ->after('max_pause_days');

            // Índice para el Job diario que busca advance_end_date = HOY
            $table->index(['advance_end_date', 'wants_auto_renewal'], 'idx_advance_renewal');
            $table->index('reactivation_error_at', 'idx_at_risk');
        });

        // Extend the status enum — MySQL requires MODIFY COLUMN with full definition
        // We use DB::statement to avoid enum re-definition issues with blueprint
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE memberships
                MODIFY COLUMN status ENUM(
                    'active',
                    'advance_active',
                    'advance_expiring',
                    'at_risk',
                    'paused',
                    'expired',
                    'cancelled'
                ) NOT NULL DEFAULT 'active'
            ");
        }

        // ── 2. Tabla de pausas ────────────────────────────────────────
        Schema::create('membership_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')
                  ->constrained('memberships')
                  ->cascadeOnDelete();
            $table->foreignId('client_id')
                  ->constrained('clients')
                  ->cascadeOnDelete();
            $table->foreignId('approved_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete()
                  ->comment('Admin que aprobó la pausa. Nulo si es automática.');

            // Fechas de la pausa
            $table->date('pause_start');
            $table->date('pause_end');
            $table->unsignedSmallInteger('pause_days')
                  ->comment('Días computados: pause_end - pause_start');

            // Motivo
            $table->enum('reason', ['travel', 'injury', 'other'])->default('other');
            $table->text('notes')->nullable();

            // Suscripciones de Recurrente afectadas
            $table->string('recurrente_sub_cancelled')
                  ->nullable()
                  ->comment('Sub cancelada al pausar');
            $table->string('recurrente_sub_new')
                  ->nullable()
                  ->comment('Sub nueva creada para reanudar');

            // Estado de la pausa
            $table->enum('status', ['active', 'completed', 'cancelled'])
                  ->default('active');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['membership_id', 'status']);
            $table->index(['pause_end', 'status'], 'idx_pause_expiry');
        });

        // ── 3. Tabla notification_log ─────────────────────────────────
        // FIX CRÍTICO 4 del SYSTEM_MAP: NotificarPagoAdelantado la referencia
        // pero nunca fue migrada
        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')
                  ->nullable()
                  ->constrained('clients')
                  ->nullOnDelete();
            $table->string('type')
                  ->comment('pago_adelantado | reactivacion | alerta_vencimiento | pausa');
            $table->string('channel')->default('email')
                  ->comment('email | sms | push');
            $table->enum('status', ['sent', 'failed', 'skipped'])->default('sent');
            $table->json('payload')->nullable()
                  ->comment('Datos relevantes para debugging');
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->index(['client_id', 'type']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
        Schema::dropIfExists('membership_pauses');

        // Restaurar status enum original
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE memberships
                MODIFY COLUMN status ENUM('active','expired','cancelled')
                NOT NULL DEFAULT 'active'
            ");
        }

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropIndex('idx_advance_renewal');
            $table->dropIndex('idx_at_risk');
            $table->dropColumn([
                'advance_end_date',
                'wants_auto_renewal',
                'reactivated_at',
                'reactivation_error',
                'reactivation_error_at',
                'max_pause_days',
                'total_paused_days',
            ]);
        });
    }
};
