<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MembershipPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'price',
        'duration_days',
        'description',
        'features',
        'published',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_days' => 'integer',
        'features' => 'array',
        'published' => 'boolean',
    ];

    public function memberships()
    {
        return $this->hasMany(Membership::class, 'plan_id');
    }
}
