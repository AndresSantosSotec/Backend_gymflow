<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class SiteSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpiar tabla existente para evitar duplicados en pruebas
        SiteSetting::truncate();

        SiteSetting::create([
            'gym_name' => 'GymFlow Fitness',
            'slogan' => 'Transforma tu cuerpo, transforma tu vida',
            'about_text' => 'En GymFlow ofrecemos equipos de última generación, entrenadores certificados y un ambiente motivador para ayudarte a alcanzar tus metas de fitness.',
            'phone' => '+502 5555-5555',
            'whatsapp' => '+502 4444-4444',
            'instagram' => '@gymflow_gt',
            'primary_color' => 'oklch(0.65 0.25 285)', // Legacy support
            'hero_images' => [
                'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=1470&auto=format&fit=crop',
                'https://images.unsplash.com/photo-1517836357463-d25dfeac3438?q=80&w=1470&auto=format&fit=crop',
                'https://images.unsplash.com/photo-1581009146145-b5ef050c2e1e?q=80&w=1470&auto=format&fit=crop',
            ],
            'theme_colors' => [
                'admin' => [
                    'font' => 'Inter',
                    'colors' => [
                        'primary' => '#4f46e5',
                        'background' => '#ffffff',
                        'foreground' => '#0f172a',
                        'sidebar' => '#1e293b',
                        'sidebarForeground' => '#ffffff',
                        'card' => '#ffffff',
                        'cardForeground' => '#0f172a',
                        'border' => '#e2e8f0',
                    ],
                ],
                'public' => [
                    'font' => 'Roboto',
                    'colors' => [
                        'primary' => '#2563eb',
                        'secondary' => '#64748b',
                        'background' => '#ffffff',
                        'foreground' => '#0f172a',
                        'card' => '#ffffff',
                        'cardForeground' => '#0f172a',
                        'border' => '#e2e8f0',
                    ],
                ],
            ],
            'animation_settings' => [
                'enabled' => true,
                'heroAnimation' => 'fade',
                'cardAnimation' => 'slide',
            ],
            'sections' => [
                [
                    'id' => 'sec-1',
                    'type' => 'plans',
                    'title' => 'Planes de Membresía',
                    'subtitle' => 'Elige el plan que mejor se adapte a ti',
                    'order' => 1,
                    'settings' => ['limit' => 3, 'showPrice' => true],
                ],
                [
                    'id' => 'sec-2',
                    'type' => 'products',
                    'title' => 'Suplementos Destacados',
                    'subtitle' => 'Potencia tu entrenamiento con nuestros productos',
                    'order' => 2,
                    'settings' => ['limit' => 4, 'showPrice' => true],
                ],
                [
                    'id' => 'sec-3',
                    'type' => 'testimonials',
                    'title' => 'Lo que dicen nuestros clientes',
                    'subtitle' => 'Historias reales de transformación',
                    'order' => 3,
                ]
            ],
        ]);
    }
}
