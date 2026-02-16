<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClienteVenta extends Model
{
    protected $table = 'cliente_ventas';
    protected $fillable = ['nombre', 'nit', 'ciudad', 'telefono', 'correo'];

    public function ventas()
    {
        return $this->hasMany(Venta::class, 'cliente_venta_id');
    }
}
