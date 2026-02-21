<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurrentePayment extends Model
{
    protected $fillable = [
        'client_id',
        'membership_id',
        'membership_plan_id',
        'recurrente_payment_id',
        'recurrente_subscription_id',
        'recurrente_checkout_id',
        'type',
        'amount_in_cents',
        'currency',
        'status',
        'concept',
        'metadata',
        'paid_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'paid_at'  => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /** Monto en quetzales (para mostrar en UI) */
    public function getAmountAttribute(): float
    {
        return $this->amount_in_cents / 100;
    }
}
