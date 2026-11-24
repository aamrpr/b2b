<?php

namespace App\Models\Accounting;

use App\Models\Orders\Order;
use App\Models\Tenancy\Tenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'base_amount' => 'integer',
            'amount' => 'integer',
            'reversed_amount' => 'integer',
            'rate' => 'float',
            'accrued_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'supplier_tenant_id');
    }

    public function platformEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'platform_journal_entry_id');
    }

    public function supplierEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'supplier_journal_entry_id');
    }

    /** العمولة بعد خصم ما عُكس — هذا هو المستحق فعلاً. */
    public function netAmount(): int
    {
        return max(0, $this->amount - $this->reversed_amount);
    }

    /** كل من يحصّل أو يعرض مستحقاً يريد الصافي لا الإجمالي المستحَق أصلاً. */
    public function amountMoney(): Money
    {
        return Money::of($this->netAmount(), $this->currency);
    }

    public function grossMoney(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
