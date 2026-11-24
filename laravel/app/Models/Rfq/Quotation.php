<?php

namespace App\Models\Rfq;

use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valid_until' => 'datetime',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            'supplier_signed_at' => 'datetime',
            'buyer_signed_at' => 'datetime',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'shipping_amount' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'exchange_rate' => 'float',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'supplier_tenant_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'buyer_tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    public function isActionable(): bool
    {
        return in_array($this->status, ['sent', 'countered'], true)
            && $this->valid_until->isFuture();
    }
}
