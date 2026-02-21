<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos adicionales para manejo de anomalías en pagos adelantados.
 *
 * Cubre:
 * - Fix 2.3: idempotency_key en recurrente_subscriptions
 * - Fix 3.1: descuento_aplicado en payment_installments
 * - Fix 3.2: precio_pagado snapshot en payment_installments
 * - Fix 5.3: tabla subscription_audit_log
 * - Fix 4.1/4.2: campos de reversión en advance_payment_logs
 * - Fix 4.3: fields en memberships para upgrade/downgrade
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── FIX 2.3 — Idempotency key para evitar suscripciones duplicadas ─
        Schema::table('recurrente_subscriptions', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('recurrente_subscription_id')
                  ->comment('UUID enviado a Recurrente para evitar duplicados en timeout');
            $table->string('creation_status')
                  ->default('created')
                  ->after('idempotency_key')
                  ->comment('created|pending_confirmation|failed — estado de la creación');
        });

        // ── FIX 3.1 + 3.2 — Snapshot de precio y descuento en cuotas ──────
        Schema::table('payment_installments', function (Blueprint $table) {
            // Precio al momento del pago (inmutable, aunque el plan cambie)
            $table->decimal('precio_pagado', 10, 2)->nullable()->after('amount_paid')
                  ->comment('Snapshot del precio al momento del pago adelantado');

            // Descuento aplicado en este pago (Q o %)
            $table->decimal('descuento_aplicado', 10, 2)->default(0)->after('precio_pagado')
                  ->comment('Descuento negociado con el admin');
            $table->string('descuento_motivo')->nullable()->after('descuento_aplicado')
                  ->comment('Razón del descuento: convenio, lealtad, promo, etc.');
            $table->foreignId('descuento_autorizado_por')->nullable()
                  ->constrained('users')->nullOnDelete()
                  ->after('descuento_motivo')
                  ->comment('Admin que autorizó el descuento');
        });

        // ── FIX 5.3 — Tabla de auditoría de suscripciones ──────────────────
        Schema::create('subscription_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('recurrente_subscription_id')->nullable()
                  ->comment('ID de la suscripción en Recurrente');
            $table->foreignId('local_subscription_id')->nullable()
                  ->constrained('recurrente_subscriptions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()
                  ->comment('Admin que realizó la acción');

            $table->string('accion')
                  ->comment('crear|cancelar|reprogramar|reactivar|suspender|fallido|cobro_duplicado|reembolso');
            $table->string('estado_anterior')->nullable();
            $table->string('estado_nuevo')->nullable();
            $table->text('motivo')->nullable();
            $table->json('metadata')->nullable()
                  ->comment('Datos extra: IDs viejos/nuevos, fechas, montos');

            $table->timestamps();
            $table->index(['client_id', 'created_at']);
            $table->index('recurrente_subscription_id');
        });

        // ── FIX 4.1 — Campos de reversión en advance_payment_logs ──────────
        Schema::table('advance_payment_logs', function (Blueprint $table) {
            $table->boolean('reversed')->default(false)->after('error_message')
                  ->comment('true si este log fue revertido');
            $table->timestamp('reversed_at')->nullable()->after('reversed');
            $table->foreignId('reversed_by')->nullable()
                  ->constrained('users')->nullOnDelete()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->unsignedBigInteger('reversal_log_id')->nullable()->after('reversal_reason')
                  ->comment('ID del log de reversión');
        });

        // ── FIX 4.3 — Upgrade/Downgrade en membresías ──────────────────────
        Schema::table('memberships', function (Blueprint $table) {
            // Plan anterior al upgrade/downgrade
            $table->unsignedBigInteger('previous_plan_id')->nullable()->after('plan_id');
            $table->decimal('credito_restante', 10, 2)->default(0)->after('previous_plan_id')
                  ->comment('Crédito en Q por meses prepagados no consumidos al cambiar de plan');
            $table->timestamp('upgraded_at')->nullable()->after('credito_restante');
        });

        // ── FIX 5.2 — Tabla de alertas de conciliación ─────────────────────
        Schema::create('recurrente_conciliation_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('installment_id')->nullable()
                  ->constrained('payment_installments')->nullOnDelete();
            $table->string('tipo')
                  ->comment('cobro_duplicado|cuota_cobrada_pagada|pago_no_registrado|sub_desincronizada');
            $table->string('recurrente_payment_id')->nullable();
            $table->string('recurrente_subscription_id')->nullable();
            $table->decimal('monto_recurrente', 10, 2)->nullable();
            $table->decimal('monto_local', 10, 2)->nullable();
            $table->text('descripcion');
            $table->enum('status', ['nueva', 'revisada', 'resuelta', 'ignorada'])->default('nueva');
            $table->foreignId('resuelta_por')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('resuelta_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrente_conciliation_alerts');
        Schema::dropIfExists('subscription_audit_log');

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['previous_plan_id', 'credito_restante', 'upgraded_at']);
        });

        Schema::table('advance_payment_logs', function (Blueprint $table) {
            $table->dropColumn(['reversed', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_log_id']);
        });

        Schema::table('payment_installments', function (Blueprint $table) {
            $table->dropColumn(['precio_pagado', 'descuento_aplicado', 'descuento_motivo', 'descuento_autorizado_por']);
        });

        Schema::table('recurrente_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['idempotency_key', 'creation_status']);
        });
    }
};
