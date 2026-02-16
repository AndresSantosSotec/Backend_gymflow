<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'type',
        'quantity',
        'reference',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
