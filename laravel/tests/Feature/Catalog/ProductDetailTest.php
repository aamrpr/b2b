<?php

namespace Tests\Feature\Catalog;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * صفحة المنتج هي ما يقرأه تاجر الجملة قبل أن يطلب عرض سعر، وما يَفصل
 * بين قطعتين متشابهتين فيها هو الخصائص والصور — فالاختبار يحرس أمرين:
 * أن تصل الخصائص بلغة القارئ لا بلغة من كتبها، وأن يبقى ترتيب الصور
 * ترتيبَ المستورد لأنّ أولاها هي الغلاف.
 */
class ProductDetailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $supplier;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform();
        $this->supplier = $this->makeSupplier('مستورد');

        $this->category = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->supplier->id,
            'category_id' => $this->category->id,
            'name' => ['ar' => 'فلتر زيت', 'en' => 'Oil Filter'],
            'description' => ['ar' => 'وصف', 'en' => 'Description'],
            'slug' => 'oil-filter-test',
            'sku' => 'OF-1',
            'base_price' => 45000,
            'currency' => 'SYP',
            'status' => 'active',
        ], $overrides));
    }

    public function test_specifications_follow_the_reader_locale(): void
    {
        $this->product(['specifications' => [
            ['label' => ['ar' => 'المادة', 'en' => 'Material'], 'value' => ['ar' => 'فولاذ', 'en' => 'Steel']],
        ]]);

        $this->getJson('/api/v1/products/oil-filter-test', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.specifications.0.label', 'المادة')
            ->assertJsonPath('data.specifications.0.value', 'فولاذ');

        $this->getJson('/api/v1/products/oil-filter-test', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.specifications.0.label', 'Material')
            ->assertJsonPath('data.specifications.0.value', 'Steel');
    }

    public function test_an_arabic_only_specification_still_reaches_an_english_reader(): void
    {
        $this->product(['specifications' => [
            ['label' => ['ar' => 'بلد المنشأ'], 'value' => ['ar' => 'تركيا']],
        ]]);

        $this->getJson('/api/v1/products/oil-filter-test', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.specifications.0.label', 'بلد المنشأ')
            ->assertJsonPath('data.specifications.0.value', 'تركيا');
    }

    public function test_images_keep_the_supplier_order_and_the_first_is_the_cover(): void
    {
        $product = $this->product();

        foreach ([['b.jpg', 1], ['a.jpg', 0], ['c.jpg', 2]] as [$path, $order]) {
            $product->media()->create(['path' => $path, 'type' => 'image', 'sort_order' => $order]);
        }

        $response = $this->getJson('/api/v1/products/oil-filter-test')->assertOk();

        $this->assertSame(
            ['a.jpg', 'b.jpg', 'c.jpg'],
            array_map(fn ($image) => basename($image['url']), $response->json('data.images')),
        );
        $this->assertSame('a.jpg', basename($response->json('data.cover_url')));
    }

    public function test_a_supplier_saves_specifications_and_english_falls_back_to_arabic(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->supplier->id,
            'name' => 'مدير',
            'email' => 'owner@test.local',
            'password' => 'password',
            'status' => 'active',
        ]));

        $this->postJson('/api/v1/my/products', [
            'category_id' => $this->category->id,
            'name' => ['ar' => 'بطارية', 'en' => 'Battery'],
            'base_price' => 850000,
            'specifications' => [
                ['label' => ['ar' => 'السعة', 'en' => 'Capacity'], 'value' => ['ar' => '70 أمبير', 'en' => '70Ah']],
                ['label' => ['ar' => 'الجهد'], 'value' => ['ar' => '12 فولت']],
            ],
        ])->assertCreated();

        $saved = Product::withoutGlobalScopes()->where('sku', null)->latest('id')->first();

        $this->assertSame('Capacity', $saved->specifications[0]['label']['en']);
        $this->assertSame('الجهد', $saved->specifications[1]['label']['en']);
        $this->assertSame('12 فولت', $saved->specifications[1]['value']['en']);
    }

    public function test_an_update_replaces_the_specifications_and_keeps_them_when_omitted(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->supplier->id,
            'name' => 'مدير',
            'email' => 'owner3@test.local',
            'password' => 'password',
            'status' => 'active',
        ]));

        $product = $this->product(['specifications' => [
            ['label' => ['ar' => 'المادة', 'en' => 'Material'], 'value' => ['ar' => 'فولاذ', 'en' => 'Steel']],
        ]]);

        $this->putJson("/api/v1/my/products/{$product->id}", [
            'specifications' => [
                ['label' => ['ar' => 'الوزن'], 'value' => ['ar' => '2 كغ']],
            ],
        ])->assertOk();

        $this->assertSame('الوزن', $product->fresh()->specifications[0]['label']['ar']);
        $this->assertCount(1, $product->fresh()->specifications);

        // تعديلٌ لا يذكر الخصائص لا يمسّها — وإلّا محا تغييرُ السعر خصائصَ المنتج
        $this->putJson("/api/v1/my/products/{$product->id}", ['base_price' => 99000])->assertOk();

        $this->assertSame('الوزن', $product->fresh()->specifications[0]['label']['ar']);
        $this->assertSame(99000, $product->fresh()->base_price);
    }

    public function test_a_specification_without_an_arabic_label_is_rejected(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->supplier->id,
            'name' => 'مدير',
            'email' => 'owner2@test.local',
            'password' => 'password',
            'status' => 'active',
        ]));

        $this->postJson('/api/v1/my/products', [
            'category_id' => $this->category->id,
            'name' => ['ar' => 'بطارية'],
            'base_price' => 1,
            'specifications' => [
                ['label' => ['en' => 'Capacity'], 'value' => ['ar' => '70 أمبير']],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('specifications.0.label.ar');
    }
}
