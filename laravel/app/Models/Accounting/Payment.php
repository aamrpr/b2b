<?php

namespace App\Models\Accounting;

use App\Support\Concerns\BelongsToTenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'exchange_rate' => 'float',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function contact(): MorphTo
    {
        return $this->morphTo('contact');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function unallocatedAmount(): int
    {
        return $this->amount - (int) $this->allocations()->sum('amount');
    }
}
