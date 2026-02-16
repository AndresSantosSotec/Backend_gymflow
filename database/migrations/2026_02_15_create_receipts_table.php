<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();

            // Relaciones
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('cascade');
            $table->foreignId('payment_id')->nullable()->constrained('payments')->onDelete('cascade');
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->onDelete('cascade');
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->onDelete('cascade');

            // Información del recibo
            $table->string('receipt_number')->unique();
            $table->enum('type', ['receipt', 'invoice', 'proforma'])->default('receipt');
            $table->enum('payment_type', ['subscription', 'individual_payment', 'course', 'product'])->default('subscription');

            // Montos
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);

            // Facturación
            $table->boolean('is_invoiced')->default(false);
            $table->timestamp('invoiced_at')->nullable();
            $table->string('invoice_number')->nullable()->unique();
            $table->text('invoice_notes')->nullable();

            // Email
            $table->boolean('email_sent')->default(false);
            $table->timestamp('email_sent_at')->nullable();
            $table->string('sent_to_email')->nullable();

            // Descripción
            $table->text('description')->nullable();
            $table->text('details')->nullable(); // JSON con detalles del pago

            // Estados
            $table->enum('status', ['draft', 'pending', 'paid', 'cancelled'])->default('draft');

            // Tracking
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index('client_id');
            $table->index('payment_id');
            $table->index('receipt_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
