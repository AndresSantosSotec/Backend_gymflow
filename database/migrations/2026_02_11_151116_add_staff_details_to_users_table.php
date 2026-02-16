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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('active');
            $table->string('address')->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('address');
            $table->string('position')->nullable()->after('birth_date');
            $table->date('hire_date')->nullable()->after('position');
            $table->decimal('salary', 10, 2)->nullable()->after('hire_date');
            
            // Emergency contact
            $table->string('emergency_contact_name')->nullable()->after('salary');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_phone');
            
            // Files and biometric
            $table->text('notes')->nullable()->after('emergency_contact_relationship');
            $table->string('photo')->nullable()->after('notes');
            $table->json('photos')->nullable()->after('photo');
            $table->string('cv_url')->nullable()->after('photos');
            $table->string('fingerprint_id')->unique()->nullable()->after('cv_url');
            $table->timestamp('fingerprint_registered_at')->nullable()->after('fingerprint_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'address', 'birth_date', 'position', 'hire_date', 'salary',
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
                'notes', 'photo', 'photos', 'cv_url', 'fingerprint_id', 'fingerprint_registered_at'
            ]);
        });
    }
};
