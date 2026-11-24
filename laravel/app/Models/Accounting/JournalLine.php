<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'debit' => 'integer',
            'credit' => 'integer',
            'base_debit' => 'integer',
            'base_credit' => 'integer',
            'exchange_rate' => 'float',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * السطر يحمل tenant_id الخاص به ومحميّ بالقيد على القيد الأب،
     * لذا تُقرأ العلاقة بلا نطاق المستأجر الحالي — وإلا انكسر عرض
     * قيود دفتر المنصة أثناء العمل في سياق مستورد.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScope('tenant');
    }

    public function contact(): MorphTo
    {
        return $this->morphTo('contact');
    }
}
