<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin user
        User::factory()->create([
            'name' => 'Admin Gymflow',
            'username' => 'admin',
            'email' => 'admin@gymflow.com',
            'password' => bcrypt('password123'),
            'active' => true,
        ]);

        // Create test user
        User::factory()->create([
            'name' => 'Test User',
            'username' => 'test',
            'email' => 'test@gymflow.com',
            'password' => bcrypt('password123'),
            'active' => true,
        ]);
    }
}
