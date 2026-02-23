<?php

namespace App\Jobs;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * FIX 5.1 — Notificación asíncrona para pagos adelantados.
 *
 * Se despacha DESPUÉS de que la transacción es exitosa.
 * Si falla el envío, Laravel reintenta automáticamente (MaxAttempts=3).
 *
 * Uso:
 *   NotificarPagoAdelantado::dispatch($clientId, $resultado)
 *       ->delay(now()->addSeconds(5)); // Pequeño delay para asegurar commit de BD
 */
class NotificarPagoAdelantado implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries      = 3;
    public int $backoff    = 60; // 60s entre reintentos
    public int $timeout    = 30;

    public function __construct(
        public readonly int   $clientId,
        public readonly array $resultado,
        public readonly ?int  $registeredBy = null,
    ) {}

    public function handle(): void
    {
        $client = Client::find($this->clientId);

        if (! $client) {
            Log::warning("[NotificarPago] Cliente #{$this->clientId} no encontrado. Skip.");
            return;
        }

        $resultado = $this->resultado;

        try {
            // ── Email al cliente (si tiene email) ─────────────────────────
            if ($client->email) {
                Mail::send([], [], function ($message) use ($client, $resultado) {
                    $message->to($client->email, $client->full_name)
                            ->subject('✅ Confirmación de pago adelantado - IronGym')
                            ->html($this->buildEmailHtml($client, $resultado));
                });

                Log::info("[NotificarPago] Email enviado a {$client->email}");
            }

            // ── Log de envío en BD para auditoría ─────────────────────────
            \Illuminate\Support\Facades\DB::table('notification_log')->insert([
                'client_id'    => $this->clientId,
                'type'         => 'pago_adelantado',
                'channel'      => 'email',
                'status'       => 'sent',
                'payload'      => json_encode($resultado),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("[NotificarPago] Error enviando notificación a cliente #{$this->clientId}: " . $e->getMessage());
            throw $e; // Relanzar para que Laravel reintente
        }
    }

    private function buildEmailHtml(Client $client, array $resultado): string
    {
        $nombre    = $client->full_name;
        $cuotas    = $resultado['cuotas_pagadas'] ?? 0;
        $monto     = number_format($resultado['monto_aplicado'] ?? 0, 2);
        $metodo    = $resultado['metodo_pago'] ?? '';
        $proxima   = $resultado['proxima_cuota'] ?? null;
        $proxFmt   = $proxima ? \Carbon\Carbon::parse($proxima)->format('d/m/Y') : null;
        $descuento = $resultado['descuento_aplicado'] ?? 0;

        $proximaLinea = $proxFmt
            ? "<p>📅 <strong>Próximo cobro automático:</strong> {$proxFmt}</p>"
            : "<p>✅ Todas las cuotas pagadas. <strong>No habrá más cobros automáticos.</strong></p>";

        $descuentoLinea = $descuento > 0
            ? "<p>🎁 <strong>Descuento aplicado:</strong> Q" . number_format($descuento, 2) . "</p>"
            : "";

        return "
            <html><body style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px'>
            <h2 style='color:#2563eb'>✅ Pago adelantado registrado</h2>
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>Confirmamos el registro de tu pago adelantado en IronGym.</p>
            <table style='width:100%;border-collapse:collapse;margin:20px 0'>
              <tr style='background:#f3f4f6'>
                <td style='padding:10px;border:1px solid #e5e7eb'><strong>Cuotas pagadas</strong></td>
                <td style='padding:10px;border:1px solid #e5e7eb'>{$cuotas} mes(es)</td>
              </tr>
              <tr>
                <td style='padding:10px;border:1px solid #e5e7eb'><strong>Monto</strong></td>
                <td style='padding:10px;border:1px solid #e5e7eb'>Q{$monto}</td>
              </tr>
              <tr style='background:#f3f4f6'>
                <td style='padding:10px;border:1px solid #e5e7eb'><strong>Método de pago</strong></td>
                <td style='padding:10px;border:1px solid #e5e7eb'>" . ucfirst($metodo) . "</td>
              </tr>
            </table>
            {$descuentoLinea}
            {$proximaLinea}
            <p style='color:#6b7280;font-size:13px'>Este es un mensaje automático. Por favor no respondas a este email.</p>
            <p style='color:#6b7280;font-size:13px'>IronGym — Sistema de Gestión de Gimnasio</p>
            </body></html>
        ";
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[NotificarPago] Job fallido definitivamente para cliente #{$this->clientId}: " . $exception->getMessage());

        // Registrar el fallo en BD para que el admin lo vea
        try {
            \Illuminate\Support\Facades\DB::table('notification_log')->insert([
                'client_id'  => $this->clientId,
                'type'       => 'pago_adelantado',
                'channel'    => 'email',
                'status'     => 'failed',
                'payload'    => json_encode(['error' => $exception->getMessage()]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {}
    }
}
