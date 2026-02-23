<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // ── Definir TODOS los permisos que el frontend usa ────────────────
        $permissions = [
            // Dashboard
            ['name' => 'Ver Dashboard',          'slug' => 'DASHBOARD_VIEW'],

            // Clientes
            ['name' => 'Ver Clientes',           'slug' => 'CLIENTS_VIEW'],
            ['name' => 'Crear Clientes',         'slug' => 'CLIENTS_CREATE'],
            ['name' => 'Editar Clientes',        'slug' => 'CLIENTS_EDIT'],
            ['name' => 'Eliminar Clientes',      'slug' => 'CLIENTS_DELETE'],

            // Planes de membresía
            ['name' => 'Ver Planes',             'slug' => 'PLANS_VIEW'],
            ['name' => 'Gestionar Planes',       'slug' => 'PLANS_MANAGE'],

            // Membresías
            ['name' => 'Ver Membresías',         'slug' => 'MEMBERSHIPS_VIEW'],
            ['name' => 'Gestionar Membresías',   'slug' => 'MEMBERSHIPS_MANAGE'],

            // Pagos
            ['name' => 'Ver Pagos',              'slug' => 'PAYMENTS_VIEW'],
            ['name' => 'Gestionar Pagos',        'slug' => 'PAYMENTS_MANAGE'],

            // Caja
            ['name' => 'Ver Caja',               'slug' => 'CASH_VIEW'],
            ['name' => 'Gestionar Caja',         'slug' => 'CASH_MANAGE'],

            // Inventario
            ['name' => 'Ver Inventario',         'slug' => 'INVENTORY_VIEW'],
            ['name' => 'Entrada de Inventario',  'slug' => 'INVENTORY_IN'],
            ['name' => 'Salida de Inventario',   'slug' => 'INVENTORY_OUT'],
            ['name' => 'Gestionar Inventario',   'slug' => 'INVENTORY_MANAGE'],

            // Productos
            ['name' => 'Ver Productos',          'slug' => 'PRODUCTS_VIEW'],
            ['name' => 'Crear Productos',        'slug' => 'PRODUCTS_CREATE'],
            ['name' => 'Editar Productos',       'slug' => 'PRODUCTS_EDIT'],
            ['name' => 'Eliminar Productos',     'slug' => 'PRODUCTS_DELETE'],

            // Ventas
            ['name' => 'Ver Ventas',             'slug' => 'SALES_VIEW'],
            ['name' => 'Crear Ventas',           'slug' => 'SALES_CREATE'],
            ['name' => 'Ver Cotizaciones',       'slug' => 'QUOTES_VIEW'],
            ['name' => 'Clientes Comerciales',   'slug' => 'SALES_CLIENTS_MANAGE'],

            // Control de Acceso — ROADMAP FUTURO
            // ['name' => 'Ver Control Acceso',     'slug' => 'ACCESS_VIEW'],
            // ['name' => 'Gestionar Acceso',       'slug' => 'ACCESS_MANAGE'],

            // Configuración
            ['name' => 'Ver Configuración',      'slug' => 'SETTINGS_VIEW'],
            ['name' => 'Gestionar Configuración','slug' => 'SETTINGS_MANAGE'],

            // Roles
            ['name' => 'Ver Roles',              'slug' => 'ROLES_VIEW'],
            ['name' => 'Gestionar Roles',        'slug' => 'ROLES_MANAGE'],

            // Usuarios / Staff
            ['name' => 'Ver Usuarios',           'slug' => 'USERS_VIEW'],
            ['name' => 'Gestionar Usuarios',     'slug' => 'USERS_MANAGE'],

            // Otros
            ['name' => 'Ver Reportes',           'slug' => 'REPORTS_VIEW'],
            ['name' => 'Ver Notificaciones',     'slug' => 'NOTIFICATIONS_VIEW'],
            // ['name' => 'Ver Cámaras',            'slug' => 'CAMERAS_VIEW'], // ROADMAP FUTURO
        ];

        foreach ($permissions as $p) {
            \App\Models\Permission::firstOrCreate(['slug' => $p['slug']], $p);
        }

        // ── Rol Administrador — acceso total ──────────────────────────────
        $adminRole = \App\Models\Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrador', 'description' => 'Acceso completo al sistema']
        );
        // Sincronizar TODOS los permisos al admin
        $adminRole->permissions()->sync(\App\Models\Permission::all()->pluck('id'));

        // ── Rol Recepcionista — acceso básico ─────────────────────────────
        $receptionRole = \App\Models\Role::firstOrCreate(
            ['slug' => 'recepcionista'],
            ['name' => 'Recepcionista', 'description' => 'Atención al cliente y acceso básico']
        );
        $receptionRole->permissions()->sync(
            \App\Models\Permission::whereIn('slug', [
                'DASHBOARD_VIEW',
                'CLIENTS_VIEW', 'CLIENTS_CREATE', 'CLIENTS_EDIT',
                'PLANS_VIEW',
                'MEMBERSHIPS_VIEW', 'MEMBERSHIPS_MANAGE',
                'PAYMENTS_VIEW',
                'CASH_VIEW',
            ])->pluck('id')
        );

        // ── Rol Staff — acceso limitado ───────────────────────────────────
        $staffRole = \App\Models\Role::firstOrCreate(
            ['slug' => 'staff'],
            ['name' => 'Staff', 'description' => 'Acceso general de staff']
        );
        $staffRole->permissions()->sync(
            \App\Models\Permission::whereIn('slug', [
                'DASHBOARD_VIEW',
                'CLIENTS_VIEW',
                'PLANS_VIEW',
                'MEMBERSHIPS_VIEW',
            ])->pluck('id')
        );

        // ── Usuario admin por defecto ─────────────────────────────────────
        \App\Models\User::firstOrCreate(
            ['email' => 'admin@irongym.com'],
            [
                'name'     => 'Admin',
                'username' => 'admin',
                'password' => \Illuminate\Support\Facades\Hash::make('admin123'),
                'role_id'  => $adminRole->id,
                'active'   => true,
            ]
        );

        $this->command->info('✅ Roles y permisos creados/actualizados correctamente.');
    }
}
