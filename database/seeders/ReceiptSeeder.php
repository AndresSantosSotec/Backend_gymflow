<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Receipt;
use App\Models\Client;
use App\Models\Payment;
use Carbon\Carbon;

class ReceiptSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear recibos de prueba para clientes existentes
        $clients = Client::limit(5)->get();

        foreach ($clients as $client) {
            // Crear un recibo por suscripción
            Receipt::create([
                'client_id' => $client->id,
                'receipt_number' => Receipt::generateReceiptNumber(),
                'type' => 'receipt',
                'payment_type' => 'subscription',
                'subtotal' => 50.00,
                'tax' => 0,
                'discount' => 0,
                'total' => 50.00,
                'status' => 'paid',
                'paid_at' => now(),
                'email_sent' => true,
                'email_sent_at' => now(),
                'sent_to_email' => $client->email ?? 'test@irongym.local',
                'is_invoiced' => true,
                'invoiced_at' => now(),
                'invoice_number' => Receipt::generateInvoiceNumber(),
                'description' => 'Membresía mensual',
                'details' => [
                    'plan' => 'Premium',
                    'duration' => '1 mes',
                    'period' => 'monthly',
                ],
                'created_at' => now()->subDays(rand(1, 30)),
            ]);

            // Crear un recibo pendiente
            Receipt::create([
                'client_id' => $client->id,
                'receipt_number' => Receipt::generateReceiptNumber(),
                'type' => 'receipt',
                'payment_type' => 'product',
                'subtotal' => 25.00,
                'tax' => 2.50,
                'discount' => 0,
                'total' => 27.50,
                'status' => 'pending',
                'description' => 'Compra de productos - Botella de agua + Toalla',
                'details' => [
                    'items' => [
                        ['name' => 'Botella de agua', 'quantity' => 1, 'price' => 15.00],
                        ['name' => 'Toalla deportiva', 'quantity' => 1, 'price' => 10.00],
                    ],
                ],
            ]);

            // Crear un recibo de curso
            Receipt::create([
                'client_id' => $client->id,
                'receipt_number' => Receipt::generateReceiptNumber(),
                'type' => 'proforma',
                'payment_type' => 'course',
                'subtotal' => 100.00,
                'tax' => 0,
                'discount' => 10.00,
                'total' => 90.00,
                'status' => 'draft',
                'description' => 'Curso de levantamiento de pesas - 4 semanas',
                'details' => [
                    'course' => 'Weightlifting 101',
                    'duration' => '4 weeks',
                    'instructor' => 'Juan Pérez',
            'discount_reason' => 'Miembro antiguo',
                ],
            ]);
        }

        $this->command->info('Receipt seeder completado');
    }
}
