<?php

namespace App\Services\CorpoFel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class CorpoFelClient
{
    private string $baseUrl;
    private string $nitUrl;
    private string $requestor;
    private string $entityNit;
    private string $country;

    public function __construct()
    {
        $cfg = config('billing.corpo_fel');
        $useTest = (bool) ($cfg['use_test'] ?? true);
        $this->baseUrl = $useTest ? $cfg['base_url_test'] : $cfg['base_url'];
        $this->nitUrl = $cfg['nit_url'];
        $this->requestor = $cfg['requestor'];
        $this->entityNit = $cfg['entity_nit'];
        $this->country = $cfg['country'] ?? 'GT';
    }

    public function authenticate(string $username, string $password): array
    {
        $payload = base64_encode(json_encode([
            'numero_nit' => $this->entityNit,
            'codigo_usuario' => $username,
            'password' => $password,
        ], JSON_UNESCAPED_UNICODE));

        return $this->systemRequest('USUARIO_LOGIN', $payload);
    }

    public function consultCui(string $cui): array
    {
        return $this->systemRequest('CONSULTA_CUI', preg_replace('/\D/', '', $cui));
    }

    public function consultNit(string $nit): array
    {
        $cleanNit = strtoupper(trim($nit));
        $cleanNit = preg_replace('/[^0-9K]/', '', $cleanNit);

        $response = Http::timeout(30)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($this->nitUrl, [
                'vNIT' => $cleanNit,
                'Entity' => $this->entityNit,
                'Requestor' => $this->requestor,
            ]);

        if (!$response->successful()) {
            return [
                'success' => false,
                'error' => 'Error HTTP al consultar NIT: ' . $response->status(),
                'raw' => $response->body(),
            ];
        }

        $data = $response->json() ?? ['raw' => $response->body()];
        $nitFound = ($data['result'] ?? false) === true;

        return [
            'success' => $nitFound,
            'data' => $data,
            'error' => $nitFound
                ? null
                : ($data['message'] ?? 'NIT no encontrado en SAT'),
        ];
    }

    public function certifyDocument(string $dteXml): array
    {
        $uuid = (string) Str::uuid();
        $base64 = base64_encode($dteXml);

        $envelope = $this->buildEnvelope(
            transaction: 'SYSTEM_REQUEST',
            data1: 'POST_DOCUMENT_SAT',
            data2: $base64,
            data3: $uuid,
            useWsPrefix: true,
        );

        $result = $this->postSoap($envelope);
        $result['request_uuid'] = $uuid;

        return $result;
    }

    public function getDocumentInfo(string $guid): array
    {
        return $this->systemRequest('GET_INFODTE', $guid);
    }

    public function getDocumentPdf(string $guid): array
    {
        return $this->getDocument($guid, 'PDF');
    }

    public function getDocumentXml(string $guid): array
    {
        return $this->getDocument($guid, 'XML');
    }

    public function getDocument(string $guid, string $format = 'PDF'): array
    {
        $format = strtoupper($format) === 'XML' ? 'XML' : 'PDF';

        $envelope = $this->buildEnvelope(
            transaction: 'GET_DOCUMENT',
            data1: $guid,
            data2: '',
            data3: $format,
            useWsPrefix: true,
        );

        return $this->postSoap($envelope);
    }

    /**
     * Corpo devuelve el PDF en base64 en ResponseData3 (a veces Data2).
     */
    public function extractPdfContent(array $parsed): ?string
    {
        foreach (['data3', 'data2', 'data1'] as $field) {
            $value = $parsed[$field] ?? null;
            if (!$value || !is_string($value)) {
                continue;
            }

            $decoded = base64_decode(trim($value), true);
            if ($decoded !== false && str_starts_with($decoded, '%PDF')) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * XML certificado: suele venir en ResponseData1 al pedir PDF o XML.
     */
    public function extractXmlContent(array $parsed): ?string
    {
        foreach (['data1', 'data2', 'data3'] as $field) {
            $value = $parsed[$field] ?? null;
            if (!$value || !is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if (str_starts_with($trimmed, '<?xml') || str_contains($trimmed, '<dte:GTDocumento')) {
                return $trimmed;
            }

            $decoded = base64_decode($trimmed, true);
            if ($decoded !== false && (
                str_starts_with($decoded, '<?xml')
                || str_contains($decoded, '<dte:GTDocumento')
                || str_contains($decoded, ':GTDocumento')
            )) {
                return $decoded;
            }
        }

        if (!empty($parsed['data1_decoded']) && is_string($parsed['data1_decoded'])) {
            return $parsed['data1_decoded'];
        }

        return null;
    }

    public function voidDocument(string $voidXml): array
    {
        $base64 = base64_encode($voidXml);

        $envelope = $this->buildEnvelope(
            transaction: 'SYSTEM_REQUEST',
            data1: 'VOID_DOCUMENT',
            data2: $base64,
            data3: 'XML',
            useWsPrefix: true,
        );

        return $this->postSoap($envelope);
    }

    public function getPhrases(string $nit): array
    {
        return $this->systemRequest('MINIRTUFRFASES_QUERY_JSON', $nit);
    }

    public function getEstablishments(string $nit, string $establishmentId = '0'): array
    {
        $payload = json_encode([[
            'numero_nit' => $nit,
            'id_establecimiento' => $establishmentId,
        ]], JSON_UNESCAPED_UNICODE);
        return $this->systemRequest('ESTABLECIMIENTO_QUERY_JSON', $payload);
    }

    public function queryDateRange(
        string $dateFrom,
        string $dateTo,
        string $docType = '',
        string $buyerNit = '',
        string $uuid = '',
        string $ref = '',
        string $status = ''
    ): array {
        $payload = base64_encode(json_encode([
            'consulta_documentos' => [
                'fecha_documento_del' => $dateFrom,
                'fecha_documento_al' => $dateTo,
                'tipo_docto' => $docType,
                'nit_comprador' => $buyerNit,
                'uuid_documento' => $uuid,
                'referencia_interna' => $ref,
                'estado_documento' => $status,
            ]
        ], JSON_UNESCAPED_UNICODE));

        return $this->systemRequest('CONSULTA_DOCUMENTOS_JSON', $payload);
    }

    private function systemRequest(string $data1, string $data2, string $data3 = ''): array
    {
        $envelope = $this->buildEnvelope('SYSTEM_REQUEST', $data1, $data2, $data3, false);
        return $this->postSoap($envelope);
    }

    private function buildEnvelope(
        string $transaction,
        string $data1,
        string $data2,
        string $data3,
        bool $useWsPrefix,
    ): string {
        if ($useWsPrefix) {
            return '<?xml version="1.0" encoding="utf-8"?>'
                . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="http://www.fact.com.mx/schema/ws">'
                . '<SOAP-ENV:Header/>'
                . '<SOAP-ENV:Body>'
                . '<ws:RequestTransaction>'
                . '<ws:Requestor>' . $this->escape($this->requestor) . '</ws:Requestor>'
                . '<ws:Entity>' . $this->escape($this->entityNit) . '</ws:Entity>'
                . '<ws:User>' . $this->escape($this->requestor) . '</ws:User>'
                . '<ws:Transaction>' . $this->escape($transaction) . '</ws:Transaction>'
                . '<ws:Country>' . $this->escape($this->country) . '</ws:Country>'
                . '<ws:Data1>' . $this->escape($data1) . '</ws:Data1>'
                . '<ws:Data2>' . $this->escape($data2) . '</ws:Data2>'
                . '<ws:Data3>' . $this->escape($data3) . '</ws:Data3>'
                . '</ws:RequestTransaction>'
                . '</SOAP-ENV:Body>'
                . '</SOAP-ENV:Envelope>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<RequestTransaction xmlns="http://www.fact.com.mx/schema/ws">'
            . '<Requestor>' . $this->escape($this->requestor) . '</Requestor>'
            . '<Entity>' . $this->escape($this->entityNit) . '</Entity>'
            . '<User>' . $this->escape($this->requestor) . '</User>'
            . '<Transaction>' . $this->escape($transaction) . '</Transaction>'
            . '<Country>' . $this->escape($this->country) . '</Country>'
            . '<Data1>' . $this->escape($data1) . '</Data1>'
            . '<Data2>' . $this->escape($data2) . '</Data2>'
            . '<Data3>' . $this->escape($data3) . '</Data3>'
            . '</RequestTransaction>'
            . '</soap:Body>'
            . '</soap:Envelope>';
    }

    private function postSoap(string $envelope): array
    {
        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Content-Type' => 'text/xml; charset=utf-8',
                ])
                ->withBody($envelope, 'text/xml; charset=utf-8')
                ->post($this->baseUrl);

            $body = $response->body();
            $parsed = $this->parseSoapResponse($body);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Error HTTP ' . $response->status(),
                    'raw' => $body,
                    'request_raw' => $envelope,
                    'parsed' => $parsed,
                ];
            }

            $isError = $this->responseIndicatesError($parsed, $body);

            return [
                'success' => !$isError,
                'parsed' => $parsed,
                'raw' => $body,
                'request_raw' => $envelope,
                'error' => $isError ? $this->extractErrorMessage($parsed, $body) : null,
            ];
        } catch (Exception $e) {
            Log::error('CorpoFel SOAP error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function parseSoapResponse(string $body): array
    {
        $result = [
            'data1' => null,
            'data2' => null,
            'data3' => null,
            'response' => null,
            'result' => null,
            'description' => null,
            'document_guid' => null,
            'serial' => null,
            'batch' => null,
        ];

        if (trim($body) === '') {
            return $result;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            return $result;
        }

        $get = function (string $name) use ($xml): ?string {
            $found = $xml->xpath('//*[local-name()="' . $name . '"]');
            if (empty($found)) {
                return null;
            }
            $value = trim((string) $found[0]);
            return $value !== '' ? $value : null;
        };

        $result['result'] = match ($get('Result')) {
            'true' => true,
            'false' => false,
            default => null,
        };
        $result['description'] = $get('Description');
        $result['document_guid'] = $get('DocumentGUID');
        $result['serial'] = $get('Serial');
        $result['batch'] = $get('Batch');
        $result['response'] = $result['description'];

        // Corpo devuelve ResponseData1/2/3 (no Data1/2/3)
        $result['data1'] = $get('ResponseData1') ?? $get('Data1');
        $result['data2'] = $get('ResponseData2') ?? $get('Data2');
        $result['data3'] = $get('ResponseData3') ?? $get('Data3');

        if ($result['data1']) {
            $json = json_decode($result['data1'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['data1_json'] = $json;
            } elseif ($this->looksLikeBase64($result['data1'])) {
                $decoded = base64_decode($result['data1'], true);
                if ($decoded !== false) {
                    $result['data1_decoded'] = $decoded;
                }
            }
        }

        if ($result['data2'] && $this->looksLikeBase64($result['data2'])) {
            $decoded = base64_decode($result['data2'], true);
            if ($decoded !== false) {
                $result['data2_decoded'] = $decoded;
                $json = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $result['data2_json'] = $json;
                }
            }
        }

        if ($result['document_guid']) {
            $result['data2_json'] = array_merge($result['data2_json'] ?? [], [
                'uuid' => strtoupper($result['document_guid']),
                'serie' => $result['batch'],
                'numero' => $result['serial'],
            ]);
        }

        return $result;
    }

    private function looksLikeBase64(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9+\/=\s]+$/', $value) && strlen($value) > 20;
    }

    private function responseIndicatesError(array $parsed, string $raw): bool
    {
        if ($parsed['result'] === false) {
            return true;
        }
        if ($parsed['result'] === true) {
            return false;
        }

        if (isset($parsed['data2_json']['resultado']) && strtolower((string) $parsed['data2_json']['resultado']) !== 'ok') {
            return true;
        }

        return false;
    }

    private function extractErrorMessage(array $parsed, string $raw): string
    {
        if (!empty($parsed['description'])) {
            return (string) $parsed['description'];
        }
        if (!empty($parsed['data2_json']['descripcion'])) {
            return (string) $parsed['data2_json']['descripcion'];
        }
        if (!empty($parsed['data1']) && !isset($parsed['data1_json'])) {
            return (string) $parsed['data1'];
        }

        return 'Error desconocido del certificador FEL';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
