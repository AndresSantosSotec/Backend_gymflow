<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $fillable = [
        'nombre', 'descripcion', 'marca_id', 'presentacion_id', 
        'precio_compra', 'precio_venta', 'stock', 'image_url'
    ];

    public function marca()
    {
        return $this->belongsTo(Marca::class);
    }

    public function presentacion()
    {
        return $this->belongsTo(Presentacion::class);
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoInventario::class);
    }
}
