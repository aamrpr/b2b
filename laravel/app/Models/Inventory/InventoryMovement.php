<?php

namespace App\Models\Inventory;

use App\Models\Accounting\JournalEntry;
use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * حركة مخزون. المصدر الوحيد للحقيقة — لا تُعدَّل ولا تُحذف،
 * والتصحيح بحركة تسوية مقابلة تماماً كالقيد المحاسبي.
 */
class InventoryMovement extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'total_cost' => 'integer',
            'balance_after' => 'integer',
            'average_cost_after' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('حركة المخزون لا تُعدَّل — سجّل حركة تسوية.'));
        static::deleting(fn () => throw new RuntimeException('حركة المخزون لا تُحذف.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withoutGlobalScope('tenant');
    }

    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    public function isInbound(): bool
    {
        return $this->quantity > 0;
    }
}
