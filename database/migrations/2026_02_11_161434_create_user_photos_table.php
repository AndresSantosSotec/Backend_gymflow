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
        Schema::create('user_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('url'); // Usamos text por si las URLs son largas (Base64 o S3)
            $table->string('type')->nullable(); // Ej: 'profile', 'gallery', 'id_card'
            $table->timestamps();
        });

        // Removemos la columna JSON vieja de users para evitar duplicidad
        if (Schema::hasColumn('users', 'photos')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('photos');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_photos');
        
        // Restauramos la columna si revertimos
        Schema::table('users', function (Blueprint $table) {
            $table->json('photos')->nullable()->after('photo');
        });
    }
};
