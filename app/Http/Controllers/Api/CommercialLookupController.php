<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Marca;
use App\Models\Presentacion;
use App\Models\MetodoPago;
use Illuminate\Http\Request;

class CommercialLookupController extends Controller
{
    public function index()
    {
        return response()->json([
            'marcas' => Marca::all(),
            'presentaciones' => Presentacion::all(),
            'metodos_pago' => MetodoPago::where('activo', true)->get(),
        ]);
    }
}
