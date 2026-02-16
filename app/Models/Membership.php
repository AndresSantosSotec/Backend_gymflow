<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Membership extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'plan_id',
        'start_date',
        'end_date',
        'status',
        'auto_renew',
        'total_amount',
        'payment_type',
        'num_installments',
        'amount_paid',
        'payment_status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'auto_renew' => 'boolean',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'num_installments' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function plan()
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function installments()
    {
        return $this->hasMany(PaymentInstallment::class)->orderBy('installment_number');
    }

    /**
     * Saldo pendiente de la membresía
     */
    public function getBalanceAttribute(): float
    {
        return max(0, (float)$this->total_amount - (float)$this->amount_paid);
    }

    /**
     * Update payment status based on installments
     */
    public function recalculatePaymentStatus(): void
    {
        $totalPaid = (float) $this->installments()->sum('amount_paid');
        $this->amount_paid = $totalPaid;

        if ($totalPaid >= (float) $this->total_amount) {
            $this->payment_status = 'paid';
        } elseif ($totalPaid > 0) {
            // Check if any installment is overdue
            $hasOverdue = $this->installments()
                ->where('status', '!=', 'paid')
                ->where('due_date', '<', now()->startOfDay())
                ->exists();
            $this->payment_status = $hasOverdue ? 'overdue' : 'partial';
        } else {
            $hasOverdue = $this->installments()
                ->where('due_date', '<', now()->startOfDay())
                ->exists();
            $this->payment_status = $hasOverdue ? 'overdue' : 'pending';
        }

        $this->save();
    }
}
