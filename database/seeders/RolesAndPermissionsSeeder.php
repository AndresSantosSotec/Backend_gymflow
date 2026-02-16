<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define permissions
        $permissions = [
            ['name' => 'View Dashboard', 'slug' => 'DASHBOARD_VIEW'],
            ['name' => 'Manage Clients', 'slug' => 'CLIENTS_MANAGE'],
            ['name' => 'Manage Staff', 'slug' => 'USERS_MANAGE'],
            ['name' => 'Manage Roles', 'slug' => 'ROLES_MANAGE'],
            ['name' => 'View Inventory', 'slug' => 'INVENTORY_VIEW'],
        ];

        foreach ($permissions as $p) {
            \App\Models\Permission::firstOrCreate(['slug' => $p['slug']], $p);
        }

        // Create Admin Role
        $adminRole = \App\Models\Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrator', 'description' => 'Full access to the system']
        );

        // Assign all permissions to admin
        $adminRole->permissions()->sync(\App\Models\Permission::all()->pluck('id'));

        // Create Staff Role
        $staffRole = \App\Models\Role::firstOrCreate(
            ['slug' => 'staff'],
            ['name' => 'Staff member', 'description' => 'General staff access']
        );

        // Assign some permissions to staff
        $staffRole->permissions()->sync(
            \App\Models\Permission::whereIn('slug', ['DASHBOARD_VIEW', 'CLIENTS_VIEW'])->pluck('id')
        );

        // Create initial admin user
        \App\Models\User::firstOrCreate(
            ['email' => 'admin@gymflow.com'],
            [
                'name' => 'Admin User',
                'username' => 'admin',
                'password' => \Illuminate\Support\Facades\Hash::make('admin123'),
                'role_id' => $adminRole->id,
                'active' => true,
            ]
        );
    }
}
