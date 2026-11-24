<?php

namespace App\Models\Accounting;

use App\Models\User;
use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * قيد اليومية. بعد الترحيل (posted) لا يُعدَّل ولا يُحذف — التصحيح بقيد عكسي فقط.
 */
class JournalEntry extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'total_debit' => 'integer',
            'total_credit' => 'integer',
            'exchange_rate' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // ثبات السجل: حماية على مستوى الموديل تكمّل قيود قاعدة البيانات
        static::updating(function (self $entry) {
            if ($entry->getOriginal('status') === 'posted') {
                $dirty = array_keys($entry->getDirty());
                $allowed = ['status', 'reversed_by_entry_id', 'updated_at'];

                if (array_diff($dirty, $allowed) !== []) {
                    throw new RuntimeException('لا يمكن تعديل قيد مُرحَّل. استخدم قيداً عكسياً.');
                }
            }
        });

        static::deleting(function (self $entry) {
            if ($entry->status === 'posted') {
                throw new RuntimeException('لا يمكن حذف قيد مُرحَّل.');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id')->withoutGlobalScope('tenant');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    public function isBalanced(): bool
    {
        return $this->total_debit === $this->total_credit;
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }
}
