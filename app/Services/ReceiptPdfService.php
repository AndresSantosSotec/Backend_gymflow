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
     * Generar PDF de recibo
     */
    public function generateReceiptPdf(Receipt $receipt): string
    {
        try {
            $receipt->load(['client', 'payment', 'membership', 'venta']);

            $html = $this->getReceiptHtml($receipt);

            $pdf = Pdf::loadHTML($html)
                ->setPaper('a4')
                ->setOption('margin-top', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10)
                ->setOption('margin-right', 10);

            $filename = 'recibos/' . $receipt->receipt_number . '_' . date('Y-m-d') . '.pdf';

            Storage::disk('public')->put($filename, $pdf->output());

            Log::info("Receipt PDF generated: {$filename}");

            return $filename;
        } catch (Exception $e) {
            Log::error("Error generating receipt PDF: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generar PDF de factura electrónica
     */
    public function generateInvoicePdf(Receipt $receipt): string
    {
        try {
            if (!$receipt->is_invoiced) {
                throw new Exception("Receipt must be invoiced first");
            }

            $receipt->load(['client', 'payment', 'membership', 'venta']);

            $html = $this->getInvoiceHtml($receipt);

            $pdf = Pdf::loadHTML($html)
                ->setPaper('a4')
                ->setOption('margin-top', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10)
                ->setOption('margin-right', 10);

            $filename = 'facturas/' . $receipt->invoice_number . '_' . date('Y-m-d') . '.pdf';

            Storage::disk('public')->put($filename, $pdf->output());

            Log::info("Invoice PDF generated: {$filename}");

            return $filename;
        } catch (Exception $e) {
            Log::error("Error generating invoice PDF: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * HTML para recibo
     */
    private function getReceiptHtml(Receipt $receipt): string
    {
        $companyName = config('app.name', 'GymFlow');
        $companyAddress = config('site.company_address', 'Dirección no configurada');
        $companyPhone = config('site.company_phone', 'Teléfono no configurado');
        $companyEmail = config('site.company_email', 'email@gymflow.local');

        return view('pdfs.receipt', [
            'receipt' => $receipt,
            'companyName' => $companyName,
            'companyAddress' => $companyAddress,
            'companyPhone' => $companyPhone,
            'companyEmail' => $companyEmail,
        ])->render();
    }

    /**
     * HTML para factura
     */
    private function getInvoiceHtml(Receipt $receipt): string
    {
        $companyName = config('app.name', 'GymFlow');
        $companyAddress = config('site.company_address', 'Dirección no configurada');
        $companyPhone = config('site.company_phone', 'Teléfono no configurado');
        $companyEmail = config('site.company_email', 'email@gymflow.local');
        $companyTax = config('site.company_tax_id', 'RFC/TAX no configurado');

        return view('pdfs.invoice', [
            'receipt' => $receipt,
            'companyName' => $companyName,
            'companyAddress' => $companyAddress,
            'companyPhone' => $companyPhone,
            'companyEmail' => $companyEmail,
            'companyTax' => $companyTax,
        ])->render();
    }

    /**
     * Descargar recibo como PDF
     */
    public function downloadReceiptPdf(Receipt $receipt)
    {
        try {
            $pdf = $this->generateReceiptPdf($receipt);

            return response()->download(
                storage_path('app/public/' . $pdf),
                $receipt->receipt_number . '.pdf'
            );
        } catch (Exception $e) {
            Log::error("Download error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Descargar factura como PDF
     */
    public function downloadInvoicePdf(Receipt $receipt)
    {
        try {
            if (!$receipt->is_invoiced) {
                throw new Exception("Receipt is not invoiced");
            }

            $pdf = $this->generateInvoicePdf($receipt);

            return response()->download(
                storage_path('app/public/' . $pdf),
                $receipt->invoice_number . '.pdf'
            );
        } catch (Exception $e) {
            Log::error("Download error: " . $e->getMessage());
            throw $e;
        }
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
            $pdf = $this->generateReceiptPdf($receipt);

            // TODO: Implementar envío de email con PDF adjunto
            // Mail::send(new ReceiptPdfMail($receipt, $pdf, $email, $message));

            Log::info("Receipt emailed to {$email}: {$receipt->receipt_number}");

            return [
                'success' => true,
                'email' => $email,
                'file' => $pdf,
            ];
        } catch (Exception $e) {
            Log::error("Error emailing receipt: " . $e->getMessage());
            throw $e;
        }
    }
}
