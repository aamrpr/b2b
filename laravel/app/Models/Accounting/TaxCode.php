<?php

namespace App\Models\Accounting;

use App\Support\Concerns\HasTranslations;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCode extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function salesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sales_account_id');
    }

    public function purchaseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'purchase_account_id');
    }

    /** مبلغ الضريبة على أساس خاضع — يراعي الضريبة الشاملة والمضافة. */
    public function taxOn(Money $base): Money
    {
        if ($this->type === 'inclusive') {
            $net = (int) round($base->amount / (1 + $this->rate / 100));

            return Money::of($base->amount - $net, $base->currency);
        }

        return $base->percentage($this->rate);
    }
}
