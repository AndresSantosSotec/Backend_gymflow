<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecurrenteProducto extends Model
{
    use SoftDeletes;

    protected $table = 'recurrente_productos';

    protected $fillable = [
        'recurrente_product_id',
        'recurrente_price_id',
        'nombre',
        'descripcion',
        'monto_centavos',
        'tipo',
        'storefront_link',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'monto_centavos' => 'integer',
    ];

    public const TIPO_INSCRIPCION = 'inscripcion';
    public const TIPO_MENSUALIDAD = 'mensualidad';
    public const TIPO_CURSO = 'curso';
    public const TIPO_OTRO = 'otro';

    public static function tipos(): array
    {
        return [
            self::TIPO_INSCRIPCION => 'Inscripción',
            self::TIPO_MENSUALIDAD => 'Mensualidad',
            self::TIPO_CURSO => 'Curso específico',
            self::TIPO_OTRO => 'Otro',
        ];
    }

    public function getMontoQuetzalesAttribute(): float
    {
        return round($this->monto_centavos / 100, 2);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }
}
