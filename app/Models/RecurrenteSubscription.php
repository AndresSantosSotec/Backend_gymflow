<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurrenteSubscription extends Model
{
    protected $fillable = [
        'client_id',
        'membership_plan_id',
        'recurrente_subscription_id',
        'recurrente_product_id',
        'status',
        'current_period_start',
        'current_period_end',
        'metadata',
    ];

    protected $casts = [
        'metadata'             => 'array',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
