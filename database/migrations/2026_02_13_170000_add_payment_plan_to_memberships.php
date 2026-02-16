<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->decimal('total_amount', 10, 2)->nullable()->after('auto_renew');
            $table->enum('payment_type', ['single', 'installments'])->default('single')->after('total_amount');
            $table->unsignedSmallInteger('num_installments')->default(1)->after('payment_type');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('num_installments');
            $table->enum('payment_status', ['paid', 'partial', 'pending', 'overdue'])->default('pending')->after('amount_paid');
        });

        Schema::create('payment_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->unsignedSmallInteger('installment_number');
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->date('due_date');
            $table->enum('status', ['pending', 'partial', 'paid', 'overdue'])->default('pending');
            $table->foreignId('payment_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['membership_id', 'installment_number']);
            $table->index(['client_id', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_installments');

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['total_amount', 'payment_type', 'num_installments', 'amount_paid', 'payment_status']);
        });
    }
};
