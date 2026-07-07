<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MembershipPlan extends Model
{
    use SoftDeletes;

    // ── Tipos de servicio disponibles (cobros independientes) ──────────
    const TYPE_MEMBERSHIP        = 'membership';        // Mensualidad general
    const TYPE_PERSONAL_TRAINING = 'personal_training'; // Entrenamiento personalizado
    const TYPE_NUTRITION         = 'nutrition';          // Nutrición / dieta
    const TYPE_COURSE            = 'course';             // Curso específico
    const TYPE_OTHER             = 'other';              // Otro servicio adicional

    /** Etiquetas legibles para mostrar en el frontend */
    const TYPE_LABELS = [
        self::TYPE_MEMBERSHIP        => 'Mensualidad',
        self::TYPE_PERSONAL_TRAINING => 'Entrenamiento Personalizado',
        self::TYPE_NUTRITION         => 'Nutrición',
        self::TYPE_COURSE            => 'Curso',
        self::TYPE_OTHER             => 'Otro',
    ];

    protected $fillable = [
        'name',
        'slug',
        'plan_type',
        'price',
        'duration_days',
        'description',
        'features',
        'published',
        'recurrente_product_id',
        'recurrente_price_id',
    ];

    protected $casts = [
        'price'         => 'decimal:2',
        'duration_days' => 'integer',
        'features'      => 'array',
        'published'     => 'boolean',
    ];

    public function memberships()
    {
        return $this->hasMany(Membership::class, 'plan_id');
    }
}
