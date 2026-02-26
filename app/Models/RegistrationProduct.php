<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RegistrationProduct
 *
 * Producto de inscripción/matrícula (pago único via Recurrente)
 * Usado para cobrar fees de inscripción, matrículas, evaluaciones físicas, etc.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property float $price
 * @property string|null $image_url
 * @property bool $published
 * @property int|null $max_uses
 * @property int $uses_count
 * @property string|null $success_url
 * @property string|null $cancel_url
 * @property string $phone_requirement
 * @property string $address_requirement
 * @property string $billing_info_requirement
 * @property string|null $recurrente_product_id
 * @property string|null $recurrente_price_id
 * @property array|null $metadata
 */
class RegistrationProduct extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'image_url',
        'published',
        'max_uses',
        'uses_count',
        'success_url',
        'cancel_url',
        'phone_requirement',
        'address_requirement',
        'billing_info_requirement',
        'recurrente_product_id',
        'recurrente_price_id',
        'metadata',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'published' => 'boolean',
        'max_uses' => 'integer',
        'uses_count' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Scope para productos publicados
     */
    public function scopePublished($query)
    {
        return $query->where('published', true);
    }

    /**
     * Scope para productos disponibles (publicados y no agotados)
     */
    public function scopeAvailable($query)
    {
        return $query->where('published', true)
            ->where(function ($q) {
                $q->whereNull('max_uses')
                  ->orWhereRaw('uses_count < max_uses');
            });
    }

    /**
     * Verifica si el producto está disponible para uso
     */
    public function isAvailable(): bool
    {
        if (!$this->published) {
            return false;
        }

        if ($this->max_uses !== null && $this->uses_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Incrementa el contador de usos
     */
    public function incrementUses(): void
    {
        $this->increment('uses_count');
    }

    /**
     * Verifica si está sincronizado con Recurrente
     */
    public function isSyncedWithRecurrente(): bool
    {
        return !empty($this->recurrente_product_id) && !empty($this->recurrente_price_id);
    }
}
