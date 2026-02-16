<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoInventario extends Model
{
    protected $table = 'movimiento_inventarios';
    protected $fillable = ['producto_id', 'tipo', 'cantidad', 'motivo', 'referencia_id'];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
