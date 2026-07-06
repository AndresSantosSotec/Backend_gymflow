<?php

namespace App\Services;

use App\Models\Receipt;
use App\Services\CorpoFel\CorpoFelClient;
use App\Services\CorpoFel\FelDteBuilder;
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

            $receipt->load(['client', 'payment', 'membership', 'venta.cliente']);

            $invoiceData = $this->prepareInvoiceData($receipt);

            // Según el provider, llamar al servicio correspondiente
            $result = match ($this->provider) {
                'corpo_fel' => $this->sendToCorpoFel($receipt),
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
        $client = $receipt->client;
        if (!$client && $receipt->venta) {
            $client = $receipt->venta->cliente;
        }

        return [
            'invoice_number' => $receipt->invoice_number,
            'receipt_number' => $receipt->receipt_number,
            'invoice_date' => $receipt->invoiced_at->format('Y-m-d'),
            'invoice_time' => $receipt->invoiced_at->format('H:i:s'),

            // Empresa
            'company' => [
                'name' => config('app.name', 'IronGym'),
                'tax_id' => config('site.company_tax_id', ''),
                'address' => config('site.company_address', ''),
                'phone' => config('site.company_phone', ''),
                'email' => config('site.company_email', ''),
            ],

            // Cliente
            'client' => [
                'name' => $client instanceof \App\Models\Client
                    ? ($client->company_name ?? $client->full_name ?? 'Cliente Anónimo')
                    : ($client?->nombre ?? 'Cliente Anónimo'),
                'tax_id' => $client instanceof \App\Models\Client
                    ? ($client->nit ?? $client->dni ?? 'CF')
                    : ($client?->nit ?? 'CF'),
                'email' => $client instanceof \App\Models\Client
                    ? ($client->email ?? '')
                    : ($client?->correo ?? ''),
                'phone' => $client instanceof \App\Models\Client
                    ? ($client->phone ?? '')
                    : ($client?->telefono ?? ''),
                'address' => $client instanceof \App\Models\Client
                    ? ($client->fiscal_address ?? $client->address ?? 'CIUDAD')
                    : ($client?->ciudad ?? 'CIUDAD'),
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
     * Certificar DTE con Corpo Sistemas (FEL Guatemala).
     */
    private function sendToCorpoFel(Receipt $receipt): array
    {
        try {
            $felPayment = app(FelPaymentService::class);
            $client = $receipt->client ?? $receipt->venta?->cliente ?? null;
            $receptor = $felPayment->resolveReceptor($client);

            $builder = app(FelDteBuilder::class);
            $dteXml = $builder->buildFromReceipt($receipt, $receptor);

            $client = app(CorpoFelClient::class);
            $response = $client->certifyDocument($dteXml);

            if (!($response['success'] ?? false)) {
                return [
                    'success' => false,
                    'provider' => 'corpo_fel',
                    'fel_status' => 'failed',
                    'error' => $response['error'] ?? 'Error al certificar con Corpo Sistemas',
                    'raw' => $response['raw'] ?? null,
                    'parsed' => $response['parsed'] ?? null,
                ];
            }

            $guid = $this->extractFelGuid($response);
            $serie = $response['parsed']['data2_json']['serie'] ?? $response['parsed']['batch'] ?? null;
            $numero = $response['parsed']['data2_json']['numero'] ?? $response['parsed']['serial'] ?? null;

            if ($guid && (empty($serie) || empty($numero))) {
                $info = $client->getDocumentInfo($guid);
                if ($info['success'] ?? false) {
                    $serie = $info['parsed']['data2_json']['serie'] ?? $info['parsed']['batch'] ?? null;
                    $numero = $info['parsed']['data2_json']['numero'] ?? $info['parsed']['serial'] ?? null;
                }
            }

            $emissionDate = FelDteBuilder::extractEmissionDateFromCertifyResponse($response['parsed'] ?? [])
                ?? FelDteBuilder::formatFelDateTime(now('America/Guatemala'));

            return [
                'success' => true,
                'provider' => 'corpo_fel',
                'fel_status' => 'certified',
                'uuid' => $guid,
                'request_uuid' => $response['request_uuid'] ?? null,
                'serie' => $serie,
                'numero' => $numero,
                'receptor' => $receptor,
                'emission_date' => $emissionDate,
                'certified_at' => now()->toIso8601String(),
                'parsed' => $response['parsed'] ?? null,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'provider' => 'corpo_fel',
                'fel_status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function extractFelGuid(array $response): ?string
    {
        $parsed = $response['parsed'] ?? [];

        if (!empty($parsed['document_guid'])) {
            return strtoupper($parsed['document_guid']);
        }

        if (!empty($parsed['data2_json']['uuid'])) {
            return strtoupper((string) $parsed['data2_json']['uuid']);
        }

        if (!empty($parsed['data2_decoded'])) {
            if (preg_match('/[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}/i', $parsed['data2_decoded'], $m)) {
                return strtoupper($m[0]);
            }
        }

        return null;
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
                'corpo_fel' => $this->cancelCorpoFel($receipt, $billingData),
                'facturama' => $this->cancelFacturama($billingData),
                'sat' => $this->cancelSAT($billingData),
                'local' => $this->cancelLocal($billingData),
                default => $this->cancelLocal($billingData),
            };

            if ($result['success']) {
                $receipt->update([
                    'status' => 'cancelled',
                    'details' => array_merge($receipt->details ?? [], [
                        'electronic_billing' => array_merge($billingData, [
                            'fel_status' => 'voided',
                            'voided_at' => now()->toIso8601String(),
                            'void_result' => $result,
                        ]),
                    ]),
                ]);
                Log::info("Invoice cancelled: {$receipt->invoice_number}");
            } else {
                $receipt->update([
                    'details' => array_merge($receipt->details ?? [], [
                        'electronic_billing' => array_merge($billingData, [
                            'last_void_attempt_at' => now()->toIso8601String(),
                            'last_void_error' => $result['error'] ?? $result['message'] ?? null,
                            'last_void_tr_code' => $result['tr_code'] ?? null,
                        ]),
                    ]),
                ]);
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
     * Anular documento FEL en Corpo Sistemas.
     */
    private function cancelCorpoFel(Receipt $receipt, array $billingData): array
    {
        $uuid = $billingData['uuid'] ?? null;
        if (!$uuid) {
            return ['success' => false, 'provider' => 'corpo_fel', 'error' => 'UUID FEL no encontrado'];
        }

        if (($billingData['fel_status'] ?? null) === 'voided') {
            return [
                'success' => true,
                'provider' => 'corpo_fel',
                'message' => 'El DTE ya fue anulado previamente',
                'already_voided' => true,
            ];
        }

        $client = $receipt->client;
        if (!$client && $receipt->venta) {
            $client = $receipt->venta->cliente;
        }

        $receptorId = (string) ($billingData['receptor']['id'] ?? 'CF');
        if ($receptorId === 'CF' && $client?->nit) {
            $receptorId = preg_replace('/\D/', '', (string) $client->nit) ?: 'CF';
        }

        $emissionDate = $billingData['emission_date'] ?? null;
        if (!$emissionDate) {
            $client = app(CorpoFelClient::class);
            $info = $client->getDocumentInfo($uuid);
            $emissionDate = FelDteBuilder::extractEmissionDateFromInfoResponse($info['parsed'] ?? []);
        }
        if (!$emissionDate && !empty($billingData['certified_at'])) {
            $emissionDate = \Carbon\Carbon::parse($billingData['certified_at'])
                ->timezone('America/Guatemala')
                ->format('Y-m-d\TH:i:s');
        }
        if (!$emissionDate) {
            $emissionDate = ($receipt->invoiced_at ?? now())
                ->copy()
                ->timezone('America/Guatemala')
                ->format('Y-m-d\TH:i:s');
        }

        $builder = app(FelDteBuilder::class);
        $voidXml = $builder->buildVoidXml($uuid, $receptorId, $emissionDate);

        $client = app(CorpoFelClient::class);
        $response = $client->voidDocument($voidXml);
        $errorMessage = $response['error'] ?? 'Error al anular';
        $trCode = $this->extractFelTrCode($errorMessage);

        return [
            'success' => (bool) ($response['success'] ?? false),
            'provider' => 'corpo_fel',
            'message' => ($response['success'] ?? false) ? 'Documento anulado en FEL' : $errorMessage,
            'error' => ($response['success'] ?? false) ? null : $errorMessage,
            'tr_code' => $trCode,
            'expected_in_pruebas' => in_array($trCode, ['1080', '1084'], true),
            'emission_date_used' => $emissionDate,
            'receptor_id_used' => $receptorId,
            'parsed' => $response['parsed'] ?? null,
        ];
    }

    private function extractFelTrCode(?string $message): ?string
    {
        if (!$message) {
            return null;
        }

        return preg_match('/TrCode:\s*\[(\d+)\]/', $message, $m) ? $m[1] : null;
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
