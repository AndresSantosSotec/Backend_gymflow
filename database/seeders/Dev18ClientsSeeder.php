<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Client;
use App\Models\MembershipPlan;
use App\Models\Membership;
use Carbon\Carbon;
use Illuminate\Support\Str;

class Dev18ClientsSeeder extends Seeder
{
    public function run(): void
    {
        // Asegurarse de que haya al menos un plan
        $plan = MembershipPlan::first();
        if (!$plan) {
            $plan = MembershipPlan::create([
                'name'          => 'Mensual',
                'slug'          => 'mensual',
                'description'   => 'Plan mensual básico',
                'price'         => 250.00,
                'duration_days' => 30,
                'features'      => json_encode(['Acceso completo']),
                'published'     => true,
            ]);
        }

        $clients = [
            ['first_name' => 'Carlos',    'last_name' => 'Ramírez Pérez',     'phone' => '55001001', 'birth_date' => '1995-03-12'],
            ['first_name' => 'María',     'last_name' => 'González López',    'phone' => '55001002', 'birth_date' => '1992-07-24'],
            ['first_name' => 'Diego',     'last_name' => 'Méndez Torres',     'phone' => '55001003', 'birth_date' => '1998-11-05'],
            ['first_name' => 'Sofía',     'last_name' => 'Herrera Castillo',  'phone' => '55001004', 'birth_date' => '1997-01-30'],
            ['first_name' => 'Luis',      'last_name' => 'Morales Fuentes',   'phone' => '55001005', 'birth_date' => '2000-06-18'],
            ['first_name' => 'Ana',       'last_name' => 'Reyes Villatoro',   'phone' => '55001006', 'birth_date' => '1994-09-22'],
            ['first_name' => 'Roberto',   'last_name' => 'Cifuentes Lima',    'phone' => '55001007', 'birth_date' => '1989-04-14'],
            ['first_name' => 'Valeria',   'last_name' => 'Solis Marroquín',   'phone' => '55001008', 'birth_date' => '2001-12-03'],
            ['first_name' => 'Fernando',  'last_name' => 'Aguilar Monzón',    'phone' => '55001009', 'birth_date' => '1993-08-27'],
            ['first_name' => 'Gabriela',  'last_name' => 'López Samayoa',     'phone' => '55001010', 'birth_date' => '1996-02-15'],
            ['first_name' => 'Javier',    'last_name' => 'Pineda Orellana',   'phone' => '55001011', 'birth_date' => '1990-05-20'],
            ['first_name' => 'Isabella',  'last_name' => 'Cruz Bethancourt',  'phone' => '55001012', 'birth_date' => '1999-10-08'],
            ['first_name' => 'Miguel',    'last_name' => 'Estrada Barrios',   'phone' => '55001013', 'birth_date' => '1987-03-31'],
            ['first_name' => 'Natalia',   'last_name' => 'Vásquez Fuentes',   'phone' => '55001014', 'birth_date' => '2002-07-17'],
            ['first_name' => 'Alejandro', 'last_name' => 'Ramos Juárez',      'phone' => '55001015', 'birth_date' => '1991-11-25'],
            ['first_name' => 'Camila',    'last_name' => 'Sandoval Ajú',      'phone' => '55001016', 'birth_date' => '1998-04-09'],
            ['first_name' => 'Sebastián', 'last_name' => 'Flores Tzul',       'phone' => '55001017', 'birth_date' => '1985-09-13'],
            ['first_name' => 'Valentina', 'last_name' => 'Ortiz Menchú',      'phone' => '55001018', 'birth_date' => '2003-01-28'],
        ];

        $now = Carbon::now();

        foreach ($clients as $i => $data) {
            $number = str_pad($i + 1, 3, '0', STR_PAD_LEFT);
            $email  = strtolower(
                iconv('UTF-8', 'ASCII//TRANSLIT', $data['first_name']) . '.' .
                iconv('UTF-8', 'ASCII//TRANSLIT', explode(' ', $data['last_name'])[0]) .
                "{$number}@gymdev.test"
            );

            // Skip if already exists
            if (Client::where('email', $email)->exists()) {
                $this->command->line("  Skip: {$email} ya existe");
                continue;
            }

            $client = Client::create([
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'email'      => $email,
                'phone'      => $data['phone'],
                'birth_date' => $data['birth_date'],
                'qr_code'    => 'QR-' . strtoupper(Str::random(10)),
                'status'     => 'active',
            ]);

            // Membresía activa de 30 días
            Membership::create([
                'client_id'  => $client->id,
                'plan_id'    => $plan->id,
                'start_date' => $now->copy()->subDays(5)->toDateString(),
                'end_date'   => $now->copy()->addDays(25)->toDateString(),
                'status'     => 'active',
                'auto_renew' => false,
            ]);

            $this->command->info("  ✓ {$data['first_name']} {$data['last_name']} (id={$client->id})");
        }

        $this->command->info("\n✅ Dev18ClientsSeeder completado.");
    }
}
