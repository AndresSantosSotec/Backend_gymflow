<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipPause extends Model
{
    protected $fillable = [
        'membership_id', 'client_id', 'approved_by',
        'pause_start', 'pause_end', 'pause_days',
        'reason', 'notes',
        'recurrente_sub_cancelled', 'recurrente_sub_new',
        'status', 'completed_at', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'pause_start'  => 'date',
        'pause_end'    => 'date',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function membership() { return $this->belongsTo(Membership::class); }
    public function client()     { return $this->belongsTo(Client::class); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
}
