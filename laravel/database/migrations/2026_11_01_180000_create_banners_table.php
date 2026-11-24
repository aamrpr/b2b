<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->json('eyebrow')->nullable();
            $table->json('title');
            $table->json('text')->nullable();
            $table->string('href');
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // الاختبارات تبني قاعدة فارغة. البيئة الحيّة تأخذ بنرات الصفحة الحالية.
        if (! app()->environment('testing')) {
            $this->seedCurrentBanners();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }

    private function seedCurrentBanners(): void
    {
        $slides = [
            ['auto-parts', 'قطع سيارات', 'Auto parts', 'قطع الغيار بالجملة، من وكيل واحد', 'Wholesale parts, from one source', 'فلاتر وبطاريات وفحمات وبوجيهات من مستوردين موثّقين.', 'Filters, batteries, pads and plugs from verified importers.'],
            ['furniture', 'أثاث', 'Furniture', 'أثاث جاهز لقاعة العرض', 'Furniture ready for the showroom', 'طاولات وكراسي تصل بكميات الجملة، جاهزة للبيع.', 'Tables and chairs in wholesale quantities, ready to sell.'],
            ['food-beverages', 'مواد غذائية', 'Food & beverages', 'توريد مستمر للمحل والمطعم', 'Steady supply for the shop and the kitchen', 'مواد غذائية ومشروبات من مستوردين في مدنك.', 'Food and drinks from importers in your cities.'],
            ['household-goods', 'منزليات', 'Household', 'أدوات المنزل بكميات الجملة', 'Household goods in bulk', 'قدور وخلاطات وما يحتاجه المحل على الرف.', 'Cookware, blenders and what belongs on the shelf.'],
        ];

        $now = now();

        foreach ($slides as $index => [$slug, $eyebrowAr, $eyebrowEn, $titleAr, $titleEn, $textAr, $textEn]) {
            $source = database_path("seeders/assets/categories/{$slug}.jpg");
            $image = null;

            if (is_file($source)) {
                $image = "banners/{$slug}.jpg";

                if (! Storage::disk('public')->exists($image)) {
                    Storage::disk('public')->put($image, (string) file_get_contents($source));
                }
            }

            DB::table('banners')->insert([
                'eyebrow' => json_encode(['ar' => $eyebrowAr, 'en' => $eyebrowEn], JSON_UNESCAPED_UNICODE),
                'title' => json_encode(['ar' => $titleAr, 'en' => $titleEn], JSON_UNESCAPED_UNICODE),
                'text' => json_encode(['ar' => $textAr, 'en' => $textEn], JSON_UNESCAPED_UNICODE),
                'href' => '/products?category='.$slug,
                'image_path' => $image,
                'sort_order' => $index,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
