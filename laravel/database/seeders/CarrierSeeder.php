<?php

namespace Database\Seeders;

use App\Models\Shipping\Carrier;
use Illuminate\Database\Seeder;

/**
 * شركات شحن داخلية عامة. لا تكامل API مع أي منها —
 * التتبّع بإدخال يدوي، وهو الواقع في السوق المحلي.
 */
class CarrierSeeder extends Seeder
{
    private const CARRIERS = [
        ['alkadmous', 'القدموس للنقل', 'Al-Kadmous Transport', '+963112345678', true,
            ['دمشق', 'حلب', 'حمص', 'حماة', 'اللاذقية', 'طرطوس', 'السويداء', 'درعا']],
        ['alahliah', 'الأهلية للشحن', 'Al-Ahliah Shipping', '+963113456789', true,
            ['دمشق', 'حلب', 'حمص', 'حماة', 'اللاذقية']],
        ['pullman', 'بولمان اكسبرس', 'Pullman Express', '+963114567890', false,
            ['دمشق', 'حلب', 'اللاذقية', 'طرطوس']],
        ['internal', 'توصيل داخلي', 'Own Delivery', null, true, []],
    ];

    private const RATES = [
        // [from, to, min_kg, max_kg, base, per_kg, days]
        ['دمشق', 'حلب', 0, 50, 7_500_00, 150_00, 2],
        ['دمشق', 'حلب', 50, null, 12_000_00, 120_00, 2],
        ['دمشق', 'حمص', 0, 50, 4_500_00, 100_00, 1],
        ['دمشق', 'اللاذقية', 0, 50, 6_000_00, 130_00, 2],
        [null, null, 0, null, 9_000_00, 180_00, 3],
    ];

    public function run(): void
    {
        foreach (self::CARRIERS as $index => [$code, $ar, $en, $phone, $cod, $coverage]) {
            $carrier = Carrier::updateOrCreate(
                ['tenant_id' => null, 'code' => $code],
                [
                    'name' => ['ar' => $ar, 'en' => $en],
                    'phone' => $phone,
                    'coverage' => $coverage ?: null,
                    'supports_cod' => $cod,
                    'is_active' => true,
                    'sort_order' => $index,
                ]
            );

            if ($carrier->rates()->exists()) {
                continue;
            }

            foreach (self::RATES as [$from, $to, $min, $max, $base, $perKg, $days]) {
                if ($coverage !== [] && $to !== null && ! in_array($to, $coverage, true)) {
                    continue;
                }

                $carrier->rates()->create([
                    'from_city' => $from,
                    'to_city' => $to,
                    'min_weight_kg' => $min,
                    'max_weight_kg' => $max,
                    'base_price' => $base + $index * 50_000,
                    'price_per_kg' => $perKg,
                    'currency' => 'SYP',
                    'transit_days' => $days,
                    'is_active' => true,
                ]);
            }
        }
    }
}
