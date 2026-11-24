<?php

namespace App\Models\Accounting;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * أرصدة مُجمَّعة لتسريع التقارير. تُحدَّث داخل نفس معاملة الترحيل،
 * ومهمة ليلية تعيد احتسابها من الأسطر وتقارن — أي اختلاف إنذار.
 */
class AccountBalance extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opening_debit' => 'integer',
            'opening_credit' => 'integer',
            'period_debit' => 'integer',
            'period_credit' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScope('tenant');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id')->withoutGlobalScope('tenant');
    }

    public function closingDebit(): int
    {
        return $this->opening_debit + $this->period_debit;
    }

    public function closingCredit(): int
    {
        return $this->opening_credit + $this->period_credit;
    }
}
