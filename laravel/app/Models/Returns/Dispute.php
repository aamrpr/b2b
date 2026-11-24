<?php

namespace App\Models\Returns;

use App\Models\Orders\Order;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * نزاع بين الطرفين تتوسّط فيه المنصة.
 * قرار الوسيط نهائي داخل المنصة ويُترجَم إلى إشعار دائن عند الاقتضاء.
 */
class Dispute extends Model
{
    public const FLOW = [
        'open' => ['under_review', 'closed'],
        'under_review' => ['awaiting_party', 'resolved', 'closed'],
        'awaiting_party' => ['under_review', 'resolved', 'closed'],
        'resolved' => ['closed'],
        'closed' => [],
    ];

    public const REASONS = [
        'not_delivered', 'damaged', 'quality', 'payment', 'return_refused', 'other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'claimed_amount' => 'integer',
            'resolution_amount' => 'integer',
            'resolved_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'buyer_tenant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'supplier_tenant_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'opened_by_tenant_id');
    }

    public function mediator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DisputeEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::FLOW[$this->status] ?? [], true);
    }

    public function involves(int $tenantId): bool
    {
        return in_array($tenantId, [$this->buyer_tenant_id, $this->supplier_tenant_id], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['resolved', 'closed'], true);
    }

    public function claimedMoney(): Money
    {
        return Money::of($this->claimed_amount, $this->currency);
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast() && $this->isOpen();
    }
}
