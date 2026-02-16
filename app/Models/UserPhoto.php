<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class UserPhoto extends Model
{
    protected $fillable = ['user_id', 'url', 'type'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
