<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Receipt;
use App\Services\CorpoFel\CorpoFelClient;
use App\Services\CorpoFel\FelDteBuilder;
use Illuminate\Support\Facades\Log;

class FelPaymentService
{
    public function __construct(
        private ElectronicBillingService $billingService,
        private CorpoFelClient $corpoFelClient,
        private FelDteBuilder $dteBuilder,
    ) {}

    /**
     * Decide si se debe certificar FEL según método de pago y preferencia explícita.
     * Efectivo: por defecto NO certifica. Otros métodos: por defecto SÍ (si FEL está habilitado).
     */
    public function shouldIssueFel(string $paymentMethod, ?bool $issueFel): bool
    {
        if (!config('billing.corpo_fel.enabled', false)) {
            return false;
        }

        if ($issueFel !== null) {
            return $issueFel;
        }

        return match ($paymentMethod) {
            'cash' => (bool) config('billing.corpo_fel.auto_certify_cash', false),
            default => (bool) config('billing.corpo_fel.auto_certify_non_cash', true),
        };
    }

    /**
     * Procesa FEL después de registrar un pago completado.
     */
    public function processAfterPayment(Payment $payment, ?bool $issueFel = null): array
    {
        if ($payment->status !== 'completed') {
            return ['skipped' => true, 'fel_status' => 'skipped', 'reason' => 'payment_not_completed'];
        }

        if (!$this->shouldIssueFel($payment->payment_method, $issueFel)) {
            $this->markFelSkipped($payment, 'user_or_policy');
            return [
                'skipped' => true,
                'fel_status' => 'skipped',
                'reason' => 'cash_or_disabled',
            ];
        }

        $receipt = Receipt::where('payment_id', $payment->id)->first();
        if (!$receipt) {
            $receipt = Receipt::createFromPaymentAuto($payment, 'individual_payment');
        }

        if (!$receipt->is_invoiced) {
            $receipt->markAsInvoiced();
        }

        $this->dteBuilder->applyIvaToReceipt($receipt);

        $result = $this->billingService->generateElectronicInvoice($receipt->fresh());

        return array_merge($result, [
            'receipt_id' => $receipt->id,
            'fel_status' => ($result['success'] ?? false) ? 'certified' : 'failed',
        ]);
    }

    /**
     * Certificar manualmente un recibo existente.
     */
    public function certifyReceipt(Receipt $receipt): array
    {
        if (!$receipt->is_invoiced) {
            $receipt->markAsInvoiced();
        }

        $this->dteBuilder->applyIvaToReceipt($receipt);

        return $this->billingService->generateElectronicInvoice($receipt->fresh());
    }

    /**
     * Resolver datos del receptor consultando NIT o CUI en Corpo Sistemas.
     */
    public function resolveReceptor($client = null): array
    {
        if (!$client) {
            return [
                'id' => 'CF',
                'name' => 'CONSUMIDOR FINAL',
                'address' => 'GUATEMALA',
                'zip' => '01001',
                'municipality' => 'GUATEMALA',
                'department' => 'GUATEMALA',
            ];
        }

        $nit = '';
        $cui = '';
        $name = 'CONSUMIDOR FINAL';
        $address = 'GUATEMALA';

        if ($client instanceof \App\Models\Client) {
            $nit = preg_replace('/\D/', '', (string) ($client->nit ?? ''));
            $cui = preg_replace('/\D/', '', (string) ($client->dni ?? ''));
            $name = $client->company_name ?? $client->full_name ?? 'CLIENTE';
            $address = $client->fiscal_address ?? $client->address ?? 'GUATEMALA';
        } elseif ($client instanceof \App\Models\ClienteVenta) {
            $nit = preg_replace('/\D/', '', (string) ($client->nit ?? ''));
            $name = $client->nombre ?? 'CLIENTE';
            $address = $client->ciudad ?? 'GUATEMALA';
        }

        if (strlen($nit) >= 2 && strtoupper($nit) !== 'CF') {
            $lookup = $this->corpoFelClient->consultNit($nit);
            if ($lookup['success'] ?? false) {
                $data = $lookup['data'] ?? [];
                $satName = trim((string) ($data['messageContent'] ?? ''));
                if ($satName === '') {
                    $satName = $name;
                }

                return [
                    'id' => $nit,
                    'name' => $satName,
                    'address' => $address,
                    'zip' => '01001',
                    'municipality' => 'GUATEMALA',
                    'department' => 'GUATEMALA',
                    'lookup' => $lookup,
                ];
            }

            // El cliente tiene NIT en BD pero SAT lo rechaza — no facturar con CF ni CUI por error.
            throw new \InvalidArgumentException(
                'NIT del cliente inválido según SAT (' . $nit . '): '
                . ($lookup['error'] ?? $lookup['data']['message'] ?? 'verifique el NIT en el perfil del cliente')
            );
        }

        if (strlen($cui) === 13) {
            $lookup = $this->corpoFelClient->consultCui($cui);
            if ($lookup['success'] ?? false) {
                $json = $lookup['parsed']['data2_json'] ?? [];
                $satName = $json['nombre'] ?? $name;
                return [
                    'id' => $cui,
                    'name' => $satName,
                    'address' => $address,
                    'zip' => '01001',
                    'municipality' => 'GUATEMALA',
                    'department' => 'GUATEMALA',
                    'lookup' => $lookup,
                ];
            }
        }

        return [
            'id' => 'CF',
            'name' => $name,
            'address' => $address,
            'zip' => '01001',
            'municipality' => 'GUATEMALA',
            'department' => 'GUATEMALA',
        ];
    }

    private function markFelSkipped(Payment $payment, string $reason): void
    {
        $receipt = Receipt::where('payment_id', $payment->id)->first();
        if (!$receipt) {
            return;
        }

        $receipt->update([
            'details' => array_merge($receipt->details ?? [], [
                'electronic_billing' => [
                    'success' => true,
                    'provider' => 'corpo_fel',
                    'fel_status' => 'skipped',
                    'reason' => $reason,
                    'skipped_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    }
}
