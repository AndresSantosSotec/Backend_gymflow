<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Permission;
use App\Models\User;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    /**
     * The permissions that belong to the role.
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * The users that belong to the role.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
