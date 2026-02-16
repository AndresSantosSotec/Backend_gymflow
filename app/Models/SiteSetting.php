<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = [
        'gym_name',
        'slogan',
        'about_text',
        'phone',
        'whatsapp',
        'instagram',
        'primary_color',
        'hero_images',
        'theme_colors',
        'animation_settings',
        'sections',
    ];

    protected $casts = [
        'hero_images' => 'array',
        'theme_colors' => 'array',
        'animation_settings' => 'array',
        'sections' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
