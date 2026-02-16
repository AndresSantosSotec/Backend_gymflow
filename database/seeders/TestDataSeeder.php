<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\MembershipPlan;
use App\Models\Client;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Lead;
use App\Models\AccessLog;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TestDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Verificar si ya hay datos
        if (MembershipPlan::count() > 0) {
            $this->command->warn('⚠️  Ya existen datos en la base de datos.');
            $this->command->warn('   Ejecuta: php artisan migrate:fresh --seed');
            $this->command->warn('   O elimina los datos manualmente antes de continuar.');
            return;
        }

        // 1. Crear Planes de Membresía
        $plans = [
            [
                'name' => 'Básico Mensual',
                'slug' => 'basico-mensual',
                'description' => 'Acceso al gimnasio en horario regular',
                'price' => 299.00,
                'duration_days' => 30,
                'features' => json_encode([
                    'Acceso al área de pesas',
                    'Acceso a cardio',
                    'Casillero personal',
                ]),
                'published' => true,
            ],
            [
                'name' => 'Premium Mensual',
                'slug' => 'premium-mensual',
                'description' => 'Acceso completo con clases grupales',
                'price' => 499.00,
                'duration_days' => 30,
                'features' => json_encode([
                    'Todo lo del plan Básico',
                    'Clases grupales',
                    'Acceso a sauna',
                    'Nutricionista 1 vez al mes',
                ]),
                'published' => true,
            ],
            [
                'name' => 'VIP Mensual',
                'slug' => 'vip-mensual',
                'description' => 'Acceso premium con entrenador personal',
                'price' => 899.00,
                'duration_days' => 30,
                'features' => json_encode([
                    'Todo lo del plan Premium',
                    '4 sesiones de entrenador personal',
                    'Acceso 24/7',
                    'Estacionamiento incluido',
                    'Toalla y proteína gratis',
                ]),
                'published' => true,
            ],
            [
                'name' => 'Trimestral',
                'slug' => 'trimestral',
                'description' => 'Plan de 3 meses con descuento',
                'price' => 799.00,
                'duration_days' => 90,
                'features' => json_encode([
                    'Acceso completo por 3 meses',
                    'Todas las clases grupales',
                    'Evaluación física mensual',
                ]),
                'published' => true,
            ],
            [
                'name' => 'Anual',
                'slug' => 'anual',
                'description' => 'Plan anual con máximo descuento',
                'price' => 2999.00,
                'duration_days' => 365,
                'features' => json_encode([
                    'Acceso completo por 12 meses',
                    'Todas las clases y servicios',
                    'Valoración nutricional trimestral',
                    'Invitado gratis 1 vez al mes',
                ]),
                'published' => true,
            ],
        ];

        foreach ($plans as $planData) {
            MembershipPlan::create($planData);
        }

        // 2. Crear Clientes con Membresías Activas
        $basicPlan = MembershipPlan::where('slug', 'basico-mensual')->first();
        $premiumPlan = MembershipPlan::where('slug', 'premium-mensual')->first();
        $vipPlan = MembershipPlan::where('slug', 'vip-mensual')->first();

        $clients = [
            [
                'first_name' => 'Juan',
                'last_name' => 'Pérez',
                'email' => 'juan.perez@example.com',
                'phone' => '1234567890',
                'birth_date' => '1990-05-15',
                'qr_code' => 'QR' . strtoupper(Str::random(8)),
                'status' => 'active',
                'plan' => $basicPlan,
            ],
            [
                'first_name' => 'María',
                'last_name' => 'García',
                'email' => 'maria.garcia@example.com',
                'phone' => '0987654321',
                'birth_date' => '1988-03-22',
                'qr_code' => 'QR' . strtoupper(Str::random(8)),
                'status' => 'active',
                'plan' => $premiumPlan,
            ],
            [
                'first_name' => 'Carlos',
                'last_name' => 'Rodríguez',
                'email' => 'carlos.rodriguez@example.com',
                'phone' => '5551234567',
                'birth_date' => '1985-11-08',
                'qr_code' => 'QR' . strtoupper(Str::random(8)),
                'status' => 'active',
                'plan' => $vipPlan,
            ],
            [
                'first_name' => 'Ana',
                'last_name' => 'Martínez',
                'email' => 'ana.martinez@example.com',
                'phone' => '5559876543',
                'birth_date' => '1992-07-30',
                'qr_code' => 'QR' . strtoupper(Str::random(8)),
                'status' => 'active',
                'plan' => $basicPlan,
            ],
            [
                'first_name' => 'Luis',
                'last_name' => 'Hernández',
                'email' => 'luis.hernandez@example.com',
                'phone' => '5552223333',
                'birth_date' => '1987-02-14',
                'qr_code' => 'QR' . strtoupper(Str::random(8)),
                'status' => 'inactive', // Cliente sin membresía activa
                'plan' => null,
            ],
        ];

        foreach ($clients as $clientData) {
            $plan = $clientData['plan'];
            unset($clientData['plan']);

            $client = Client::create($clientData);

            // Si tiene plan asignado, crear membresía y pago
            if ($plan) {
                $startDate = Carbon::now()->subDays(rand(1, 20));
                $endDate = $startDate->copy()->addDays($plan->duration_days);

                // Crear pago
                $payment = Payment::create([
                    'client_id' => $client->id,
                    'amount' => $plan->price,
                    'payment_method' => ['cash', 'card', 'transfer'][rand(0, 2)],
                    'status' => 'completed',
                    'transaction_id' => 'TXN' . strtoupper(Str::random(10)),
                    'notes' => 'Pago de membresía ' . $plan->name,
                ]);

                // Crear membresía
                $membership = Membership::create([
                    'client_id' => $client->id,
                    'plan_id' => $plan->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'active',
                    'auto_renew' => rand(0, 1) == 1,
                ]);

                // Actualizar el pago con el membership_id
                $payment->update(['membership_id' => $membership->id]);

                // Crear algunos logs de acceso
                for ($i = 0; $i < rand(3, 10); $i++) {
                    AccessLog::create([
                        'client_id' => $client->id,
                        'access_type' => 'entry',
                        'qr_code' => $client->qr_code,
                        'access_time' => Carbon::now()->subDays(rand(0, 15))->setHour(rand(6, 21)),
                        'status' => 'allowed',
                        'notes' => 'Acceso normal',
                    ]);
                }
            }
        }

        // 3. Crear algunos Leads
        $leads = [
            [
                'first_name' => 'Pedro',
                'last_name' => 'González',
                'email' => 'pedro.gonzalez@example.com',
                'phone' => '5554445555',
                'status' => 'new',
                'source' => 'Facebook',
                'plan_slug' => 'basico-mensual',
                'preferred_payment_method' => 'card',
                'notes' => 'Interesado en clases de spinning',
            ],
            [
                'first_name' => 'Laura',
                'last_name' => 'Sánchez',
                'email' => 'laura.sanchez@example.com',
                'phone' => '5556667777',
                'status' => 'contacted',
                'source' => 'Instagram',
                'plan_slug' => 'premium-mensual',
                'preferred_payment_method' => 'cash',
                'notes' => 'Preguntó por horarios de yoga',
            ],
            [
                'first_name' => 'Roberto',
                'last_name' => 'López',
                'email' => 'roberto.lopez@example.com',
                'phone' => '5558889999',
                'status' => 'interested',
                'source' => 'Recomendación',
                'plan_slug' => 'vip-mensual',
                'preferred_payment_method' => 'transfer',
                'notes' => 'Quiere entrenador personal',
            ],
        ];

        foreach ($leads as $leadData) {
            Lead::create($leadData);
        }

        $this->command->info('✅ Datos de prueba creados exitosamente:');
        $this->command->info('   - ' . MembershipPlan::count() . ' planes de membresía');
        $this->command->info('   - ' . Client::count() . ' clientes');
        $this->command->info('   - ' . Membership::count() . ' membresías activas');
        $this->command->info('   - ' . Payment::count() . ' pagos');
        $this->command->info('   - ' . AccessLog::count() . ' logs de acceso');
        $this->command->info('   - ' . Lead::count() . ' leads');
    }
}
