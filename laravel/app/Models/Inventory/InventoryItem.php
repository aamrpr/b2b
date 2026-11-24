<?php

namespace App\Models\Inventory;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Support\Concerns\BelongsToTenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * رصيد صنف في مستودع. هذا الجدول لقطة مُسرِّعة —
 * المصدر الحقيقي للرصيد والتكلفة هو جدول الحركات.
 */
class InventoryItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'integer',
            'quantity_reserved' => 'integer',
            'average_cost' => 'integer',
            'total_value' => 'integer',
            'reorder_point' => 'integer',
            'last_movement_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScope('tenant');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function costLayers(): HasMany
    {
        return $this->hasMany(InventoryCostLayer::class)->orderBy('received_at')->orderBy('id');
    }

    /** المتاح للبيع = الموجود − المحجوز */
    public function availableQuantity(): int
    {
        return $this->quantity_on_hand - $this->quantity_reserved;
    }

    /** القيمة الفعلية المخزَّنة — لا الكمية × المتوسط المقرَّب. */
    public function value(): Money
    {
        return Money::of($this->total_value, $this->tenant->base_currency);
    }

    public function isBelowReorderPoint(): bool
    {
        return $this->reorder_point > 0 && $this->availableQuantity() <= $this->reorder_point;
    }

    /** الرصيد المشتق من الحركات — يجب أن يطابق العمود المخزَّن. */
    public function derivedQuantity(): int
    {
        return (int) $this->movements()->sum('quantity');
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('quantity_on_hand', '<=', 'reorder_point')
            ->where('reorder_point', '>', 0);
    }
}
