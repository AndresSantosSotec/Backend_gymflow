<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'status',
        'source',
        'notes',
        'contacted_at',
        'plan_slug',
        'preferred_payment_method',
    ];

    protected $casts = [
        'contacted_at' => 'datetime',
    ];
}
