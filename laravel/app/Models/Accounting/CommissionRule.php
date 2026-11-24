<?php

namespace App\Models\Accounting;

use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tiers' => 'array',
            'value' => 'float',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** احتساب العمولة على مبلغ الصفقة حسب نوع القاعدة. */
    public function calculate(Money $base): Money
    {
        return match ($this->type) {
            'fixed' => Money::of((int) $this->value, $base->currency),
            'tiered' => $this->calculateTiered($base),
            default => $base->percentage($this->value),
        };
    }

    public function effectiveRate(Money $base): float
    {
        if ($base->isZero()) {
            return 0.0;
        }

        return round($this->calculate($base)->amount / $base->amount * 100, 3);
    }

    private function calculateTiered(Money $base): Money
    {
        $amount = 0;
        $remaining = $base->amount;
        $floor = 0;

        foreach ($this->tiers ?? [] as $tier) {
            $ceiling = $tier['up_to'] ?? null;
            $slice = $ceiling === null ? $remaining : min($remaining, $ceiling - $floor);

            if ($slice <= 0) {
                continue;
            }

            $amount += (int) round($slice * ($tier['rate'] ?? 0) / 100);
            $remaining -= $slice;
            $floor = $ceiling ?? $floor;

            if ($remaining <= 0) {
                break;
            }
        }

        return Money::of($amount, $base->currency);
    }
}
