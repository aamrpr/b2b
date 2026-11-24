<?php

namespace App\Models\Accounting;

use App\Support\Concerns\BelongsToTenant;
use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * حساب في دليل الحسابات. system_key هو المفتاح الثابت الذي يربط
 * محرك القيود بالحساب دون الاعتماد على الكود الرقمي القابل للتغيير.
 */
class Account extends Model
{
    use BelongsToTenant, HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_leaf' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isDebitNatured(): bool
    {
        return $this->normal_balance === 'debit';
    }

    /** الرصيد بعملة الدفتر حتى تاريخ معيّن (موجب = في الطبيعة الصحيحة للحساب) */
    public function balance(?string $upTo = null): int
    {
        $query = JournalLine::query()
            ->where('journal_lines.account_id', $this->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted');

        if ($upTo) {
            $query->whereDate('journal_entries.entry_date', '<=', $upTo);
        }

        $totals = $query->selectRaw('COALESCE(SUM(base_debit),0) AS d, COALESCE(SUM(base_credit),0) AS c')->first();

        $debit = (int) $totals->d;
        $credit = (int) $totals->c;

        return $this->isDebitNatured() ? $debit - $credit : $credit - $debit;
    }

    public function scopeLeaf($query)
    {
        return $query->where('is_leaf', true)->where('is_active', true);
    }
}
