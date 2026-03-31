<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_secondary',
        'dni',
        'nit',
        'company_name',
        'fiscal_address',
        'birth_date',
        'gender',
        'address',
        'photo_url',
        'qr_code',
        'fingerprint_id',
        'fingerprint_template',
        'fingerprint_device_id',
        'fingerprint_quality',
        'fingerprint_registered_at',
        'fingerprint_enrollment_version',
        'fingerprint_sample_count',
        'fingerprint_legacy_enrollment',
        'status',
        'notes',
        'emergency_contact_name',
        'emergency_contact_phone',
        'weight_kg',
        'height_cm',
        'medical_conditions',
        'referral_source',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'fingerprint_registered_at' => 'datetime',
        'weight_kg' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'fingerprint_quality' => 'integer',
        'fingerprint_enrollment_version' => 'integer',
        'fingerprint_sample_count' => 'integer',
        'fingerprint_legacy_enrollment' => 'boolean',
    ];

    protected $appends = [
        'full_name',
        'age',
        'has_fingerprint',
        'has_active_membership',
        'membership_end_date',
        'photo_public_path',
    ];

    // ─── Computed Attributes ───

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getAgeAttribute(): ?int
    {
        if (!$this->birth_date) return null;
        return $this->birth_date->age;
    }

    public function getHasFingerprintAttribute(): bool
    {
        return !empty($this->fingerprint_id);
    }

    public function getHasActiveMembershipAttribute(): bool
    {
        return $this->activeMembership() !== null;
    }

    public function getMembershipEndDateAttribute(): ?string
    {
        $membership = $this->activeMembership();
        return $membership ? $membership->end_date->toDateString() : null;
    }

    /**
     * Ruta pública de la foto sin host: /storage/clients/photos/xxx.jpg
     * El frontend construye la URL con window.location.origin para mismo origen (sin Mixed Content).
     */
    public function getPhotoPublicPathAttribute(): ?string
    {
        if (empty($this->photo_url)) {
            return null;
        }
        return '/storage/' . ltrim($this->photo_url, '/');
    }

    // ─── Relationships ───

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function accessLogs()
    {
        return $this->hasMany(AccessLog::class);
    }

    public function installments()
    {
        return $this->hasMany(PaymentInstallment::class)->orderBy('due_date');
    }

    // ─── Helpers ───

    /**
     * Get the currently active membership (most recent active one)
     */
    public function activeMembership(): ?Membership
    {
        return $this->memberships()
            ->where('status', 'active')
            ->where('end_date', '>=', Carbon::today())
            ->orderBy('end_date', 'desc')
            ->first();
    }

    /**
     * Check if client has a valid, non-expired membership
     */
    public function hasValidMembership(): bool
    {
        return $this->activeMembership() !== null;
    }

    /**
     * Determine real-time status based on membership validity
     */
    public function resolveStatus(): string
    {
        if ($this->status === 'suspended') {
            return 'suspended';
        }
        return $this->hasValidMembership() ? 'active' : 'inactive';
    }

    // ─── Fingerprint Methods (Preparados para integración futura) ───

    /**
     * Register a fingerprint for this client.
     * In future: $template would come from the biometric SDK.
     */
    public function registerFingerprint(
        string $fingerprintId,
        ?string $template = null,
        ?string $deviceId = null,
        ?int $quality = null,
        int $enrollmentVersion = 2,
        ?int $sampleCount = null,
        bool $legacyEnrollment = false,
    ): self {
        $this->update([
            'fingerprint_id' => $fingerprintId,
            'fingerprint_template' => $template,
            'fingerprint_device_id' => $deviceId,
            'fingerprint_quality' => $quality,
            'fingerprint_registered_at' => now(),
            'fingerprint_enrollment_version' => $enrollmentVersion,
            'fingerprint_sample_count' => $sampleCount,
            'fingerprint_legacy_enrollment' => $legacyEnrollment,
        ]);

        return $this;
    }

    /**
     * Remove fingerprint data from this client.
     */
    public function removeFingerprint(): self
    {
        $this->update([
            'fingerprint_id' => null,
            'fingerprint_template' => null,
            'fingerprint_device_id' => null,
            'fingerprint_quality' => null,
            'fingerprint_registered_at' => null,
            'fingerprint_enrollment_version' => 1,
            'fingerprint_sample_count' => null,
            'fingerprint_legacy_enrollment' => false,
        ]);

        return $this;
    }

    /**
     * Verify a fingerprint ID matches this client.
     * In future: would compare biometric templates.
     */
    public function verifyFingerprint(string $fingerprintId): bool
    {
        return $this->fingerprint_id === $fingerprintId;
    }

    // ─── Scopes ───

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    public function scopeWithActiveMemership($query)
    {
        return $query->whereHas('memberships', function ($q) {
            $q->where('status', 'active')
              ->where('end_date', '>=', Carbon::today());
        });
    }

    public function scopeWithExpiredMembership($query)
    {
        return $query->whereDoesntHave('memberships', function ($q) {
            $q->where('status', 'active')
              ->where('end_date', '>=', Carbon::today());
        });
    }

    public function scopeWithFingerprint($query)
    {
        return $query->whereNotNull('fingerprint_id');
    }

    public function scopeWithoutFingerprint($query)
    {
        return $query->whereNull('fingerprint_id');
    }
}
