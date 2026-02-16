<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetodoPago extends Model
{
    protected $table = 'metodo_pagos';
    protected $fillable = ['nombre', 'activo'];

    public function pagos()
    {
        return $this->hasMany(PagoVenta::class, 'metodo_pago_id');
    }
}
