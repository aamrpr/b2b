<?php

namespace App\Models\Shipping;

use App\Models\Tenancy\Tenant;
use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * شركة شحن. العامة تديرها المنصة (tenant_id = null)،
 * والخاصة يضيفها المستورد لنفسه — السوق المحلي مليء بشركات صغيرة.
 */
class Carrier extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'coverage' => 'array',
            'supports_cod' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(CarrierRate::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function isPlatformWide(): bool
    {
        return $this->tenant_id === null;
    }

    public function trackingUrlFor(?string $number): ?string
    {
        if (! $number || ! $this->tracking_url_template) {
            return null;
        }

        return str_replace('{number}', rawurlencode($number), $this->tracking_url_template);
    }

    public function servesCity(?string $city): bool
    {
        $coverage = $this->coverage ?? [];

        return $coverage === [] || $city === null || in_array($city, $coverage, true);
    }

    /** المتاح لمستأجر: العامة + الخاصة به. */
    public function scopeAvailableTo($query, int $tenantId)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }
}
