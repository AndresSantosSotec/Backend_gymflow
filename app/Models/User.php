<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Role;
use App\Models\UserPhoto;
use App\Models\UserDocument;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role_id',
        'active',
        'phone',
        'address',
        'birth_date',
        'position',
        'hire_date',
        'salary',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'notes',
        'photo',
        'cv_url',
        'fingerprint_id',
        'fingerprint_registered_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'birth_date' => 'date',
            'hire_date' => 'date',
            'salary' => 'decimal:2',
            'fingerprint_registered_at' => 'datetime',
        ];
    }

    /**
     * Get the role associated with the user.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the photos for the user.
     */
    public function photos()
    {
        return $this->hasMany(UserPhoto::class);
    }

    /**
     * Get the documents for the user.
     */
    public function documents()
    {
        return $this->hasMany(UserDocument::class);
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission($permissionSlug)
    {
        if (!$this->role) return false;
        return $this->role->permissions()->where('slug', $permissionSlug)->exists();
    }
}
