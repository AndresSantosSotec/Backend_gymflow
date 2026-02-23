<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StartSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Definir TODOS los permisos basados en el frontend
        $permissionsList = [
            // Dashboard
            ['name' => 'Ver Dashboard', 'slug' => 'DASHBOARD_VIEW', 'description' => 'Permite ver las estadísticas generales'],

            // Clientes
            ['name' => 'Ver Clientes', 'slug' => 'CLIENTS_VIEW', 'description' => 'Permite ver la lista de clientes'],
            ['name' => 'Crear Clientes', 'slug' => 'CLIENTS_CREATE', 'description' => 'Permite registrar nuevos clientes'],
            ['name' => 'Editar Clientes', 'slug' => 'CLIENTS_EDIT', 'description' => 'Permite modificar datos de clientes'],
            ['name' => 'Eliminar Clientes', 'slug' => 'CLIENTS_DELETE', 'description' => 'Permite dar de baja clientes'],

            // Planes
            ['name' => 'Ver Planes', 'slug' => 'PLANS_VIEW', 'description' => 'Permite ver los planes de membresía'],
            ['name' => 'Gestionar Planes', 'slug' => 'PLANS_MANAGE', 'description' => 'Permite crear, editar y publicar planes'],

            // Membresías
            ['name' => 'Ver Membresías', 'slug' => 'MEMBERSHIPS_VIEW', 'description' => 'Permite ver membresías activas'],
            ['name' => 'Gestionar Membresías', 'slug' => 'MEMBERSHIPS_MANAGE', 'description' => 'Permite asignar y renovar membresías'],

            // Pagos
            ['name' => 'Ver Pagos', 'slug' => 'PAYMENTS_VIEW', 'description' => 'Permite ver el historial de pagos'],
            ['name' => 'Gestionar Pagos', 'slug' => 'PAYMENTS_MANAGE', 'description' => 'Permite registrar cobros y devoluciones'],

            // Caja
            ['name' => 'Ver Caja', 'slug' => 'CASH_VIEW', 'description' => 'Permite ver movimientos de caja'],
            ['name' => 'Gestionar Caja', 'slug' => 'CASH_MANAGE', 'description' => 'Permite realizar ingresos, egresos y cierres'],

            // Inventario
            ['name' => 'Ver Inventario', 'slug' => 'INVENTORY_VIEW', 'description' => 'Permite ver el stock de productos'],
            ['name' => 'Gestionar Inventario', 'slug' => 'INVENTORY_MANAGE', 'description' => 'Permite ajustar stock y catálogo'],

            // Accesos / Huellas Digitales — ROADMAP FUTURO
            // ['name' => 'Ver Accesos', 'slug' => 'ACCESS_VIEW', 'description' => 'Permite ver registros de entrada (QR/Huella)'],
            // ['name' => 'Gestionar Accesos', 'slug' => 'ACCESS_MANAGE', 'description' => 'Permite autorizar o denegar accesos manuales'],

            // Configuración y Sistema
            ['name' => 'Ver Configuración', 'slug' => 'SETTINGS_VIEW', 'description' => 'Permite ver los ajustes del sistema'],
            ['name' => 'Gestionar Configuración', 'slug' => 'SETTINGS_MANAGE', 'description' => 'Permite cambiar colores, logos y textos'],
            ['name' => 'Ver Roles', 'slug' => 'ROLES_VIEW', 'description' => 'Permite ver los roles del sistema'],
            ['name' => 'Gestionar Roles', 'slug' => 'ROLES_MANAGE', 'description' => 'Permite crear y editar permisos de roles'],
            ['name' => 'Ver Usuarios/Staff', 'slug' => 'USERS_VIEW', 'description' => 'Permite ver la lista de empleados'],
            ['name' => 'Gestionar Usuarios/Staff', 'slug' => 'USERS_MANAGE', 'description' => 'Permite contratar y editar staff'],

            // Planificados / Extras
            ['name' => 'Ver Reportes', 'slug' => 'REPORTS_VIEW', 'description' => 'Permite ver reportes y estadísticas avanzadas'],
            ['name' => 'Ver Notificaciones', 'slug' => 'NOTIFICATIONS_VIEW', 'description' => 'Permite ver notificaciones del sistema'],
            // ['name' => 'Ver Cámaras', 'slug' => 'CAMERAS_VIEW', 'description' => 'Permite ver las cámaras de seguridad'], // ROADMAP FUTURO

            // Módulo Comercial
            ['name' => 'Ver Productos', 'slug' => 'PRODUCTS_VIEW', 'description' => 'Permite ver el catálogo de productos'],
            ['name' => 'Crear Productos', 'slug' => 'PRODUCTS_CREATE', 'description' => 'Permite agregar nuevos productos al catálogo'],
            ['name' => 'Editar Productos', 'slug' => 'PRODUCTS_EDIT', 'description' => 'Permite modificar precios y datos de productos'],
            ['name' => 'Eliminar Productos', 'slug' => 'PRODUCTS_DELETE', 'description' => 'Permite eliminar productos del catálogo'],
            ['name' => 'Ingreso de Inventario', 'slug' => 'INVENTORY_IN', 'description' => 'Permite registrar entradas de mercadería'],
            ['name' => 'Egreso de Inventario', 'slug' => 'INVENTORY_OUT', 'description' => 'Permite registrar salidas/ajustes de stock'],
            ['name' => 'Ver Ventas', 'slug' => 'SALES_VIEW', 'description' => 'Permite ver el historial de ventas'],
            ['name' => 'Realizar Ventas', 'slug' => 'SALES_CREATE', 'description' => 'Permite usar el POS para vender productos'],
            ['name' => 'Ver Cotizaciones', 'slug' => 'QUOTES_VIEW', 'description' => 'Permite generar y ver cotizaciones'],
            ['name' => 'Gestionar Clientes Ventas', 'slug' => 'SALES_CLIENTS_MANAGE', 'description' => 'Permite crear y editar clientes comerciales'],
        ];

        foreach ($permissionsList as $p) {
            Permission::updateOrCreate(['slug' => $p['slug']], $p);
        }

        // 2. Crear Roles con sus asignaciones

        // --- ADMIN: Todo
        $adminRole = Role::updateOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrador', 'description' => 'Control total del gimnasio']
        );
        $adminRole->permissions()->sync(Permission::all()->pluck('id'));

        // --- MANAGER: Operaciones y gestión, pero no roles críticos de sistema
        $managerRole = Role::updateOrCreate(
            ['slug' => 'manager'],
            ['name' => 'Gerente', 'description' => 'Gestión operativa y personal']
        );
        $managerPermissions = Permission::whereNotIn('slug', [
            'ROLES_MANAGE', 'SETTINGS_MANAGE'
        ])->pluck('id');
        $managerRole->permissions()->sync($managerPermissions);

        // --- STAFF: Atención al cliente y ventas
        $staffRole = Role::updateOrCreate(
            ['slug' => 'staff'],
            ['name' => 'Staff / Recepción', 'description' => 'Atención al cliente, cobros y accesos']
        );
        $staffPermissions = Permission::whereIn('slug', [
            'DASHBOARD_VIEW',
            'CLIENTS_VIEW', 'CLIENTS_CREATE', 'CLIENTS_EDIT',
            'MEMBERSHIPS_VIEW', 'MEMBERSHIPS_MANAGE',
            'PAYMENTS_VIEW', 'PAYMENTS_MANAGE',
            'CASH_VIEW', 'CASH_MANAGE',
            'INVENTORY_VIEW'
        ])->pluck('id');
        $staffRole->permissions()->sync($staffPermissions);

        // 3. Crear 3 Usuarios Parametrizados

        // Usuario Admin
        User::updateOrCreate(
            ['email' => 'admin@irongym.com'],
            [
                'name' => 'Dueño IronGym',
                'username' => 'admin',
                'password' => Hash::make('admin123'),
                'role_id' => $adminRole->id,
                'active' => true,
                'position' => 'Propietario',
                'hire_date' => now(),
            ]
        );

        // Usuario Gerente
        User::updateOrCreate(
            ['email' => 'gerente@irongym.com'],
            [
                'name' => 'Carlos Gerente',
                'username' => 'carlos.manager',
                'password' => Hash::make('manager123'),
                'role_id' => $managerRole->id,
                'active' => true,
                'position' => 'Gerente de Sucursal',
                'phone' => '555123456',
                'hire_date' => now()->subMonths(6),
            ]
        );

        // Usuario Staff/Recepción
        User::updateOrCreate(
            ['email' => 'recepcion@irongym.com'],
            [
                'name' => 'Ana Recepción',
                'username' => 'ana.staff',
                'password' => Hash::make('staff123'),
                'role_id' => $staffRole->id,
                'active' => true,
                'position' => 'Recepcionista',
                'phone' => '555987654',
                'hire_date' => now()->subMonths(2),
            ]
        );
    }
}
