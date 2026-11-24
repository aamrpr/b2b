<?php

namespace Database\Seeders;

use App\Models\Catalog\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * الأصناف التي يعمل بها المستوردون — كما وردت في وصف المشروع.
 */
class CategorySeeder extends Seeder
{
    private const TREE = [
        ['قطع سيارات', 'Auto Parts', 'car', [
            ['قطع محرك', 'Engine Parts'],
            ['إطارات وجنوط', 'Tires & Rims'],
            ['بطاريات', 'Batteries'],
            ['زيوت وفلاتر', 'Oils & Filters'],
            ['كهرباء السيارة', 'Auto Electrical'],
            ['إكسسوارات داخلية', 'Interior Accessories'],
        ]],
        ['منزليات', 'Household Goods', 'home', [
            ['أدوات مطبخ', 'Kitchenware'],
            ['أجهزة كهربائية منزلية', 'Home Appliances'],
            ['مفروشات', 'Home Textiles'],
            ['أدوات تنظيف', 'Cleaning Supplies'],
            ['بلاستيك منزلي', 'Household Plastics'],
        ]],
        ['أثاث', 'Furniture', 'sofa', [
            ['أثاث غرف نوم', 'Bedroom Furniture'],
            ['أثاث مكتبي', 'Office Furniture'],
            ['طاولات وكراسي', 'Tables & Chairs'],
            ['أثاث خارجي', 'Outdoor Furniture'],
        ]],
        ['هدايا وألعاب', 'Gifts & Toys', 'gift', [
            ['ألعاب أطفال', 'Children Toys'],
            ['هدايا مناسبات', 'Occasion Gifts'],
            ['قرطاسية', 'Stationery'],
            ['ألعاب تعليمية', 'Educational Toys'],
        ]],
        ['مواد بناء', 'Building Materials', 'hammer', [
            ['أدوات صحية', 'Sanitary Ware'],
            ['سيراميك وبلاط', 'Ceramics & Tiles'],
            ['دهانات', 'Paints'],
            ['كهربائيات', 'Electrical Supplies'],
        ]],
        ['ملابس وأقمشة', 'Clothing & Textiles', 'shirt', [
            ['ملابس رجالية', 'Menswear'],
            ['ملابس نسائية', 'Womenswear'],
            ['ملابس أطفال', 'Kidswear'],
            ['أقمشة', 'Fabrics'],
        ]],
        ['مواد غذائية', 'Food & Beverages', 'utensils', [
            ['معلبات', 'Canned Food'],
            ['حبوب وبقوليات', 'Grains & Legumes'],
            ['مشروبات', 'Beverages'],
            ['توابل', 'Spices'],
        ]],
        ['إلكترونيات', 'Electronics', 'smartphone', [
            ['هواتف وإكسسوارات', 'Phones & Accessories'],
            ['حواسيب', 'Computers'],
            ['أجهزة صوتية', 'Audio Devices'],
        ]],
    ];

    /**
     * الصورة المبدئية للصنف.
     *
     * المصدر داخل المستودع لا في التخزين: رفع صورة جديدة يحذف الملف
     * السابق، فلو عاشت الصور المبدئية في التخزين وحده لضاعت عند أول
     * استبدال ولما استعادها البذر. هنا تُنسخ عند الحاجة فقط.
     *
     * الصورة الحقيقية (jpg) تُقدَّم على الرسم (svg). والرسم يبقى مصدراً
     * لرمز المنتج في البطاقات المولَّدة، فلا يُستبدل.
     */
    private function defaultImage(string $slug): ?string
    {
        foreach (['jpg', 'jpeg', 'webp', 'png', 'svg'] as $extension) {
            $source = database_path("seeders/assets/categories/{$slug}.{$extension}");

            if (! is_file($source)) {
                continue;
            }

            $path = "categories/{$slug}.{$extension}";

            if (! Storage::disk('public')->exists($path)) {
                Storage::disk('public')->put($path, file_get_contents($source));
            }

            return $path;
        }

        return null;
    }

    public function run(): void
    {
        foreach (self::TREE as $index => [$ar, $en, $icon, $children]) {
            $slug = Str::slug($en);

            $parent = Category::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => ['ar' => $ar, 'en' => $en],
                    'icon' => $icon,
                    'sort_order' => $index,
                    'is_active' => true,
                    'is_featured' => $index < 4,
                ]
            );

            // الصورة المبدئية تُوضع للصنف الذي لا صورة له فقط،
            // فإعادة البذر لا تمحو صورة رفعتها المنصة
            if (! $parent->image_path) {
                $parent->update(['image_path' => $this->defaultImage($slug)]);
            }

            foreach ($children as $childIndex => [$childAr, $childEn]) {
                Category::updateOrCreate(
                    ['slug' => Str::slug($childEn)],
                    [
                        'parent_id' => $parent->id,
                        'name' => ['ar' => $childAr, 'en' => $childEn],
                        'sort_order' => $childIndex,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
