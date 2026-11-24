<?php

namespace Database\Seeders;

use App\Models\Accounting\Currency;
use App\Models\Accounting\ExchangeRate;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['SYP', 'ليرة سورية', 'Syrian Pound', 'ل.س', 2, 1],
            ['USD', 'دولار أمريكي', 'US Dollar', '$', 2, 2],
            ['TRY', 'ليرة تركية', 'Turkish Lira', '₺', 2, 3],
            ['EUR', 'يورو', 'Euro', '€', 2, 4],
            ['AED', 'درهم إماراتي', 'UAE Dirham', 'د.إ', 2, 5],
        ];

        foreach ($currencies as [$code, $ar, $en, $symbol, $decimals, $order]) {
            Currency::updateOrCreate(
                ['code' => $code],
                [
                    'name' => ['ar' => $ar, 'en' => $en],
                    'symbol' => $symbol,
                    'decimal_places' => $decimals,
                    'is_active' => true,
                    'sort_order' => $order,
                ]
            );
        }

        // أسعار صرف ابتدائية — تُحدَّث يدوياً أو من مزوّد
        $rates = [
            ['USD', 'SYP', 13000],
            ['TRY', 'SYP', 380],
            ['EUR', 'SYP', 14000],
            ['AED', 'SYP', 3540],
        ];

        foreach ($rates as [$from, $to, $rate]) {
            ExchangeRate::updateOrCreate(
                ['from_currency' => $from, 'to_currency' => $to, 'rate_date' => today()],
                ['rate' => $rate, 'source' => 'manual']
            );
        }
    }
}
