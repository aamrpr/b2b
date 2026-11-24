<?php

namespace App\Http\Resources;

/**
 * كل مبلغ يخرج من الـ API بنفس الشكل:
 * القيمة الخام بالوحدة الصغرى (للحسابات) + نص منسَّق (للعرض).
 */
class MoneyResource
{
    public static function make(?int $amount, string $currency, int $decimals = 2): array
    {
        $amount ??= 0;

        return [
            'amount' => $amount,
            'currency' => $currency,
            'decimals' => $decimals,
            'formatted' => number_format($amount / (10 ** $decimals), $decimals),
        ];
    }
}
