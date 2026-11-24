<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** طبقة تكلفة — تُستخدم عند اعتماد FIFO. */
class InventoryCostLayer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_in' => 'integer',
            'quantity_remaining' => 'integer',
            'unit_cost' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
    }
}
