<?php

namespace Tests\Feature\Catalog;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الخصائص نص حر بلغتين، والتصفية تُبنى من مفتاح ثابت لا من لغة القارئ:
 * اختيار الجهد بالعربية يبقى نفسه حين تُعرض القيمة «12V».
 */
class ProductAttributeFilterTest extends TestCase
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

    public function test_attribute_values_are_or_and_different_attributes_are_and(): void
    {
        $this->product('battery', [
            ['label' => ['ar' => 'الجهد', 'en' => 'Voltage'], 'value' => ['ar' => '12 فولت', 'en' => '12V']],
            ['label' => ['ar' => 'المادة', 'en' => 'Material'], 'value' => ['ar' => 'رصاص', 'en' => 'Lead']],
        ]);
        $this->product('blender', [
            ['label' => ['ar' => 'الجهد', 'en' => 'Voltage'], 'value' => ['ar' => '220 فولت', 'en' => '220V']],
            ['label' => ['ar' => 'المادة', 'en' => 'Material'], 'value' => ['ar' => 'زجاج', 'en' => 'Glass']],
        ]);
        $this->product('plain', []);

        $index = $this->withHeader('X-Locale', 'ar')->getJson('/api/v1/products')->assertOk()->json();
        $voltage = collect($index['facets']['attributes'])->firstWhere('name', 'الجهد');
        $twelve = collect($voltage['values'])->firstWhere('name', '12 فولت');
        $mains = collect($voltage['values'])->firstWhere('name', '220 فولت');
        $material = collect($index['facets']['attributes'])->firstWhere('name', 'المادة');
        $lead = collect($material['values'])->firstWhere('name', 'رصاص');
        $glass = collect($material['values'])->firstWhere('name', 'زجاج');

        $this->assertSame(1, $twelve['count']);
        $this->assertSame(1, $mains['count']);

        $onlyTwelve = $this->catalog($this->token($voltage['key'], $twelve['key']));
        $this->assertSame(['بطارية'], $onlyTwelve);

        $eitherVoltage = $this->catalog(implode("\x1e", [
            $this->token($voltage['key'], $twelve['key']),
            $this->token($voltage['key'], $mains['key']),
        ]));
        $this->assertEqualsCanonicalizing(['بطارية', 'خلاط'], $eitherVoltage);

        $twelveAndLead = $this->catalog(implode("\x1e", [
            $this->token($voltage['key'], $twelve['key']),
            $this->token($material['key'], $lead['key']),
        ]));
        $this->assertSame(['بطارية'], $twelveAndLead);

        $twelveAndGlass = $this->catalog(implode("\x1e", [
            $this->token($voltage['key'], $twelve['key']),
            $this->token($material['key'], $glass['key']),
        ]));
        $this->assertSame([], $twelveAndGlass);
    }

    public function test_selecting_a_value_does_not_hide_the_other_values_of_that_attribute(): void
    {
        $this->product('battery', [
            ['label' => ['ar' => 'الجهد', 'en' => 'Voltage'], 'value' => ['ar' => '12 فولت', 'en' => '12V']],
        ]);
        $this->product('blender', [
            ['label' => ['ar' => 'الجهد', 'en' => 'Voltage'], 'value' => ['ar' => '220 فولت', 'en' => '220V']],
        ]);

        $index = $this->withHeader('X-Locale', 'ar')->getJson('/api/v1/products')->json();
        $voltage = collect($index['facets']['attributes'])->firstWhere('name', 'الجهد');
        $twelve = collect($voltage['values'])->firstWhere('name', '12 فولت');

        $filtered = $this->withHeader('X-Locale', 'ar')->getJson('/api/v1/products?'.http_build_query([
            'attr' => $this->token($voltage['key'], $twelve['key']),
        ]))->assertOk()->json();

        $names = collect($filtered['facets']['attributes'])->firstWhere('key', $voltage['key']);
        $this->assertEqualsCanonicalizing(
            ['12 فولت', '220 فولت'],
            collect($names['values'])->pluck('name')->all(),
        );
    }

    public function test_the_attribute_key_stays_put_when_the_label_is_translated(): void
    {
        $this->product('battery', [
            ['label' => ['ar' => 'الجهد', 'en' => 'Voltage'], 'value' => ['ar' => '12 فولت', 'en' => '12V']],
        ]);

        $arabic = collect($this->withHeader('X-Locale', 'ar')->getJson('/api/v1/products')->json('facets.attributes'))->firstWhere('name', 'الجهد');
        $english = collect($this->getJson('/api/v1/products', ['X-Locale' => 'en'])->json('facets.attributes'))
            ->firstWhere('name', 'Voltage');

        $this->assertSame($arabic['key'], $english['key']);
        $this->assertSame($arabic['values'][0]['key'], $english['values'][0]['key']);
        $this->assertSame('12V', $english['values'][0]['name']);

        $names = $this->catalog($this->token($english['key'], $english['values'][0]['key']));
        $this->assertSame(['بطارية'], $names);
    }

    /** @param  list<array{label: array<string, string>, value: array<string, string>}>  $specs */
    private function product(string $slug, array $specs): Product
    {
        $names = [
            'battery' => ['ar' => 'بطارية', 'en' => 'Battery'],
            'blender' => ['ar' => 'خلاط', 'en' => 'Blender'],
            'plain' => ['ar' => 'صندوق', 'en' => 'Box'],
        ];

        return Product::query()->create([
            'tenant_id' => $this->supplier->id,
            'category_id' => $this->category->id,
            'name' => $names[$slug],
            'description' => ['ar' => 'وصف', 'en' => 'Description'],
            'slug' => $slug,
            'sku' => strtoupper($slug),
            'base_price' => 45000,
            'currency' => 'SYP',
            'status' => 'active',
            'specifications' => $specs,
        ]);
    }

    /** @return list<string> */
    private function catalog(string $attr): array
    {
        return $this->withHeader('X-Locale', 'ar')
            ->getJson('/api/v1/products?'.http_build_query(['attr' => $attr]))
            ->assertOk()
            ->json('data.*.name');
    }

    private function token(string $label, string $value): string
    {
        return $label."\x1f".$value;
    }
}
