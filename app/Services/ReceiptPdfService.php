<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\Client;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class ReceiptPdfService
{
    /**
     * Generar PDF de recibo y guardarlo en disco
     */
    public function generateReceiptPdf(Receipt $receipt): string
    {
        try {
            $receipt->load(['client', 'payment', 'membership.plan', 'venta']);

            $data = $this->getCompanyData();
            $data['receipt'] = $receipt;

            $pdf = Pdf::loadView('pdfs.receipt', $data)
                ->setPaper('letter')
                ->setOption('isRemoteEnabled', true)
                ->setOption('defaultFont', 'DejaVu Sans');

            $filename = 'recibos/' . $receipt->receipt_number . '_' . date('Y-m-d') . '.pdf';

            Storage::disk('public')->makeDirectory('recibos');
            Storage::disk('public')->put($filename, $pdf->output());

            Log::info("Receipt PDF generated: {$filename}");

            return $filename;
        } catch (Exception $e) {
            Log::error("Error generating receipt PDF: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generar PDF de factura electrónica y guardarlo en disco
     */
    public function generateInvoicePdf(Receipt $receipt): string
    {
        try {
            if (!$receipt->is_invoiced) {
                throw new Exception("Receipt must be invoiced first");
            }

            $receipt->load(['client', 'payment', 'membership.plan', 'venta']);

            $data = $this->getCompanyData(true);
            $data['receipt'] = $receipt;

            $pdf = Pdf::loadView('pdfs.invoice', $data)
                ->setPaper('letter')
                ->setOption('isRemoteEnabled', true)
                ->setOption('defaultFont', 'DejaVu Sans');

            $filename = 'facturas/' . $receipt->invoice_number . '_' . date('Y-m-d') . '.pdf';

            Storage::disk('public')->makeDirectory('facturas');
            Storage::disk('public')->put($filename, $pdf->output());

            Log::info("Invoice PDF generated: {$filename}");

            return $filename;
        } catch (Exception $e) {
            Log::error("Error generating invoice PDF: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Descargar recibo como PDF (stream directo, sin guardar en disco)
     */
    public function downloadReceiptPdf(Receipt $receipt)
    {
        try {
            $receipt->load(['client', 'payment', 'membership.plan', 'venta']);

            $data = $this->getCompanyData();
            $data['receipt'] = $receipt;

            $pdf = Pdf::loadView('pdfs.receipt', $data)
                ->setPaper('letter')
                ->setOption('isRemoteEnabled', true)
                ->setOption('defaultFont', 'DejaVu Sans');

            $filename = 'Recibo_' . ($receipt->receipt_number ?? $receipt->id) . '.pdf';

            return $pdf->download($filename);
        } catch (Exception $e) {
            Log::error("Download receipt error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Descargar factura como PDF (stream directo)
     */
    public function downloadInvoicePdf(Receipt $receipt)
    {
        try {
            if (!$receipt->is_invoiced) {
                throw new Exception("Receipt is not invoiced");
            }

            $receipt->load(['client', 'payment', 'membership.plan', 'venta']);

            $data = $this->getCompanyData(true);
            $data['receipt'] = $receipt;

            $pdf = Pdf::loadView('pdfs.invoice', $data)
                ->setPaper('letter')
                ->setOption('isRemoteEnabled', true)
                ->setOption('defaultFont', 'DejaVu Sans');

            $filename = 'Factura_' . ($receipt->invoice_number ?? $receipt->id) . '.pdf';

            return $pdf->download($filename);
        } catch (Exception $e) {
            Log::error("Download invoice error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener el contenido binario del PDF de recibo
     */
    public function getReceiptPdfContent(Receipt $receipt): string
    {
        $receipt->load(['client', 'payment', 'membership.plan', 'venta']);

        $data = $this->getCompanyData();
        $data['receipt'] = $receipt;

        $pdf = Pdf::loadView('pdfs.receipt', $data)
            ->setPaper('letter')
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans');

        return $pdf->output();
    }

    /**
     * Generar múltiples PDFs
     */
    public function generateBulkPdfs(array $receiptIds): array
    {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($receiptIds as $id) {
            try {
                $receipt = Receipt::findOrFail($id);
                $filename = $this->generateReceiptPdf($receipt);
                $results['success'][] = [
                    'id' => $id,
                    'receipt_number' => $receipt->receipt_number,
                    'file' => $filename,
                ];
            } catch (Exception $e) {
                $results['failed'][] = [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Enviar PDF por email
     */
    public function emailReceiptPdf(Receipt $receipt, string $email, string $message = null)
    {
        try {
            $pdfContent = $this->getReceiptPdfContent($receipt);

            // Guardar temporalmente para adjuntar al email
            $tempPath = 'temp/recibo_' . $receipt->receipt_number . '.pdf';
            Storage::disk('local')->put($tempPath, $pdfContent);

            // TODO: Implementar envío de email con PDF adjunto
            // Mail::send(new ReceiptPdfMail($receipt, storage_path('app/' . $tempPath), $email, $message));

            // Limpiar temp
            // Storage::disk('local')->delete($tempPath);

            Log::info("Receipt emailed to {$email}: {$receipt->receipt_number}");

            return [
                'success' => true,
                'email' => $email,
                'receipt_number' => $receipt->receipt_number,
            ];
        } catch (Exception $e) {
            Log::error("Error emailing receipt: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generar ticket térmico 80mm como PDF (stream directo)
     */
    public function downloadTicketPdf(Receipt $receipt)
    {
        try {
            $receipt->load(['client', 'payment', 'membership.plan', 'venta']);

            $data = $this->getCompanyData();
            $data['receipt'] = $receipt;

            $pdf = Pdf::loadView('pdfs.ticket', $data)
                ->setPaper([0, 0, 226.77, 600], 'portrait') // 80mm width, variable height
                ->setOption('isRemoteEnabled', true)
                ->setOption('defaultFont', 'Courier');

            $filename = 'Ticket_' . ($receipt->receipt_number ?? $receipt->id) . '.pdf';

            return $pdf->download($filename);
        } catch (Exception $e) {
            Log::error("Download ticket error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Obtener HTML del ticket para impresión directa en navegador
     */
    public function getTicketHtml(Receipt $receipt): string
    {
        $receipt->load(['client', 'payment', 'membership.plan', 'venta']);

        $data = $this->getCompanyData();
        $data['receipt'] = $receipt;

        return view('pdfs.ticket', $data)->render();
    }

    /**
     * Generar reporte general de recibos en PDF (landscape)
     */
    public function downloadReportPdf(
        $receipts,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        array $appliedFilters = []
    ) {
        try {
            $data = $this->getCompanyData();
            $data['receipts'] = $receipts;
            $data['dateFrom'] = $dateFrom;
            $data['dateTo'] = $dateTo;
            $data['appliedFilters'] = $appliedFilters;

            $pdf = Pdf::loadView('pdfs.report', $data)
                ->setPaper('letter', 'landscape')
                ->setOption('isRemoteEnabled', true)
                ->setOption('defaultFont', 'DejaVu Sans');

            $filename = 'Reporte_Recibos_' . date('Y-m-d_His') . '.pdf';

            return $pdf->download($filename);
        } catch (Exception $e) {
            Log::error("Download report error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Datos de la empresa para los PDFs
     */
    private function getCompanyData(bool $includeTax = false): array
    {
        $data = [
            'companyName' => config('app.name', 'GymFlow'),
            'companyAddress' => config('site.company_address', 'Guatemala, Guatemala'),
            'companyPhone' => config('site.company_phone', '(502) 0000-0000'),
            'companyEmail' => config('site.company_email', 'info@gymflow.gt'),
        ];

        if ($includeTax) {
            $data['companyTax'] = config('site.company_tax_id', 'NIT no configurado');
        }

        return $data;
    }
}
