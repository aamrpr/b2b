<?php

namespace App\Models\Accounting;

use App\Models\Returns\ReturnRequest;
use App\Support\Concerns\BelongsToTenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'applied_amount' => 'integer',
            'exchange_rate' => 'float',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('tenant');
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withoutGlobalScope('tenant');
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    /** الرصيد غير المستخدم من الإشعار — يُخصم من فواتير لاحقة. */
    public function unappliedAmount(): int
    {
        return max(0, $this->total - $this->applied_amount);
    }
}
