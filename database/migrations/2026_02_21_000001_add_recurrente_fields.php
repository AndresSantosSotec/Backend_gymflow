<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos de Recurrente a clients y membership_plans,
 * y crea la tabla recurrente_payments para tracking de pagos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── clients: guardar IDs de Recurrente ───────────────────────
        Schema::table('clients', function (Blueprint $table) {
            // ID del usuario en Recurrente (se crea al sincronizar)
            $table->string('recurrente_user_id')->nullable()
                  ->comment('ID del cliente en la plataforma Recurrente');

            // Token del método de pago (tarjeta) guardado
            $table->string('recurrente_payment_method_id')->nullable()->after('recurrente_user_id')
                  ->comment('ID del payment_method guardado en Recurrente (tarjeta tokenizada)');
        });

        // ── membership_plans: mapear plan → producto Recurrente ──────
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->string('recurrente_product_id')->nullable()->after('id')
                  ->comment('ID del producto en Recurrente (sincronizado vía artisan)');
        });

        // ── recurrente_payments: registro detallado de cada cobro ────
        Schema::create('recurrente_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();

            // IDs de Recurrente
            $table->string('recurrente_payment_id')->nullable()->comment('ID del pago en Recurrente');
            $table->string('recurrente_subscription_id')->nullable()->comment('ID de suscripción si aplica');
            $table->string('recurrente_checkout_id')->nullable()->comment('ID del checkout que originó el pago');

            // Datos del cobro
            $table->string('type')->default('one_time')
                  ->comment('one_time | subscription | checkout');
            $table->integer('amount_in_cents')->unsigned()->default(0);
            $table->string('currency', 3)->default('GTQ');
            $table->string('status')->default('pending')
                  ->comment('pending | succeeded | failed | refunded');
            $table->string('concept')->nullable()->comment('Descripción del cobro');

            // Payload completo del webhook para auditoría
            $table->json('metadata')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Índices útiles
            $table->index('recurrente_payment_id');
            $table->index('recurrente_subscription_id');
            $table->index(['client_id', 'status']);
        });

        // ── recurrente_subscriptions: suscripciones activas ─────────
        Schema::create('recurrente_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->string('recurrente_subscription_id')->unique();
            $table->string('recurrente_product_id')->nullable();
            $table->string('status')->default('active')
                  ->comment('active | cancelled | past_due | paused');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrente_subscriptions');
        Schema::dropIfExists('recurrente_payments');

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropColumn('recurrente_product_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['recurrente_user_id', 'recurrente_payment_method_id']);
        });
    }
};
