<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Agrega permisos para módulo de Productos de Inscripción y los asigna al rol admin.
 * No borra ni modifica usuarios ni otros datos. Seguro ejecutar en producción.
 *
 * Uso: php artisan db:seed --class=AddRegistrationProductsPermissionSeeder
 */
class AddRegistrationProductsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Los productos de inscripción usan los mismos permisos que membresías
        // ya que están bajo el mismo menú de gestión

        $adminRole = Role::where('slug', 'admin')->first();

        if (!$adminRole) {
            $this->command->warn('Rol admin no encontrado. Verifica que RolesAndPermissionsSeeder se haya ejecutado.');
            return;
        }

        // Los productos de inscripción usan MEMBERSHIPS_VIEW y MEMBERSHIPS_MANAGE
        // que ya deberían existir, solo verificamos que el admin los tenga
        $permissions = Permission::whereIn('slug', [
            'MEMBERSHIPS_VIEW',
            'MEMBERSHIPS_MANAGE',
        ])->get();

        if ($permissions->count() > 0) {
            $adminRole->permissions()->syncWithoutDetaching($permissions->pluck('id'));
            $this->command->info('✅ Permisos de membresías asignados al rol admin (incluye productos de inscripción).');
        } else {
            $this->command->warn('⚠️  Permisos de membresías no encontrados. Ejecuta RolesAndPermissionsSeeder primero.');
        }

        // Actualizar también recepcionista para que pueda ver productos de inscripción
        $receptionRole = Role::where('slug', 'recepcionista')->first();
        if ($receptionRole) {
            $receptionRole->permissions()->syncWithoutDetaching($permissions->pluck('id'));
            $this->command->info('✅ Permisos de membresías asignados al rol recepcionista (incluye productos de inscripción).');
        }
    }
}
