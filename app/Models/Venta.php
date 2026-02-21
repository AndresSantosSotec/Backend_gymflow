<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $fillable = ['cliente_venta_id', 'total', 'estado'];

    public function cliente()
    {
        return $this->belongsTo(ClienteVenta::class, 'cliente_venta_id');
    }

    public function detalles()
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function pagos()
    {
        return $this->hasMany(PagoVenta::class);
    }

    public function receipt()
    {
        return $this->hasOne(Receipt::class);
    }
}
