<?php

namespace App\Models\Accounting;

use App\Models\Orders\Order;
use App\Support\Concerns\BelongsToTenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'shipping_amount' => 'integer',
            'total' => 'integer',
            'paid_amount' => 'integer',
            'balance_due' => 'integer',
            'exchange_rate' => 'float',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function contact(): MorphTo
    {
        return $this->morphTo('contact');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    /**
     * تحديث أرقام السداد وحالتها من مصدر واحد.
     * كتابة الحالة يدوياً في كل موضع يسدّد تفتح باب التناقض.
     */
    public function applyPayment(int $amount): void
    {
        $this->paid_amount = min($this->total, $this->paid_amount + $amount);
        $this->balance_due = max(0, $this->total - $this->paid_amount - $this->creditedAmount());
        $this->status = $this->derivedStatus();
        $this->save();
    }

    /** ما خُصم بإشعارات ائتمان مطبَّقة على هذه الفاتورة. */
    public function creditedAmount(): int
    {
        return (int) CreditNote::withoutGlobalScope('tenant')
            ->where('invoice_id', $this->id)
            ->where('status', 'issued')
            ->sum('applied_amount');
    }

    public function derivedStatus(): string
    {
        if (in_array($this->status, ['draft', 'cancelled'], true)) {
            return $this->status;
        }

        if ($this->balance_due <= 0) {
            return 'paid';
        }

        if ($this->paid_amount > 0 || $this->creditedAmount() > 0) {
            return $this->isOverdue() ? 'overdue' : 'partially_paid';
        }

        return $this->isOverdue() ? 'overdue' : 'issued';
    }

    /**
     * التأخّر يُقاس بالتاريخ لا باللحظة: فاتورة تستحق اليوم ليست متأخرة،
     * ومقارنة الطابع الزمني كانت تجعلها متأخرة منذ منتصف الليل —
     * وتناقض تقرير الأعمار الذي يضعها في خانة "الرصيد الجاري".
     */
    public function isOverdue(): bool
    {
        return $this->balance_due > 0 && $this->due_date->lt(today());
    }

    /** عمر الدين بالأيام — أساس تقرير أعمار الذمم. */
    public function ageInDays(): int
    {
        return $this->balance_due > 0 ? max(0, (int) $this->due_date->diffInDays(now(), false)) : 0;
    }

    public function agingBucket(): string
    {
        $age = $this->ageInDays();

        return match (true) {
            $age <= 0 => 'current',
            $age <= 30 => '1_30',
            $age <= 60 => '31_60',
            $age <= 90 => '61_90',
            default => 'over_90',
        };
    }
}
