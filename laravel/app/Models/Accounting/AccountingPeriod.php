<?php

namespace App\Models\Accounting;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class)->withoutGlobalScope('tenant');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** الترحيل العادي ممنوع في فترة مقفلة. */
    public function acceptsPosting(bool $adjusting = false): bool
    {
        return match ($this->status) {
            'open' => true,
            'soft_closed' => $adjusting,
            default => false,
        };
    }
}
