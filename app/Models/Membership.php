<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Membership — Modelo central del ciclo de vida de suscripciones.
 *
 * Estados:
 *   active           → Suscripción activa y cobrada por Recurrente
 *   advance_active   → Meses pagados en efectivo/transferencia; Recurrente pausado
 *   advance_expiring → Quedan ≤7 días de adelantos (fase de alerta)
 *   at_risk          → Recurrente falló al reactivarse — admin debe intervenir
 *   paused           → Pausa aprobada (viaje/lesión)
 *   expired          → Venció sin renovar
 *   cancelled        → Cancelada explícitamente
 *
 * @property int         $id
 * @property int         $client_id
 * @property int|null    $plan_id
 * @property string      $status
 * @property string      $payment_type
 * @property string      $payment_status
 * @property Carbon|null $advance_end_date
 * @property bool        $wants_auto_renewal
 * @property Carbon|null $reactivated_at
 * @property string|null $reactivation_error
 * @property Carbon|null $reactivation_error_at
 * @property int         $max_pause_days
 * @property int         $total_paused_days
 */
class Membership extends Model
{
    use SoftDeletes;

    // ── Estados del ciclo de vida ──────────────────────────────────────
    const STATUS_ACTIVE           = 'active';
    const STATUS_ADVANCE_ACTIVE   = 'advance_active';
    const STATUS_ADVANCE_EXPIRING = 'advance_expiring';
    const STATUS_AT_RISK          = 'at_risk';
    const STATUS_PAUSED           = 'paused';
    const STATUS_EXPIRED          = 'expired';
    const STATUS_CANCELLED        = 'cancelled';

    /** Días antes del vencimiento del adelanto en que se emite la alerta */
    const EXPIRING_ALERT_DAYS = 7;

    protected $fillable = [
        'client_id',
        'plan_id',
        'name',
        'description',
        'price',
        'start_date',
        'end_date',
        'status',
        'auto_renew',
        'total_amount',
        'payment_type',
        'num_installments',
        'amount_paid',
        'payment_status',
        // Campos de Recurrente (migration 2026_02_21_000001)
        'recurrente_status',
        'recurrente_rescheduled_at',
        'payment_method_log',
        // Campos de upgrade (migration 2026_02_21_030000)
        'previous_plan_id',
        'credito_restante',
        'upgraded_at',
        // Campos del ciclo de adelantos (migration 2026_02_21_060000)
        'advance_end_date',
        'wants_auto_renewal',
        'reactivated_at',
        'reactivation_error',
        'reactivation_error_at',
        'max_pause_days',
        'total_paused_days',
    ];

    protected $casts = [
        'start_date'             => 'date',
        'end_date'               => 'date',
        'advance_end_date'       => 'date',
        'auto_renew'             => 'boolean',
        'wants_auto_renewal'     => 'boolean',
        'total_amount'           => 'decimal:2',
        'amount_paid'            => 'decimal:2',
        'credito_restante'       => 'decimal:2',
        'num_installments'       => 'integer',
        'max_pause_days'         => 'integer',
        'total_paused_days'      => 'integer',
        'reactivated_at'         => 'datetime',
        'reactivation_error_at'  => 'datetime',
        'upgraded_at'            => 'datetime',
        'recurrente_rescheduled_at' => 'datetime',
        'payment_method_log'     => 'array',
    ];

    // ─────────────────────────────────────────────────────────────────
    //  RELACIONES
    // ─────────────────────────────────────────────────────────────────

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function plan()
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function installments()
    {
        return $this->hasMany(PaymentInstallment::class)->orderBy('installment_number');
    }

    public function recurrenteSubscriptions()
    {
        return $this->hasMany(RecurrenteSubscription::class, 'membership_plan_id', 'plan_id');
    }

    public function pauses()
    {
        return $this->hasMany(MembershipPause::class)->orderByDesc('pause_start');
    }

    public function activePause()
    {
        return $this->hasOne(MembershipPause::class)->where('status', 'active')->latest('pause_start');
    }

    // ─────────────────────────────────────────────────────────────────
    //  SCOPES
    // ─────────────────────────────────────────────────────────────────

    /** Membresías cuyo adelanto vence HOY → candidatas a reactivación */
    public function scopeAdvancedExpiringToday($query)
    {
        return $query->whereIn('status', [self::STATUS_ADVANCE_ACTIVE, self::STATUS_ADVANCE_EXPIRING])
                     ->whereDate('advance_end_date', today())
                     ->where('wants_auto_renewal', true);
    }

    /** Membresías cuyo adelanto vence en exactamente N días → alerta previa */
    public function scopeAdvancedExpiring($query, int $days = 7)
    {
        return $query->where('status', self::STATUS_ADVANCE_ACTIVE)
                     ->whereDate('advance_end_date', today()->addDays($days))
                     ->where('wants_auto_renewal', true);
    }

    /** Membresías en riesgo que necesitan intervención */
    public function scopeAtRisk($query)
    {
        return $query->where('status', self::STATUS_AT_RISK);
    }

    /** Pausas activas que deben terminar HOY o antes */
    public function scopePausesEndingToday($query)
    {
        return $query->where('status', self::STATUS_PAUSED)
                     ->whereDate('advance_end_date', '<=', today());
    }

    // ─────────────────────────────────────────────────────────────────
    //  ACCESSORS
    // ─────────────────────────────────────────────────────────────────

    /** Días restantes de adelanto (positivo = futuro, negativo = vencido) */
    public function getDaysUntilAdvanceEndsAttribute(): ?int
    {
        if (! $this->advance_end_date) {
            return null;
        }
        return today()->diffInDays($this->advance_end_date, false);
    }

    /** Días restantes de pausa aprobada */
    public function getDaysUntilPauseEndsAttribute(): ?int
    {
        $pause = $this->activePause;
        if (! $pause) return null;
        return today()->diffInDays($pause->pause_end, false);
    }

    /** True si puede pausarse sin superar el límite */
    public function canPause(int $days): bool
    {
        return ($this->total_paused_days + $days) <= $this->max_pause_days;
    }

    /** Saldo pendiente */
    public function getBalanceAttribute(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->amount_paid);
    }

    /** True si en algún estado de adelanto (activo, venciendo) */
    public function isInAdvanceMode(): bool
    {
        return in_array($this->status, [
            self::STATUS_ADVANCE_ACTIVE,
            self::STATUS_ADVANCE_EXPIRING,
        ]);
    }

    /** True si acepta cobro automático (no cancelada, no at_risk bloqueada) */
    public function canBeReactivated(): bool
    {
        return ! in_array($this->status, [
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  BUSINESS LOGIC
    // ─────────────────────────────────────────────────────────────────

    /**
     * Recalcular payment_status basado en cuotas.
     * Llamar después de marcar cuotas como pagadas.
     */
    public function recalculatePaymentStatus(): void
    {
        $totalPaid = (float) $this->installments()->sum('amount_paid');
        $this->amount_paid = $totalPaid;

        if ($totalPaid >= (float) $this->total_amount) {
            $this->payment_status = 'paid';
        } elseif ($totalPaid > 0) {
            $hasOverdue = $this->installments()
                ->where('status', '!=', 'paid')
                ->where('due_date', '<', now()->startOfDay())
                ->exists();
            $this->payment_status = $hasOverdue ? 'overdue' : 'partial';
        } else {
            $hasOverdue = $this->installments()
                ->where('due_date', '<', now()->startOfDay())
                ->exists();
            $this->payment_status = $hasOverdue ? 'overdue' : 'pending';
        }

        $this->save();
    }

    /**
     * Actualizar advance_end_date a partir de las cuotas pagadas.
     * Determina hasta qué fecha está cubierto el cliente.
     */
    public function recalculateAdvanceEndDate(): void
    {
        $ultimaCuotaAdelantada = $this->installments()
            ->where('is_advance_payment', true)
            ->where('status', 'paid')
            ->orderByDesc('due_date')
            ->first();

        if ($ultimaCuotaAdelantada) {
            // La cobertura va hasta el último día del mes de esa cuota
            $endDate = $ultimaCuotaAdelantada->due_date->endOfMonth()->toDateString();
            $this->advance_end_date = $endDate;

            // Actualizar status según días restantes
            $daysLeft = today()->diffInDays($ultimaCuotaAdelantada->due_date, false);

            $this->status = match(true) {
                $daysLeft <= 0                         => self::STATUS_ACTIVE,          // Ya venció → Recurrente activo
                $daysLeft <= self::EXPIRING_ALERT_DAYS => self::STATUS_ADVANCE_EXPIRING, // Alerta
                default                                => self::STATUS_ADVANCE_ACTIVE,  // Normal
            };
        } else {
            // Sin cuotas adelantadas → volver a activo normal
            $this->advance_end_date = null;
            if ($this->status === self::STATUS_ADVANCE_ACTIVE || $this->status === self::STATUS_ADVANCE_EXPIRING) {
                $this->status = self::STATUS_ACTIVE;
            }
        }

        $this->save();
    }

    /**
     * Empujar fechas de cuotas futuras hacia adelante (para pausas).
     *
     * @param int $days Días a empujar
     */
    public function pushFutureInstallmentDates(int $days): void
    {
        $this->installments()
            ->where('status', '!=', 'paid')
            ->where('due_date', '>=', today())
            ->orderBy('due_date')
            ->get()
            ->each(function (PaymentInstallment $installment) use ($days) {
                $installment->update([
                    'due_date' => $installment->due_date->addDays($days),
                    'notes'    => ($installment->notes ?? '') .
                                  " [Fecha ajustada +{$days}d por pausa]",
                ]);
            });

        // Actualizar advance_end_date si aplica
        if ($this->advance_end_date) {
            $this->advance_end_date = Carbon::parse($this->advance_end_date)->addDays($days);
            $this->save();
        }
    }
}
