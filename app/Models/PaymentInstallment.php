<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentInstallment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'membership_id',
        'client_id',
        'installment_number',
        'amount',
        'amount_paid',
        'due_date',
        'status',
        'payment_id',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'installment_number' => 'integer',
    ];

    public function membership()
    {
        return $this->belongsTo(Membership::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Remaining balance on this installment
     */
    public function getRemainingAttribute(): float
    {
        return max(0, (float)$this->amount - (float)$this->amount_paid);
    }

    /**
     * Check if overdue
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status !== 'paid' && $this->due_date->lt(now()->startOfDay());
    }
}
