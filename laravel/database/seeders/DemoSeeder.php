<?php

namespace Database\Seeders;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\SupplierCategory;
use App\Models\Inventory\Warehouse;
use App\Models\Tenancy\SupplierProfile;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * بيانات تجريبية تحاكي السيناريو:
 * مستوردون في صنف "قطع سيارات" + تاجر جملة يبحث عنهم.
 */
class DemoSeeder extends Seeder
{
    private const SUPPLIERS = [
        ['شركة النور لقطع السيارات', 'Al-Noor Auto Parts', 'دمشق', 'auto-parts', ['Toyota', 'Hyundai', 'Kia']],
        ['مؤسسة الشام للاستيراد', 'Al-Sham Import', 'حلب', 'auto-parts', ['Bosch', 'Denso']],
        ['مجموعة الفرات التجارية', 'Euphrates Trading', 'حمص', 'auto-parts', ['Valeo', 'NGK']],
        ['بيت المنزليات الحديث', 'Modern Household', 'دمشق', 'household-goods', ['Tefal', 'Arcoroc']],
        ['دار الأثاث الدمشقي', 'Damascene Furniture House', 'دمشق', 'furniture', ['IKEA-style']],
    ];

    private const PRODUCTS = [
        'auto-parts' => [
            ['فلتر زيت تويوتا أصلي', 'Toyota Genuine Oil Filter', 'piece', 50, 7, 45000],
            ['بطارية 70 أمبير', '70Ah Car Battery', 'piece', 10, 14, 850000],
            ['طقم فحمات فرامل أمامية', 'Front Brake Pad Set', 'set', 20, 10, 320000],
            ['بوجيهات NGK إيريديوم', 'NGK Iridium Spark Plugs', 'piece', 100, 7, 95000],
        ],
        'household-goods' => [
            ['طقم قدور تيفال 10 قطع', 'Tefal 10-Piece Cookware Set', 'set', 5, 21, 2400000],
            ['خلاط كهربائي 600 واط', '600W Electric Blender', 'piece', 12, 14, 780000],
        ],
        'furniture' => [
            ['طاولة طعام خشب زان 6 مقاعد', 'Beech Dining Table for 6', 'piece', 3, 30, 5500000],
            ['كرسي مكتبي دوّار', 'Swivel Office Chair', 'piece', 10, 21, 1250000],
        ],
    ];

    /**
     * خصائص كل منتج بترتيب أهمّيتها لتاجر الجملة: ما يَفصل بين قطعتين
     * متشابهتين أوّلاً — رقم القطعة والتوافق — ثمّ التفاصيل المادية.
     */
    private const SPECS = [
        'Toyota Genuine Oil Filter' => [
            ['رقم القطعة', 'Part number', '90915-YZZD2', '90915-YZZD2'],
            ['التوافق', 'Fits', 'كورولا وكامري 2010–2020', 'Corolla and Camry 2010–2020'],
            ['مادة الترشيح', 'Filter media', 'ورق متعدّد الطبقات', 'Multi-layer paper'],
            ['الضمان', 'Warranty', '12 شهراً من الوكيل', '12 months dealer warranty'],
            ['التعبئة', 'Packing', 'كرتونة 50 قطعة', 'Carton of 50'],
        ],
        '70Ah Car Battery' => [
            ['السعة', 'Capacity', '70 أمبير/ساعة', '70Ah'],
            ['الجهد', 'Voltage', '12 فولت', '12V'],
            ['تيار الإقلاع البارد', 'Cold cranking amps', '640 أمبير', '640A'],
            ['النوع', 'Type', 'سائلة قليلة الصيانة', 'Low-maintenance flooded'],
            ['الأبعاد', 'Dimensions', '278 × 175 × 190 مم', '278 × 175 × 190 mm'],
            ['الوزن', 'Weight', '17.5 كغ', '17.5 kg'],
        ],
        'Front Brake Pad Set' => [
            ['الموضع', 'Position', 'أمامي', 'Front axle'],
            ['المادة', 'Material', 'سيراميك خالٍ من الأسبستوس', 'Asbestos-free ceramic'],
            ['عدد القطع', 'Pieces', '4 فحمات', '4 pads'],
            ['مؤشّر التآكل', 'Wear indicator', 'مدمج', 'Built in'],
            ['التعبئة', 'Packing', 'علبة لكل طقم', 'One box per set'],
        ],
        'NGK Iridium Spark Plugs' => [
            ['القطب', 'Electrode', 'إيريديوم 0.6 مم', '0.6 mm iridium'],
            ['فجوة الشرارة', 'Spark gap', '0.8 مم', '0.8 mm'],
            ['السنّ', 'Thread', '14 × 1.25 مم', '14 × 1.25 mm'],
            ['العمر المتوقّع', 'Service life', '100,000 كم', '100,000 km'],
        ],
        'Tefal 10-Piece Cookware Set' => [
            ['عدد القطع', 'Pieces', '10 قطع', '10 pieces'],
            ['المادة', 'Material', 'ألمنيوم بطلاء غير لاصق', 'Non-stick coated aluminium'],
            ['يصلح للحثّ', 'Induction ready', 'نعم', 'Yes'],
            ['الضمان', 'Warranty', 'سنتان', '2 years'],
        ],
        '600W Electric Blender' => [
            ['القدرة', 'Power', '600 واط', '600W'],
            ['سعة الإبريق', 'Jar capacity', '1.5 لتر', '1.5 L'],
            ['السرعات', 'Speeds', 'سرعتان مع نبض', '2 speeds with pulse'],
            ['مادة الإبريق', 'Jar material', 'زجاج مقوّى', 'Tempered glass'],
            ['الجهد', 'Voltage', '220 فولت', '220V'],
        ],
        'Beech Dining Table for 6' => [
            ['المادة', 'Material', 'خشب زان طبيعي', 'Solid beech'],
            ['عدد المقاعد', 'Seats', '6', '6'],
            ['الأبعاد', 'Dimensions', '180 × 90 × 75 سم', '180 × 90 × 75 cm'],
            ['التشطيب', 'Finish', 'ورنيش مطفأ', 'Matte lacquer'],
            ['التجميع', 'Assembly', 'يُشحن مفكوكاً', 'Flat-packed'],
        ],
        'Swivel Office Chair' => [
            ['الارتفاع', 'Height', 'قابل للتعديل 45–55 سم', 'Adjustable 45–55 cm'],
            ['الحمولة القصوى', 'Max load', '120 كغ', '120 kg'],
            ['المسند', 'Backrest', 'شبك تنفّسي', 'Breathable mesh'],
            ['القاعدة', 'Base', 'نايلون بخمس عجلات', 'Five-castor nylon'],
        ],
    ];

    public function run(): void
    {
        $provisioner = app(TenantProvisioner::class);
        $inventory = app(InventoryService::class);

        foreach (self::SUPPLIERS as $index => [$nameAr, $nameEn, $city, $categorySlug, $brands]) {
            $tenant = $provisioner->createSupplier([
                'name' => $nameAr,
                'city' => $city,
                'base_currency' => 'SYP',
                'locale' => 'ar',
            ]);

            $tenant->update(['status' => 'active']);

            SupplierProfile::create([
                'tenant_id' => $tenant->id,
                'legal_name' => $nameAr,
                'display_name' => ['ar' => $nameAr, 'en' => $nameEn],
                'description' => [
                    'ar' => "مستورد وموزّع معتمد — {$city}",
                    'en' => "Licensed importer and distributor — {$city}",
                ],
                'phone' => '+9639'.random_int(10000000, 99999999),
                'verification_status' => 'verified',
                'verified_at' => now(),
                'rating_avg' => round(random_int(38, 50) / 10, 2),
                'rating_count' => random_int(5, 60),
                'completed_orders_count' => random_int(10, 200),
            ]);

            $category = Category::where('slug', $categorySlug)->firstOrFail();

            SupplierCategory::create([
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'is_primary' => true,
                'years_experience' => random_int(3, 25),
                'brands' => $brands,
                'receives_rfq' => true,
            ]);

            // المستورد مسجّل أيضاً في الفئات الفرعية ليصله الطلب الدقيق
            foreach ($category->children as $child) {
                SupplierCategory::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $child->id,
                    'is_primary' => false,
                    'receives_rfq' => true,
                ]);
            }

            User::create([
                'tenant_id' => $tenant->id,
                'name' => 'مدير '.$nameAr,
                'email' => "supplier{$index}@b2b.local",
                'phone' => '+9639'.random_int(10000000, 99999999),
                'password' => 'password',
                'locale' => 'ar',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $warehouse = Warehouse::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'name' => ['ar' => "مستودع {$city}", 'en' => "{$city} Warehouse"],
                'code' => 'WH-'.$tenant->id,
                'address' => $city,
                'is_default' => true,
                'is_active' => true,
            ]);

            foreach (self::PRODUCTS[$categorySlug] as [$pAr, $pEn, $unit, $moq, $lead, $price]) {
                $product = Product::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $category->id,
                    'name' => ['ar' => $pAr, 'en' => $pEn],
                    'description' => [
                        'ar' => 'منتج مستورد بضمان الوكيل، متوفر بكميات الجملة.',
                        'en' => 'Imported product with dealer warranty, available in wholesale quantities.',
                    ],
                    'slug' => Str::slug($pEn).'-'.Str::lower(Str::random(6)),
                    'sku' => strtoupper(Str::random(8)),
                    'brand' => $brands[0] ?? null,
                    'country_of_origin' => 'TR',
                    'unit_of_measure' => $unit,
                    'min_order_quantity' => $moq,
                    'lead_time_days' => $lead,
                    'price_visible' => true,
                    'base_price' => $price + random_int(-5000, 5000),
                    'currency' => 'SYP',
                    'status' => 'active',
                    'specifications' => collect(self::SPECS[$pEn] ?? [])
                        ->map(fn ($spec) => [
                            'label' => ['ar' => $spec[0], 'en' => $spec[1]],
                            'value' => ['ar' => $spec[2], 'en' => $spec[3]],
                        ])
                        ->all(),
                ]);

                $this->attachImages($product, $categorySlug);

                // رصيد افتتاحي بتكلفة 65% من سعر البيع، مُرحَّل محاسبياً
                $inventory->receive(
                    $inventory->itemFor($product, $warehouse),
                    random_int(50, 400),
                    (int) ($product->base_price * 0.65),
                    'opening',
                    null,
                    true,
                    'capital',
                    'رصيد افتتاحي',
                );
            }
        }

        /* تاجر جملة */
        $buyer = $provisioner->createBuyer([
            'name' => 'محلات الأمانة للجملة',
            'city' => 'دمشق',
            'locale' => 'ar',
        ]);

        User::create([
            'tenant_id' => $buyer->id,
            'name' => 'أبو أحمد',
            'email' => 'buyer@b2b.local',
            'phone' => '+963911111111',
            'password' => 'password',
            'locale' => 'ar',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * صور المنتج التجريبية.
     *
     * لا صور حقيقية في بذرةٍ لا تملك حقوق صور أحد، فتُركَّب ثلاث لوحات من
     * رمز الصنف نفسه بخلفيات مختلفة — تكفي لتجربة المعرض وترتيب الصور،
     * ويستبدلها المستورد برفع صوره من «منتجاتي».
     */
    private function attachImages(Product $product, string $categorySlug): void
    {
        if ($this->attachPhotos($product)) {
            return;
        }

        $glyph = $this->glyph($categorySlug);

        if ($glyph === null) {
            return;
        }

        foreach ([0, 1, 2] as $variant) {
            $path = "products/{$product->slug}-{$variant}.svg";

            Storage::disk('public')->put($path, $this->plate($glyph, $variant));

            $product->media()->create([
                'path' => $path,
                'type' => 'image',
                'sort_order' => $variant,
            ]);
        }
    }

    /**
     * صور المنتج الحقيقية.
     *
     * مجلوبة من ويكيميديا كومنز برخصٍ حرّة (نسبها في credits.json بجانبها)،
     * ومحفوظة في المستودع لا في التخزين: رفع صورةٍ جديدة يحذف الملف السابق،
     * فلو عاشت صور البذرة في التخزين وحده لضاعت عند أوّل استبدال.
     *
     * وتُسمّى باسم المنتج الإنجليزيّ لا بـslug قاعدة البيانات، فالأخير
     * يحمل لاحقةً عشوائية تتبدّل مع كل بذرة.
     */
    private function attachPhotos(Product $product): bool
    {
        $key = Str::slug($product->getAttribute('name')['en'] ?? '');
        $source = database_path('seeders/assets/products');
        $found = 0;

        foreach ([0, 1, 2] as $index) {
            foreach (['jpg', 'png'] as $extension) {
                $file = "{$source}/{$key}-{$index}.{$extension}";

                if (! is_file($file)) {
                    continue;
                }

                $path = "products/{$product->slug}-{$index}.{$extension}";

                if (! Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->put($path, file_get_contents($file));
                }

                $product->media()->create([
                    'path' => $path,
                    'type' => 'image',
                    'sort_order' => $index,
                ]);

                $found++;
                break;
            }
        }

        return $found > 0;
    }

    /** رمز الصنف وحده من لوحته، بلا خلفيتها. */
    private function glyph(string $categorySlug): ?string
    {
        $source = database_path("seeders/assets/categories/{$categorySlug}.svg");

        if (! is_file($source)) {
            return null;
        }

        return preg_match('/<g transform=.*<\/g>/s', file_get_contents($source), $match)
            ? $match[0]
            : null;
    }

    private function plate(string $glyph, int $variant): string
    {
        [$from, $to, $stroke, $wrap] = match ($variant) {
            0 => ['#142D4E', '#087F7B', '#ffffff', ''],
            1 => ['#F5F7FA', '#DCE6F2', '#142D4E', 'translate(60 0) scale(0.85) translate(60 37.5)'],
            default => ['#0B1A2E', '#142D4E', '#16A6A1', 'translate(0 -20) scale(1.15) translate(-52 -32)'],
        };

        $glyph = str_replace('stroke="#ffffff"', 'stroke="'.$stroke.'"', $glyph);
        $glyph = $wrap === '' ? $glyph : '<g transform="'.$wrap.'">'.$glyph.'</g>';

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500" width="800" height="500" role="img">
          <defs>
            <linearGradient id="plate" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="{$from}" />
              <stop offset="100%" stop-color="{$to}" />
            </linearGradient>
          </defs>
          <rect width="800" height="500" fill="url(#plate)" />
          {$glyph}
        </svg>
        SVG;
    }
}
