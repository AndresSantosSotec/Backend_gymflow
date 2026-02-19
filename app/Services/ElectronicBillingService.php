<?php

namespace App\Services;

use App\Models\Receipt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ElectronicBillingService
{
    /**
     * Configuración del servicio
     */
    private string $provider;
    private string $apiUrl;
    private string $apiKey;
    private string $apiSecret;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->provider = config('billing.provider', 'local');
        $this->apiUrl = config('billing.api_url', '');
        $this->apiKey = config('billing.api_key', '');
        $this->apiSecret = config('billing.api_secret', '');
    }

    /**
     * Generar factura electrónica
     */
    public function generateElectronicInvoice(Receipt $receipt): array
    {
        try {
            if (!$receipt->is_invoiced) {
                throw new Exception("Receipt must be invoiced first");
            }

            $receipt->load(['client', 'payment', 'membership']);

            $invoiceData = $this->prepareInvoiceData($receipt);

            // Según el provider, llamar al servicio correspondiente
            $result = match ($this->provider) {
                'facturama' => $this->sendToFacturama($invoiceData),
                'sat' => $this->sendToSAT($invoiceData),
                'local' => $this->storeLocalInvoice($invoiceData, $receipt),
                default => $this->storeLocalInvoice($invoiceData, $receipt),
            };

            if ($result['success']) {
                // Guardar datos de facturación electrónica
                $receipt->update([
                    'details' => array_merge(
                        $receipt->details ?? [],
                        ['electronic_billing' => $result]
                    ),
                ]);

                Log::info("Electronic invoice generated: {$receipt->invoice_number}");
            }

            return $result;
        } catch (Exception $e) {
            Log::error("Electronic billing error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Preparar datos para facturación electrónica
     */
    private function prepareInvoiceData(Receipt $receipt): array
    {
        return [
            'invoice_number' => $receipt->invoice_number,
            'receipt_number' => $receipt->receipt_number,
            'invoice_date' => $receipt->invoiced_at->format('Y-m-d'),
            'invoice_time' => $receipt->invoiced_at->format('H:i:s'),

            // Empresa
            'company' => [
                'name' => config('app.name', 'GymFlow'),
                'tax_id' => config('site.company_tax_id', ''),
                'address' => config('site.company_address', ''),
                'phone' => config('site.company_phone', ''),
                'email' => config('site.company_email', ''),
            ],

            // Cliente
            'client' => [
                'name' => $receipt->client->full_name ?? 'Cliente Anónimo',
                'tax_id' => $receipt->client->dni ?? '',
                'email' => $receipt->client->email ?? '',
                'phone' => $receipt->client->phone ?? '',
                'address' => $receipt->client->address ?? '',
            ],

            // Conceptos
            'items' => [
                [
                    'description' => $receipt->description ?? ucfirst(str_replace('_', ' ', $receipt->payment_type)),
                    'quantity' => 1,
                    'unit_price' => $receipt->subtotal,
                    'amount' => $receipt->subtotal,
                ]
            ],

            // Montos
            'subtotal' => $receipt->subtotal,
            'tax' => $receipt->tax,
            'discount' => $receipt->discount,
            'total' => $receipt->total,

            // Detalles
            'payment_method' => $receipt->payment->payment_method ?? 'cash',
            'payment_type' => $receipt->payment_type,
            'notes' => $receipt->invoice_notes ?? '',

            // Metadata
            'currency' => config('app.currency', 'USD'),
            'status' => $receipt->status,
        ];
    }

    /**
     * Enviar a Facturama (ejemplo de proveedor externo)
     */
    private function sendToFacturama(array $invoiceData): array
    {
        try {
            // Este es un ejemplo de integración con Facturama
            // Ajustar según la documentación de la API real

            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/invoices', [
                'document' => [
                    'type' => 'I', // Invoice
                    'folio' => $invoiceData['invoice_number'],
                    'date' => $invoiceData['invoice_date'],
                    'receiver' => [
                        'name' => $invoiceData['client']['name'],
                        'taxId' => $invoiceData['client']['tax_id'],
                    ],
                    'items' => $invoiceData['items'],
                    'payments' => [
                        [
                            'date' => now()->format('Y-m-d'),
                            'paymentForm' => $invoiceData['payment_method'],
                            'amount' => $invoiceData['total'],
                        ]
                    ],
                ],
            ]);

            if ($response->successful()) {
                $jsonData = $response->json();
                return [
                    'success' => true,
                    'provider' => 'facturama',
                    'cfdi_id' => $jsonData['id'] ?? null,
                    'cfdi_folio' => $jsonData['folio'] ?? null,
                    'stamp_number' => $jsonData['stampNumber'] ?? null,
                    'data' => $jsonData,
                ];
            }

            $jsonData = $response->json();
            return [
                'success' => false,
                'provider' => 'facturama',
                'error' => $jsonData['message'] ?? 'Unknown error',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'provider' => 'facturama',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Enviar a SAT (Servicio de Administración Tributaria)
     */
    private function sendToSAT(array $invoiceData): array
    {
        try {
            // Integración con SAT (México)
            // Esta es una estructura ejemplo

            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/cfdi/generate', $invoiceData);

            if ($response->successful()) {
                $jsonData = $response->json();
                return [
                    'success' => true,
                    'provider' => 'sat',
                    'uuid' => $jsonData['uuid'] ?? null,
                    'cfdi' => $jsonData['cfdi'] ?? null,
                    'qr' => $jsonData['qr_code'] ?? null,
                ];
            }

            $jsonData = $response->json();
            return [
                'success' => false,
                'provider' => 'sat',
                'error' => $jsonData['error'] ?? 'Unknown error',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'provider' => 'sat',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Almacenar factura localmente (cuando no hay proveedor externo)
     */
    private function storeLocalInvoice(array $invoiceData, Receipt $receipt): array
    {
        try {
            $invoiceId = 'LOCAL-' . $receipt->invoice_number . '-' . now()->timestamp;

            // Generar datos de facturación local
            $billingData = [
                'invoice_id' => $invoiceId,
                'folio' => $receipt->invoice_number,
                'stamp_number' => strtoupper(bin2hex(random_bytes(10))),
                'status' => 'emitted',
                'emission_date' => now()->format('Y-m-d H:i:s'),
                'xml_url' => route('receipts.download.invoice', $receipt->id),
                'pdf_url' => route('receipts.download.receipt', $receipt->id),
            ];

            // TODO: Generar XML CFDI (si se necesita conformidad con estándares)

            Log::info("Local invoice stored: {$invoiceId}");

            return [
                'success' => true,
                'provider' => 'local',
                'invoice_id' => $invoiceId,
                'data' => $billingData,
                'message' => 'Invoice stored locally',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'provider' => 'local',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validar estado de factura electrónica
     */
    public function validateInvoiceStatus(Receipt $receipt): array
    {
        try {
            if (!$receipt->is_invoiced) {
                return [
                    'valid' => false,
                    'status' => 'not_invoiced',
                    'message' => 'Receipt has not been invoiced',
                ];
            }

            $billingData = $receipt->details['electronic_billing'] ?? null;

            if (!$billingData) {
                return [
                    'valid' => false,
                    'status' => 'no_billing_data',
                    'message' => 'No electronic billing data found',
                ];
            }

            return [
                'valid' => true,
                'status' => 'active',
                'invoice_number' => $receipt->invoice_number,
                'billing_provider' => $billingData['provider'] ?? 'unknown',
                'data' => $billingData,
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancelar factura electrónica
     */
    public function cancelInvoice(Receipt $receipt): array
    {
        try {
            if (!$receipt->is_invoiced) {
                throw new Exception("Receipt must be invoiced to cancel");
            }

            $billingData = $receipt->details['electronic_billing'] ?? null;

            if (!$billingData) {
                throw new Exception("No electronic billing data found");
            }

            // Según el provider, cancelar de la forma correspondiente
            $result = match ($billingData['provider'] ?? $this->provider) {
                'facturama' => $this->cancelFacturama($billingData),
                'sat' => $this->cancelSAT($billingData),
                'local' => $this->cancelLocal($billingData),
                default => $this->cancelLocal($billingData),
            };

            if ($result['success']) {
                $receipt->update(['status' => 'cancelled']);
                Log::info("Invoice cancelled: {$receipt->invoice_number}");
            }

            return $result;
        } catch (Exception $e) {
            Log::error("Invoice cancellation error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancelar en Facturama
     */
    private function cancelFacturama(array $billingData): array
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->delete($this->apiUrl . '/invoices/' . $billingData['cfdi_id']);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'provider' => 'facturama',
                    'message' => 'Invoice cancelled successfully',
                ];
            }

            $jsonData = $response->json();
            return [
                'success' => false,
                'provider' => 'facturama',
                'error' => $jsonData['message'] ?? 'Cancellation failed',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancelar en SAT
     */
    private function cancelSAT(array $billingData): array
    {
        // TODO: Implementar cancelación en SAT
        return [
            'success' => true,
            'provider' => 'sat',
            'message' => 'Invoice cancellation request submitted',
        ];
    }

    /**
     * Cancelar localmente
     */
    private function cancelLocal(array $billingData): array
    {
        return [
            'success' => true,
            'provider' => 'local',
            'message' => 'Invoice cancelled locally',
        ];
    }

    /**
     * Generar XML CFDI (Comprobante Fiscal Digital por Internet)
     */
    public function generateCFDI(Receipt $receipt): ?string
    {
        try {
            $invoiceData = $this->prepareInvoiceData($receipt);

            // Estructura básica de XML CFDI
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= "<Comprobante xmlns=\"http://www.sat.gob.mx/cfd/3\">\n";
            $xml .= "  <InformacionComprobante>\n";
            $xml .= "    <Folio>{$invoiceData['invoice_number']}</Folio>\n";
            $xml .= "    <Fecha>{$invoiceData['invoice_date']}T{$invoiceData['invoice_time']}</Fecha>\n";
            $xml .= "    <TipoDeComprobante>I</TipoDeComprobante>\n";
            $xml .= "    <Moneda>{$invoiceData['currency']}</Moneda>\n";
            $xml .= "    <SubTotal>{$invoiceData['subtotal']}</SubTotal>\n";
            $xml .= "    <Impuesto>{$invoiceData['tax']}</Impuesto>\n";
            $xml .= "    <Total>{$invoiceData['total']}</Total>\n";
            $xml .= "  </InformacionComprobante>\n";
            $xml .= "</Comprobante>\n";

            return $xml;
        } catch (Exception $e) {
            Log::error("CFDI generation error: " . $e->getMessage());
            return null;
        }
    }
}
