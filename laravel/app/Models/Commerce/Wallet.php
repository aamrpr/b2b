<?php

namespace App\Models\Commerce;

use App\Models\Accounting\Account;
use App\Support\Concerns\BelongsToTenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'pending_balance' => 'integer',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function balanceMoney(): Money
    {
        return Money::of($this->balance, $this->currency);
    }

    /** الرصيد المشتق من الحركات — يجب أن يطابق العمود المخزَّن دائماً. */
    public function derivedBalance(): int
    {
        return (int) $this->transactions()
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0) AS b")
            ->value('b');
    }
}
