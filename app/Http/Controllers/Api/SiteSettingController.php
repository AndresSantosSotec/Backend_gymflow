<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SiteSettingController extends Controller
{
    public function index()
    {
        $settings = SiteSetting::first();

        if (!$settings) {
            // Retornar configuración por defecto
            return response()->json([
                'gymName' => 'IronGym',
                'slogan' => 'Tu mejor versión te espera',
                'aboutText' => 'Somos un gimnasio moderno y completo, dedicado a ayudarte a alcanzar tus metas de fitness.',
                'phone' => '+502 1234-5678',
                'whatsapp' => '+502 1234-5678',
                'instagram' => '@irongym',
                'primaryColor' => 'oklch(0.65 0.25 285)',
                'heroImages' => [],
                'themeColors' => null,
                'animationSettings' => null,
                'sections' => [],
                'updatedAt' => now()->toISOString(),
            ]);
        }

        return response()->json([
            'gymName' => $settings->gym_name,
            'slogan' => $settings->slogan,
            'aboutText' => $settings->about_text,
            'phone' => $settings->phone,
            'whatsapp' => $settings->whatsapp,
            'instagram' => $settings->instagram,
            'primaryColor' => $settings->primary_color,
            'heroImages' => $settings->hero_images ?? [],
            'themeColors' => $settings->theme_colors,
            'animationSettings' => $settings->animation_settings,
            'sections' => $settings->sections ?? [],
            'updatedAt' => $settings->updated_at->toISOString(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'gymName' => 'required|string|max:255',
            'slogan' => 'nullable|string|max:255',
            'aboutText' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'whatsapp' => 'nullable|string|max:50',
            'instagram' => 'nullable|string|max:100',
            'primaryColor' => 'nullable|string|max:100',
            'heroImages' => 'nullable|array',
            'heroImages.*' => 'nullable|string|max:500',
            'themeColors' => 'nullable|array',
            'animationSettings' => 'nullable|array',
            'sections' => 'nullable|array',
        ]);

        // Buscar o crear configuración
        $settings = SiteSetting::first();

        if (!$settings) {
            $settings = new SiteSetting();
        }

        $settings->gym_name = $validated['gymName'];
        $settings->slogan = $validated['slogan'] ?? '';
        $settings->about_text = $validated['aboutText'] ?? '';
        $settings->phone = $validated['phone'] ?? '';
        $settings->whatsapp = $validated['whatsapp'] ?? '';
        $settings->instagram = $validated['instagram'] ?? '';
        $settings->primary_color = $validated['primaryColor'] ?? 'oklch(0.65 0.25 285)';
        $settings->hero_images = $validated['heroImages'] ?? [];
        $settings->theme_colors = $validated['themeColors'] ?? null;
        $settings->animation_settings = $validated['animationSettings'] ?? null;
        $settings->sections = $validated['sections'] ?? [];
        $settings->save();

        return response()->json([
            'gymName' => $settings->gym_name,
            'slogan' => $settings->slogan,
            'aboutText' => $settings->about_text,
            'phone' => $settings->phone,
            'whatsapp' => $settings->whatsapp,
            'instagram' => $settings->instagram,
            'primaryColor' => $settings->primary_color,
            'heroImages' => $settings->hero_images,
            'themeColors' => $settings->theme_colors,
            'animationSettings' => $settings->animation_settings,
            'sections' => $settings->sections,
            'updatedAt' => $settings->updated_at->toISOString(),
        ]);
    }

    public function uploadHeroImages(Request $request)
    {
        $request->validate([
            'images' => 'required|array|max:5',
            'images.*' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048', // Max 2MB por imagen
        ]);

        $uploadedUrls = [];

        foreach ($request->file('images') as $image) {
            // Generar nombre único
            $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();

            // Guardar en storage/app/public/hero-images
            $path = $image->storeAs('hero-images', $filename, 'public');

            // Generar URL pública
            $url = Storage::url($path);
            $uploadedUrls[] = $url;
        }

        return response()->json([
            'success' => true,
            'urls' => $uploadedUrls,
            'message' => count($uploadedUrls) . ' imagen(es) subida(s) exitosamente',
        ]);
    }

    public function deleteHeroImage(Request $request)
    {
        $request->validate([
            'url' => 'required|string',
        ]);

        $url = $request->input('url');

        // Extraer el path del storage desde la URL
        // Ejemplo: /storage/hero-images/123456_abc.jpg -> hero-images/123456_abc.jpg
        $path = str_replace('/storage/', '', parse_url($url, PHP_URL_PATH));

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);

            return response()->json([
                'success' => true,
                'message' => 'Imagen eliminada exitosamente',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Imagen no encontrada',
        ], 404);
    }
}
