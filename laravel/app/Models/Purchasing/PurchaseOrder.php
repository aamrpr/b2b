<?php

namespace App\Models\Purchasing;

use App\Models\Inventory\Warehouse;
use App\Models\User;
use App\Support\Concerns\BelongsToTenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToTenant;

    public const FLOW = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['partially_received', 'received', 'cancelled'],
        'partially_received' => ['received', 'closed', 'cancelled'],
        'received' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'shipping_amount' => 'integer',
            'total' => 'integer',
            'exchange_rate' => 'float',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScope('tenant');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScope('tenant');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::FLOW[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['closed', 'cancelled'], true);
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    /** الكميات التي لم تُستلم بعد. */
    public function pendingQuantities(): array
    {
        return $this->items
            ->mapWithKeys(fn (PurchaseOrderItem $item) => [
                $item->id => max(0, $item->quantity - $item->received_quantity),
            ])
            ->all();
    }

    /** الكميات المستلمة ولم تُفوتَر — أساس رصيد "مستلمة غير مفوترة". */
    public function uninvoicedQuantities(): array
    {
        return $this->items
            ->mapWithKeys(fn (PurchaseOrderItem $item) => [
                $item->id => max(0, $item->received_quantity - $item->invoiced_quantity),
            ])
            ->all();
    }

    public function isFullyReceived(): bool
    {
        return $this->items->every(fn (PurchaseOrderItem $item) => $item->received_quantity >= $item->quantity);
    }
}
