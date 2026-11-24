<?php

namespace Tests\Feature\Seo;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Tenancy\SupplierProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_homepage_shares_the_platform_logo(): void
    {
        $this->getJson('/seo.json?path=/', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('title', 'المستورد · بضاعتك تبدأ من هون')
            ->assertJsonPath('image', rtrim((string) config('app.frontend_url'), '/').'/brand/logo.png')
            ->assertJsonPath('robots', 'index,follow')
            ->assertJsonPath('json_ld.@graph.0.@type', 'Organization');
    }

    public function test_a_product_link_shares_its_photo_and_name(): void
    {
        $supplier = $this->makeSupplier('النور');
        $category = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
            'image_path' => 'categories/auto.jpg',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $supplier->id,
            'category_id' => $category->id,
            'name' => ['ar' => 'فلتر زيت', 'en' => 'Oil Filter'],
            'description' => ['ar' => 'فلتر للجملة', 'en' => 'Wholesale filter'],
            'slug' => 'oil-filter',
            'sku' => 'OF-1',
            'base_price' => 45000,
            'currency' => 'SYP',
            'price_visible' => true,
            'status' => 'active',
        ]);
        $product->media()->create(['path' => 'products/filter.jpg', 'type' => 'image', 'sort_order' => 0]);

        $this->getJson('/seo.json?path=/products/oil-filter', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('title', 'فلتر زيت · المستورد')
            ->assertJsonPath('description', 'فلتر للجملة')
            ->assertJsonPath('type', 'product')
            ->assertJsonPath('image', asset('storage/products/filter.jpg'));

        $this->getJson('/seo.json?path=/products/oil-filter', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('title', 'Oil Filter · ALMUSTAWRED');
    }

    public function test_a_category_page_uses_the_parent_photo(): void
    {
        $parent = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
            'image_path' => 'categories/auto.jpg',
        ]);
        Category::create([
            'parent_id' => $parent->id,
            'name' => ['ar' => 'فلاتر', 'en' => 'Filters'],
            'slug' => 'filters',
        ]);

        $this->getJson('/seo.json?path='.urlencode('/products?category=filters'), ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('title', 'فلاتر · المستورد')
            ->assertJsonPath('image', asset('storage/categories/auto.jpg'));

        $this->getJson('/seo.json?path='.urlencode('/suppliers?category=auto-parts'))
            ->assertOk()
            ->assertJsonPath('image', asset('storage/categories/auto.jpg'));
    }

    public function test_a_supplier_link_shares_its_logo(): void
    {
        $supplier = $this->makeSupplier('دار الأثاث');
        SupplierProfile::create([
            'tenant_id' => $supplier->id,
            'legal_name' => 'دار الأثاث',
            'display_name' => ['ar' => 'دار الأثاث', 'en' => 'Furniture House'],
            'description' => ['ar' => 'أثاث بالجملة', 'en' => 'Wholesale furniture'],
            'logo_path' => 'logos/furniture.png',
        ]);

        $this->getJson('/seo.json?path=/suppliers/'.$supplier->slug, ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('title', 'دار الأثاث · المستورد')
            ->assertJsonPath('description', 'أثاث بالجملة')
            ->assertJsonPath('image', asset('storage/logos/furniture.png'));
    }

    public function test_private_pages_are_kept_out_of_search_and_the_sitemap_lists_the_catalog(): void
    {
        $supplier = $this->makeSupplier('النور');
        $category = Category::create([
            'name' => ['ar' => 'أثاث', 'en' => 'Furniture'],
            'slug' => 'furniture',
        ]);
        Product::withoutGlobalScopes()->create([
            'tenant_id' => $supplier->id,
            'category_id' => $category->id,
            'name' => ['ar' => 'كرسي', 'en' => 'Chair'],
            'slug' => 'chair',
            'sku' => 'CH-1',
            'base_price' => 100,
            'currency' => 'SYP',
            'status' => 'active',
        ]);

        $this->getJson('/seo.json?path=/account')
            ->assertOk()
            ->assertJsonPath('robots', 'noindex,nofollow');

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/products/chair', $sitemap);
        $this->assertStringContainsString('/suppliers/'.$supplier->slug, $sitemap);
        $this->assertStringContainsString('category=furniture', $sitemap);
        $this->assertStringContainsString('sitemap.xml', $this->get('/robots.txt')->assertOk()->getContent());
    }
}
