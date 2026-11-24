<?php

namespace App\Models\Shipping;

use App\Models\Accounting\JournalEntry;
use App\Models\Orders\Order;
use App\Models\User;
use App\Support\Concerns\BelongsToTenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use BelongsToTenant;

    /** تسلسل الحالات — أي قفزة للخلف مرفوضة */
    public const FLOW = [
        'draft' => ['ready', 'cancelled'],
        'ready' => ['picked_up', 'cancelled'],
        'picked_up' => ['in_transit', 'failed', 'returned'],
        'in_transit' => ['out_for_delivery', 'failed', 'returned'],
        'out_for_delivery' => ['delivered', 'failed', 'returned'],
        'delivered' => [],
        'failed' => ['out_for_delivery', 'returned'],
        'returned' => [],
        'cancelled' => [],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'origin_address' => 'array',
            'destination_address' => 'array',
            'weight_kg' => 'float',
            'shipping_cost' => 'integer',
            'cod_amount' => 'integer',
            'is_cod' => 'boolean',
            'estimated_delivery' => 'date',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShipmentDocument::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withoutGlobalScope('tenant');
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::FLOW[$this->status] ?? [], true);
    }

    /** @return array<int, string> */
    public function allowedTransitions(): array
    {
        return self::FLOW[$this->status] ?? [];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['delivered', 'returned', 'cancelled'], true);
    }

    public function costMoney(): Money
    {
        return Money::of($this->shipping_cost, $this->currency);
    }

    public function carrierTrackingUrl(): ?string
    {
        return $this->carrier?->trackingUrlFor($this->tracking_number);
    }
}
