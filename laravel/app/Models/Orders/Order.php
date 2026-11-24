<?php

namespace App\Models\Orders;

use App\Models\Accounting\Commission;
use App\Models\Accounting\Invoice;
use App\Models\Rfq\Quotation;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
            'shipping_address' => 'array',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'shipping_amount' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'base_total' => 'integer',
            'commission_amount' => 'integer',
            'exchange_rate' => 'float',
            'commission_rate' => 'float',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'supplier_tenant_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'buyer_tenant_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    public function netMoney(): Money
    {
        return Money::of($this->subtotal - $this->discount_amount, $this->currency);
    }
}
