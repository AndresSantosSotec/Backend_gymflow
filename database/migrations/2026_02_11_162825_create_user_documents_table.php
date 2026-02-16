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
        Schema::create('user_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Nombre del archivo (ej: Contrato_Juan.pdf)
            $table->text('url'); // URL o Base64 del documento
            $table->string('type')->nullable(); // Ej: 'pdf', 'docx', 'jpg'
            $table->string('category')->nullable(); // Ej: 'contract', 'id_card', 'cert'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_documents');
    }
};
