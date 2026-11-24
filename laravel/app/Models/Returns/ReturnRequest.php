<?php

namespace App\Models\Returns;

use App\Models\Accounting\CreditNote;
use App\Models\Orders\Order;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * طلب مرتجع. يفتحه تاجر الجملة، يبتّ فيه المستورد،
 * وتُقفَل دورته بإشعار دائن وقيوده.
 */
class ReturnRequest extends Model
{
    /** الانتقالات المسموحة — المرتجع لا يرجع للخلف */
    public const FLOW = [
        'requested' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['in_transit', 'received', 'cancelled'],
        'in_transit' => ['received', 'cancelled'],
        'received' => ['completed'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public const REASONS = [
        'damaged', 'wrong_item', 'not_as_described', 'quantity_short', 'expired', 'other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'integer',
            'approved_amount' => 'integer',
            'restock' => 'boolean',
            'decided_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReturnPhoto::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'buyer_tenant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'supplier_tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class)->withoutGlobalScope('tenant');
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::FLOW[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['completed', 'rejected', 'cancelled'], true);
    }

    public function approvedMoney(): Money
    {
        return Money::of($this->approved_amount, $this->currency);
    }

    /** قيمة ما استُلم فعلاً — هي أساس إشعار الائتمان لا ما طُلب. */
    public function receivedValue(): int
    {
        return (int) $this->items->sum(fn (ReturnRequestItem $item) => $item->received_quantity * $item->unit_price);
    }

    public function receivedCost(): int
    {
        return (int) $this->items->sum(fn (ReturnRequestItem $item) => $item->received_quantity * $item->unit_cost);
    }
}
