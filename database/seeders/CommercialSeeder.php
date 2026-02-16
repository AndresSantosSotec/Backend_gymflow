<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CommercialSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Metodos de Pago
        $metodoEfectivo = \App\Models\MetodoPago::firstOrCreate(['nombre' => 'EFECTIVO', 'activo' => true]);
        \App\Models\MetodoPago::firstOrCreate(['nombre' => 'TRANSFERENCIA', 'activo' => true]);
        \App\Models\MetodoPago::firstOrCreate(['nombre' => 'TARJETA', 'activo' => true]);

        // 2. Marcas de ejemplo
        $marcaGatorade = \App\Models\Marca::firstOrCreate(['nombre' => 'Gatorade']);
        $marcaOptimum = \App\Models\Marca::firstOrCreate(['nombre' => 'Optimum Nutrition']);
        
        // 3. Presentaciones de ejemplo
        $presBotella = \App\Models\Presentacion::firstOrCreate(['nombre' => '600ml']);
        $presBote = \App\Models\Presentacion::firstOrCreate(['nombre' => '5lb']);

        // 4. Productos de Ejemplo
        $producto1 = \App\Models\Producto::firstOrCreate([
            'nombre' => 'Gatorade Blue Bolt',
        ], [
            'descripcion' => 'Bebida hidratante sabor mora azul.',
            'marca_id' => $marcaGatorade->id,
            'presentacion_id' => $presBotella->id,
            'precio_compra' => 8.00,
            'precio_venta' => 12.00,
            'stock' => 50,
        ]);

        $producto2 = \App\Models\Producto::firstOrCreate([
            'nombre' => 'Gold Standard Whey Protein',
        ], [
            'descripcion' => 'Proteína de suero de leche sabor vainilla.',
            'marca_id' => $marcaOptimum->id,
            'presentacion_id' => $presBote->id,
            'precio_compra' => 450.00,
            'precio_venta' => 650.00,
            'stock' => 10,
        ]);

        // 5. Movimientos de inventario iniciales
        if ($producto1->wasRecentlyCreated) {
            \App\Models\MovimientoInventario::create([
                'producto_id' => $producto1->id,
                'tipo' => 'INGRESO',
                'cantidad' => 50,
                'motivo' => 'Inventario Inicial',
                'created_at' => now(),
            ]);
        }

        if ($producto2->wasRecentlyCreated) {
            \App\Models\MovimientoInventario::create([
                'producto_id' => $producto2->id,
                'tipo' => 'INGRESO',
                'cantidad' => 10,
                'motivo' => 'Inventario Inicial',
                'created_at' => now(),
            ]);
        }

        // Permisos nuevos... (resto igual)
        $permissions = [
            ['name' => 'Ver Productos', 'slug' => 'PRODUCTS_VIEW'],
            ['name' => 'Crear Productos', 'slug' => 'PRODUCTS_CREATE'],
            ['name' => 'Editar Productos', 'slug' => 'PRODUCTS_EDIT'],
            ['name' => 'Eliminar Productos', 'slug' => 'PRODUCTS_DELETE'],
            
            ['name' => 'Ver Inventario', 'slug' => 'INVENTORY_VIEW'],
            ['name' => 'Registrar Ingresos', 'slug' => 'INVENTORY_IN'],
            ['name' => 'Registrar Egresos', 'slug' => 'INVENTORY_OUT'],
            
            ['name' => 'Ver Ventas', 'slug' => 'SALES_VIEW'],
            ['name' => 'Crear Ventas', 'slug' => 'SALES_CREATE'],
            ['name' => 'Ver Cotizaciones', 'slug' => 'QUOTES_VIEW'],
            
            ['name' => 'Gestionar Clientes Ventas', 'slug' => 'SALES_CLIENTS_MANAGE'],
        ];

        foreach ($permissions as $p) {
            \App\Models\Permission::firstOrCreate(['slug' => $p['slug']], $p);
        }

        // Asignar a Admin por defecto
        $adminRole = \App\Models\Role::where('slug', 'admin')->first();
        if ($adminRole) {
            $adminRole->permissions()->syncWithoutDetaching(\App\Models\Permission::all()->pluck('id'));
        }
    }
}
