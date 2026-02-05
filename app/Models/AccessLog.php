<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    protected $fillable = [
        'client_id',
        'access_type',
        'qr_code',
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
}
