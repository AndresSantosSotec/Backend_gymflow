<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EconomicProfileItem extends Model
{
    protected $fillable = [
        'client_id',
        'type',
        'category',
        'source',
        'monthly_amount',
        'active',
    ];

    protected $casts = [
        'monthly_amount' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
