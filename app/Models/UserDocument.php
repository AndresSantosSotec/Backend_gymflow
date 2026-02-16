<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class UserDocument extends Model
{
    protected $fillable = ['user_id', 'name', 'url', 'type', 'category'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
