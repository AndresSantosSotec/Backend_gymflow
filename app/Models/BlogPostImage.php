<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlogPostImage extends Model
{
    protected $fillable = [
        'blog_post_id',
        'image_path',
        'order',
    ];

    public function post()
    {
        return $this->belongsTo(BlogPost::class);
    }
}
