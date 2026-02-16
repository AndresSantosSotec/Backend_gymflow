<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BlogPostController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $posts = \App\Models\BlogPost::with(['author', 'images'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);
        return response()->json($posts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'excerpt' => 'nullable|string',
            'status' => 'required|in:draft,published',
            'featured_image' => 'nullable|image|max:2048',
            'gallery_images.*' => 'nullable|image|max:2048',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['slug'] = \Illuminate\Support\Str::slug($validated['title']) . '-' . uniqid();

        // Handle featured image
        if ($request->hasFile('featured_image')) {
            $path = $request->file('featured_image')->store('blog-images', 'public');
            $validated['featured_image'] = $path;
        }
        
        if ($validated['status'] === 'published') {
            $validated['published_at'] = now();
        }

        $post = \App\Models\BlogPost::create($validated);

        // Handle gallery images
        if ($request->hasFile('gallery_images')) {
            foreach ($request->file('gallery_images') as $index => $image) {
                $path = $image->store('blog-gallery', 'public');
                $post->images()->create([
                    'image_path' => $path,
                    'order' => $index,
                ]);
            }
        }

        return response()->json($post->load('images'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $post = \App\Models\BlogPost::with(['author', 'images'])->findOrFail($id);
        return response()->json($post);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $post = \App\Models\BlogPost::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'excerpt' => 'nullable|string',
            'status' => 'sometimes|required|in:draft,published',
            'featured_image' => 'nullable|image|max:2048',
            'gallery_images.*' => 'nullable|image|max:2048',
            'remove_image_ids' => 'nullable|array',
            'remove_image_ids.*' => 'integer|exists:blog_post_images,id',
        ]);

        if (isset($validated['title'])) {
             $validated['slug'] = \Illuminate\Support\Str::slug($validated['title']) . '-' . uniqid();
        }

        // Handle featured image update
        if ($request->hasFile('featured_image')) {
            if ($post->featured_image) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($post->featured_image);
            }
            $path = $request->file('featured_image')->store('blog-images', 'public');
            $validated['featured_image'] = $path;
        }

        if (isset($validated['status']) && $validated['status'] === 'published' && !$post->published_at) {
            $validated['published_at'] = now();
        }

        $post->update($validated);

        // Handle adding new gallery images
        if ($request->hasFile('gallery_images')) {
            $currentMaxOrder = $post->images()->max('order') ?? -1;
            foreach ($request->file('gallery_images') as $index => $image) {
                $path = $image->store('blog-gallery', 'public');
                $post->images()->create([
                    'image_path' => $path,
                    'order' => $currentMaxOrder + 1 + $index,
                ]);
            }
        }

        // Handle removing gallery images
        if ($request->filled('remove_image_ids')) {
            $imagesToDelete = $post->images()->whereIn('id', $request->remove_image_ids)->get();
            foreach ($imagesToDelete as $image) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($image->image_path);
                $image->delete();
            }
        }

        return response()->json($post->load('images'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $post = \App\Models\BlogPost::with('images')->findOrFail($id);
        
        // Delete featured image
        if ($post->featured_image) {
             \Illuminate\Support\Facades\Storage::disk('public')->delete($post->featured_image);
        }

        // Delete gallery images
        foreach ($post->images as $image) {
             \Illuminate\Support\Facades\Storage::disk('public')->delete($image->image_path);
        }

        $post->delete();
        return response()->json(null, 204);
    }
}
