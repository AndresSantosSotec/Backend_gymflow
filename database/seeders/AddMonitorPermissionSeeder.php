<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Agrega solo el permiso MONITOR_VIEW y lo asigna al rol admin.
 * No borra ni modifica usuarios ni otros datos. Seguro ejecutar en producción.
 *
 * Uso: php artisan db:seed --class=AddMonitorPermissionSeeder
 */
class AddMonitorPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::firstOrCreate(
            ['slug' => 'MONITOR_VIEW'],
            [
                'name'        => 'Ver Monitor / Logs',
                'description' => 'Solo admin: ver logs detallados del sistema para depuración',
            ]
        );

        $adminRole = Role::where('slug', 'admin')->first();

        if ($adminRole) {
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
            $this->command->info('Permiso MONITOR_VIEW agregado al rol admin.');
        } else {
            $this->command->warn('Rol admin no encontrado. El permiso MONITOR_VIEW fue creado; asígnalo manualmente al admin si hace falta.');
        }
    }
}
