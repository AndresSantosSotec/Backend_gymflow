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
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('gym_name')->default('GymFlow');
            $table->string('slogan')->nullable();
            $table->text('about_text')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('whatsapp', 50)->nullable();
            $table->string('instagram', 100)->nullable();
            $table->string('primary_color', 100)->default('oklch(0.65 0.25 285)');
            $table->json('hero_images')->nullable(); // Array de URLs de imágenes para carrusel
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
