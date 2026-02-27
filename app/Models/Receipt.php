<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Receipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'payment_id',
        'venta_id',
        'membership_id',
        'receipt_number',
        'type',
        'payment_type',
        'subtotal',
        'tax',
        'discount',
        'total',
        'is_invoiced',
        'invoiced_at',
        'invoice_number',
        'invoice_notes',
        'email_sent',
        'email_sent_at',
        'sent_to_email',
        'description',
        'details',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'is_invoiced' => 'boolean',
        'invoiced_at' => 'datetime',
        'email_sent' => 'boolean',
        'email_sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'details' => 'array',
    ];

    /**
     * Relaciones
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function membership()
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * Generar número de recibo automático (único, incluye borrados y evita carreras).
     */
    public static function generateReceiptNumber()
    {
        $prefix = 'REC-' . date('Y') . '-';

        return \Illuminate\Support\Facades\DB::transaction(function () use ($prefix) {
            // Incluir soft-deleted para no reutilizar números; lock para evitar duplicados por concurrencia
            $lastReceipt = self::withTrashed()
                ->where('receipt_number', 'like', $prefix . '%')
                ->orderByRaw('CAST(SUBSTRING(receipt_number, LOCATE(\'-\', receipt_number, 5) + 1) AS UNSIGNED) DESC')
                ->lockForUpdate()
                ->first();

            if ($lastReceipt && preg_match('/-(\d+)$/', $lastReceipt->receipt_number, $m)) {
                $newNumber = str_pad((int) $m[1] + 1, 6, '0', STR_PAD_LEFT);
            } else {
                $newNumber = '000001';
            }

            return $prefix . $newNumber;
        });
    }

    /**
     * Generar número de factura automático
     */
    public static function generateInvoiceNumber()
    {
        $prefix = 'INV-' . date('Y');
        $lastInvoice = self::where('invoice_number', 'like', $prefix . '%')
            ->whereNotNull('invoice_number')
            ->orderBy('id', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -6);
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '000001';
        }

        return $prefix . '-' . $newNumber;
    }

    /**
     * Marcar como facturado
     */
    public function markAsInvoiced($notes = null)
    {
        $this->update([
            'is_invoiced' => true,
            'invoiced_at' => now(),
            'invoice_number' => self::generateInvoiceNumber(),
            'invoice_notes' => $notes,
        ]);

        return $this;
    }

    /**
     * Marcar como pagado
     */
    public function markAsPaid()
    {
        $this->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $this;
    }

    /**
     * Marcar como enviado por email
     */
    public function markEmailSent($email)
    {
        $this->update([
            'email_sent' => true,
            'email_sent_at' => now(),
            'sent_to_email' => $email,
        ]);

        return $this;
    }

    /**
     * Scope para recibos activos
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'paid']);
    }

    /**
     * Scope para recibos pendientes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope para recibos pagados
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Scope para recibos sin facturar
     */
    public function scopeNotInvoiced($query)
    {
        return $query->where('is_invoiced', false);
    }

    /**
     * Scope para recibos facturados
     */
    public function scopeInvoiced($query)
    {
        return $query->where('is_invoiced', true);
    }

    /**
     * Auto-create a receipt from a Payment.
     * Call this every time a payment is recorded so we always have a receipt.
     */
    public static function createFromPaymentAuto(Payment $payment, string $paymentType = 'individual_payment', ?int $membershipId = null): self
    {
        // Avoid duplicate receipts for the same payment
        $existing = self::where('payment_id', $payment->id)->first();
        if ($existing) {
            return $existing;
        }

        return self::create([
            'client_id'      => $payment->client_id,
            'payment_id'     => $payment->id,
            'membership_id'  => $membershipId ?? $payment->membership_id,
            'receipt_number'  => self::generateReceiptNumber(),
            'type'           => 'receipt',
            'payment_type'   => $paymentType,
            'subtotal'       => $payment->amount,
            'tax'            => 0,
            'discount'       => 0,
            'total'          => $payment->amount,
            'status'         => $payment->status === 'completed' ? 'paid' : 'pending',
            'paid_at'        => $payment->paid_at,
            'description'    => $payment->notes,
        ]);
    }

    /**
     * Auto-create a receipt from a Venta (Sale).
     * Call this every time a sale is completed so we always have a receipt.
     */
    public static function createFromVentaAuto(Venta $venta): self
    {
        // Avoid duplicate receipts for the same sale
        $existing = self::where('venta_id', $venta->id)->first();
        if ($existing) {
            return $existing;
        }

        return self::create([
            'client_id'      => $venta->cliente?->id ?? null,
            'venta_id'       => $venta->id,
            'receipt_number' => self::generateReceiptNumber(),
            'type'           => 'receipt',
            'payment_type'   => 'product',
            'subtotal'       => $venta->total,
            'tax'            => 0,
            'discount'       => 0,
            'total'          => $venta->total,
            'status'         => $venta->estado === 'PAGADA' ? 'paid' : 'pending',
            'paid_at'        => $venta->estado === 'PAGADA' ? now() : null,
            'description'    => 'Venta de Productos/POS',
        ]);
    }
}
