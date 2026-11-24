<?php

namespace App\Services\Accounting;

use App\Models\Accounting\ExchangeRate;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * تحويل العملات بسعر صرف مؤرَّخ.
 * كل قيد يحمل سعر الصرف المستخدم لحظة الترحيل — لا يُعاد احتسابه لاحقاً أبداً.
 */
class CurrencyConverter
{
    /** @var array<string, float> */
    private array $cache = [];

    public function rate(string $from, string $to, ?Carbon $date = null): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $date ??= Carbon::today();
        $key = "{$from}:{$to}:{$date->toDateString()}";

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // أحدث سعر في تاريخه أو قبله
        $direct = ExchangeRate::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->value('rate');

        if ($direct !== null) {
            return $this->cache[$key] = (float) $direct;
        }

        // السعر المعكوس
        $inverse = ExchangeRate::query()
            ->where('from_currency', $to)
            ->where('to_currency', $from)
            ->whereDate('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->value('rate');

        if ($inverse !== null && (float) $inverse > 0) {
            return $this->cache[$key] = 1 / (float) $inverse;
        }

        throw new RuntimeException("لا يوجد سعر صرف من {$from} إلى {$to} بتاريخ {$date->toDateString()}");
    }

    public function convert(Money $money, string $to, ?Carbon $date = null): Money
    {
        $to = strtoupper($to);

        if ($money->currency === $to) {
            return $money;
        }

        return Money::of(
            (int) round($money->amount * $this->rate($money->currency, $to, $date)),
            $to
        );
    }

    /** التحويل بسعر صرف معلوم مسبقاً (المخزَّن على المستند). */
    public function convertAtRate(Money $money, string $to, float $rate): Money
    {
        return Money::of((int) round($money->amount * $rate), strtoupper($to));
    }
}
