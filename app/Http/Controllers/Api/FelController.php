<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\CorpoFel\CorpoFelClient;
use App\Services\ElectronicBillingService;
use App\Services\FelPaymentService;
use Illuminate\Http\Request;

class FelController extends Controller
{
    public function status()
    {
        $cfg = config('billing.corpo_fel');

        return response()->json([
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'provider' => config('billing.provider'),
            'use_test' => (bool) ($cfg['use_test'] ?? true),
            'entity_nit' => $cfg['entity_nit'] ?? null,
            'auto_certify_cash' => (bool) ($cfg['auto_certify_cash'] ?? false),
            'auto_certify_non_cash' => (bool) ($cfg['auto_certify_non_cash'] ?? true),
        ]);
    }

    public function consultNit(Request $request, CorpoFelClient $client)
    {
        $validated = $request->validate([
            'nit' => 'required|string|min:2|max:20',
        ]);

        return response()->json($client->consultNit($validated['nit']));
    }

    public function consultCui(Request $request, CorpoFelClient $client)
    {
        $validated = $request->validate([
            'cui' => 'required|string|min:8|max:20',
        ]);

        return response()->json($client->consultCui($validated['cui']));
    }

    public function certifyReceipt(Request $request, string $id, FelPaymentService $felService)
    {
        $receipt = Receipt::with(['client', 'payment'])->findOrFail($id);

        if (($receipt->details['electronic_billing']['fel_status'] ?? null) === 'certified') {
            return response()->json([
                'message' => 'Este recibo ya fue certificado en FEL',
                'electronic_billing' => $receipt->details['electronic_billing'] ?? null,
            ], 422);
        }

        $result = $felService->certifyReceipt($receipt);

        if (!($result['success'] ?? false)) {
            return response()->json([
                'message' => 'No se pudo certificar la factura electrónica',
                'error' => $result['error'] ?? 'Error desconocido',
                'fel' => $result,
            ], 422);
        }

        return response()->json([
            'message' => 'Factura electrónica certificada correctamente',
            'fel' => $result,
            'receipt' => $receipt->fresh(),
        ]);
    }

    public function downloadFelPdf(string $id, CorpoFelClient $client)
    {
        $receipt = Receipt::findOrFail($id);
        $guid = $this->resolveFelGuid($receipt);

        if (!$guid) {
            return response()->json(['message' => 'Este recibo no tiene UUID FEL'], 422);
        }

        $result = $client->getDocumentPdf($guid);
        if (!($result['success'] ?? false)) {
            return response()->json([
                'message' => 'No se pudo obtener el PDF del certificador',
                'error' => $result['error'] ?? null,
            ], 422);
        }

        $pdf = $client->extractPdfContent($result['parsed'] ?? []);
        if ($pdf === null) {
            return response()->json(['message' => 'PDF no disponible en la respuesta del certificador'], 422);
        }

        return $this->felFileDownload($pdf, 'fel-' . $guid . '.pdf', 'application/pdf');
    }

    public function downloadFelXml(string $id, CorpoFelClient $client)
    {
        $receipt = Receipt::findOrFail($id);
        $guid = $this->resolveFelGuid($receipt);

        if (!$guid) {
            return response()->json(['message' => 'Este recibo no tiene UUID FEL'], 422);
        }

        $result = $client->getDocumentPdf($guid);
        if (!($result['success'] ?? false)) {
            $result = $client->getDocumentXml($guid);
        }

        if (!($result['success'] ?? false)) {
            return response()->json([
                'message' => 'No se pudo obtener el XML del certificador',
                'error' => $result['error'] ?? null,
            ], 422);
        }

        $xml = $client->extractXmlContent($result['parsed'] ?? []);
        if ($xml === null) {
            return response()->json(['message' => 'XML no disponible en la respuesta del certificador'], 422);
        }

        return $this->felFileDownload($xml, 'fel-' . $guid . '.xml', 'application/xml; charset=UTF-8');
    }

    private function resolveFelGuid(Receipt $receipt): ?string
    {
        $billing = $receipt->details['electronic_billing'] ?? null;
        $guid = $billing['uuid'] ?? null;

        return $guid ? strtoupper((string) $guid) : null;
    }

    private function felFileDownload(string $content, string $filename, string $mime)
    {
        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'fel-document';

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function voidReceipt(string $id, ElectronicBillingService $billingService)
    {
        $receipt = Receipt::with('client')->findOrFail($id);

        if (!$receipt->is_invoiced) {
            return response()->json(['message' => 'El recibo no está facturado'], 422);
        }

        $result = $billingService->cancelInvoice($receipt);

        if (!($result['success'] ?? false)) {
            $trCode = $result['tr_code'] ?? null;
            $expected = (bool) ($result['expected_in_pruebas'] ?? false);

            return response()->json([
                'message' => $expected
                    ? 'Anulación rechazada por SAT en ambiente PRUEBAS (comportamiento esperado)'
                    : 'No se pudo anular el documento FEL',
                'error' => $result['error'] ?? $result['message'] ?? null,
                'tr_code' => $trCode,
                'expected_in_pruebas' => $expected,
                'result' => $result,
            ], $expected ? 200 : 422);
        }

        return response()->json([
            'message' => 'Documento FEL anulado',
            'result' => $result,
            'receipt' => $receipt->fresh(),
        ]);
    }

    public function phrases(Request $request, CorpoFelClient $client)
    {
        $validated = $request->validate([
            'nit' => 'required|string',
        ]);
        return response()->json($client->getPhrases($validated['nit']));
    }

    public function establishments(Request $request, CorpoFelClient $client)
    {
        $validated = $request->validate([
            'nit' => 'required|string',
            'establishment_id' => 'nullable|string',
        ]);
        return response()->json($client->getEstablishments($validated['nit'], $validated['establishment_id'] ?? '0'));
    }

    public function queryGuid(Request $request, CorpoFelClient $client)
    {
        $validated = $request->validate([
            'guid' => 'required|string',
        ]);
        return response()->json($client->getDocumentInfo(strtoupper($validated['guid'])));
    }

    public function queryDateRange(Request $request, CorpoFelClient $client)
    {
        $validated = $request->validate([
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d',
            'doc_type' => 'nullable|string',
            'buyer_nit' => 'nullable|string',
            'uuid' => 'nullable|string',
            'ref' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        return response()->json($client->queryDateRange(
            $validated['date_from'],
            $validated['date_to'],
            $validated['doc_type'] ?? '',
            $validated['buyer_nit'] ?? '',
            $validated['uuid'] ?? '',
            $validated['ref'] ?? '',
            $validated['status'] ?? ''
        ));
    }

    public function getPdfByGuid(Request $request, CorpoFelClient $client)
    {
        $validated = $request->validate([
            'guid' => 'required|string',
        ]);
        return response()->json($client->getDocumentPdf(strtoupper($validated['guid'])));
    }

    public function voidByGuid(Request $request, CorpoFelClient $client)
    {
        $validated = $request->validate([
            'guid' => 'required|string',
            'receptor_id' => 'required|string',
            'fecha_emision' => 'required|string',
            'motivo' => 'required|string|max:100',
        ]);

        $cfg = config('billing.corpo_fel');
        $emisorNit = preg_replace('/\D/', '', (string) ($cfg['entity_nit'] ?? ''));
        $fechaAnulacion = now('America/Guatemala')->format('Y-m-d\TH:i:s.vP');

        $voidXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<dte:GTAnulacionDocumento xmlns:dte="http://www.sat.gob.gt/dte/fel/0.1.0" Version="0.1">'
            . '<dte:SAT><dte:AnulacionDTE ID="DatosCertificados">'
            . '<dte:DatosGenerales ID="DatosAnulacion"'
            . ' FechaEmisionDocumentoAnular="' . $validated['fecha_emision'] . '"'
            . ' FechaHoraAnulacion="' . $fechaAnulacion . '"'
            . ' IDReceptor="' . htmlspecialchars($validated['receptor_id'], ENT_XML1) . '"'
            . ' MotivoAnulacion="' . htmlspecialchars($validated['motivo'], ENT_XML1) . '"'
            . ' NITEmisor="' . htmlspecialchars($emisorNit, ENT_XML1) . '"'
            . ' NumeroDocumentoAAnular="' . htmlspecialchars(strtoupper($validated['guid']), ENT_XML1) . '"/>'
            . '</dte:AnulacionDTE></dte:SAT></dte:GTAnulacionDocumento>';

        $response = $client->voidDocument($voidXml);
        $response['void_xml'] = $voidXml;

        return response()->json($response);
    }

    public function certifyRawXml(Request $request, CorpoFelClient $client)
    {
        $validated = $request->validate([
            'xml' => 'required|string',
        ]);
        return response()->json($client->certifyDocument($validated['xml']));
    }

    public function getSamples()
    {
        $path = 'd:\\Gymflow\\Documentacion Corpo\\docs\\sat\\xmls';
        if (!is_dir($path)) {
            $path = base_path('../Documentacion Corpo/docs/sat/xmls');
        }

        if (!is_dir($path)) {
            return response()->json(['success' => false, 'error' => 'Directorio de ejemplos no encontrado'], 404);
        }

        $categories = ['facturas', 'notas_de_credito_y_debito', 'operaciones', 'varios'];
        $samples = [];

        foreach ($categories as $cat) {
            $catPath = $path . DIRECTORY_SEPARATOR . $cat;
            if (is_dir($catPath)) {
                $files = scandir($catPath);
                foreach ($files as $file) {
                    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'xml') {
                        $samples[] = [
                            'category' => $cat,
                            'name' => $file,
                            'path' => $cat . '/' . $file,
                        ];
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'samples' => $samples,
        ]);
    }

    public function getSampleContent(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string',
            'filename' => 'required|string',
        ]);

        $path = 'd:\\Gymflow\\Documentacion Corpo\\docs\\sat\\xmls';
        if (!is_dir($path)) {
            $path = base_path('../Documentacion Corpo/docs/sat/xmls');
        }

        $category = basename($validated['category']);
        $filename = basename($validated['filename']);

        $filePath = $path . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($filePath)) {
            return response()->json(['success' => false, 'error' => 'Archivo no encontrado: ' . $filePath], 404);
        }

        $content = file_get_contents($filePath);

        return response()->json([
            'success' => true,
            'category' => $category,
            'filename' => $filename,
            'content' => $content,
        ]);
    }
}
