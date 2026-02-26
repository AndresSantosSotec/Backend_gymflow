<?php

namespace App\Services;

use App\Models\RecurrenteProducto;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para crear y gestionar productos de pago único en Recurrente
 * y su copia local en recurrente_productos.
 */
class RecurrenteProductoService
{
    public function __construct(
        private RecurrenteService $recurrente
    ) {}

    /**
     * Crea un producto de pago único en Recurrente y lo guarda en BD local.
     *
     * @param array $datos ['nombre', 'descripcion'?, 'monto' (en quetzales), 'tipo', 'activo'?]
     * @return RecurrenteProducto
     * @throws \Exception
     */
    public function crearProductoPagoUnico(array $datos): RecurrenteProducto
    {
        $baseUrl = config('app.frontend_url', config('app.url', 'http://localhost:5173'));

        $payload = [
            'product' => [
                'name'        => $datos['nombre'],
                'description' => $datos['descripcion'] ?? '',
                'prices_attributes' => [[
                    'currency'        => 'GTQ',
                    'charge_type'     => 'one_time',
                    'amount_in_cents' => (int) round((float) ($datos['monto'] ?? 0) * 100),
                ]],
                'success_url' => $datos['success_url'] ?? "{$baseUrl}/p/pago-exitoso",
                'cancel_url'  => $datos['cancel_url'] ?? "{$baseUrl}/p/pago-cancelado",
            ],
        ];

        Log::info('[RecurrenteProducto] Creando producto en Recurrente', ['nombre' => $datos['nombre']]);

        $response = $this->recurrente->createOneTimeProduct($payload);

        if (empty($response['id'])) {
            throw new \Exception('Recurrente no devolvió ID de producto: ' . json_encode($response));
        }

        return $this->guardarProductoLocal($response, $datos);
    }

    /**
     * Guarda o actualiza el producto en la tabla local recurrente_productos.
     */
    public function guardarProductoLocal(array $recurrenteResponse, array $datos): RecurrenteProducto
    {
        $recurrenteProductId = $recurrenteResponse['id'] ?? null;
        $prices = $recurrenteResponse['prices'] ?? [];
        $recurrentePriceId = $prices[0]['id'] ?? null;
        $amountInCents = $prices[0]['amount_in_cents'] ?? (int) round((float) ($datos['monto'] ?? 0) * 100);

        $producto = RecurrenteProducto::create([
            'recurrente_product_id' => $recurrenteProductId,
            'recurrente_price_id'   => $recurrentePriceId,
            'nombre'                => $datos['nombre'],
            'descripcion'           => $datos['descripcion'] ?? null,
            'monto_centavos'        => $amountInCents,
            'tipo'                  => $datos['tipo'] ?? RecurrenteProducto::TIPO_INSCRIPCION,
            'storefront_link'       => $recurrenteResponse['storefront_link'] ?? null,
            'activo'                => $datos['activo'] ?? true,
        ]);

        Log::info('[RecurrenteProducto] Producto guardado localmente', [
            'id' => $producto->id,
            'recurrente_product_id' => $recurrenteProductId,
        ]);

        return $producto;
    }

    /**
     * Elimina el producto en Recurrente (si existe ID) y soft-delete local.
     */
    public function eliminarProducto(RecurrenteProducto $producto): void
    {
        if ($producto->recurrente_product_id) {
            try {
                $this->recurrente->deleteProduct($producto->recurrente_product_id);
            } catch (\Exception $e) {
                Log::warning('[RecurrenteProducto] No se pudo eliminar en Recurrente', [
                    'id' => $producto->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $producto->delete();
    }
}
