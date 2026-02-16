<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    protected $fillable = [
        'client_id',
        'access_type',
        'verification_method',
        'qr_code',
        'fingerprint_id',
        'access_time',
        'camera_id',
        'photo_url',
        'status',
        'notes',
    ];

    protected $casts = [
        'access_time' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    // ─── Scopes ───

    public function scopeAllowed($query)
    {
        return $query->where('status', 'allowed');
    }

    public function scopeDenied($query)
    {
        return $query->where('status', 'denied');
    }

    public function scopeByFingerprint($query)
    {
        return $query->where('verification_method', 'fingerprint');
    }

    public function scopeByQR($query)
    {
        return $query->where('verification_method', 'qr');
    }
}
