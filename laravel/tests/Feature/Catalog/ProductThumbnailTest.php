<?php

namespace Tests\Feature\Catalog;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Support\Media\ImageThumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * بطاقة السوق لا تحتاج الأصل. الرفع يبني مصغّراً، والحذف يأخذه معه،
 * وصفحة المنتج تبقي رابط الأصل للتكبير.
 */
class ProductThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->platform();
        $this->supplier = $this->makeSupplier('مستورد');

        Category::create([
            'name' => ['ar' => 'قطع', 'en' => 'Parts'],
            'slug' => 'parts',
        ]);
    }

    public function test_uploading_a_product_image_builds_a_card_thumbnail_and_keeps_the_original(): void
    {
        $owner = User::create([
            'tenant_id' => $this->supplier->id,
            'name' => 'مدير',
            'email' => 'owner@test.local',
            'password' => 'password',
            'status' => 'active',
        ]);

        Sanctum::actingAs($owner);

        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->supplier->id,
            'category_id' => Category::first()->id,
            'name' => ['ar' => 'بطارية', 'en' => 'Battery'],
            'slug' => 'battery-thumb',
            'base_price' => 1000,
            'currency' => 'SYP',
            'status' => 'active',
        ]);

        $upload = $this->post('/api/v1/my/products/'.$product->id.'/images', [
            'images' => [UploadedFile::fake()->image('battery.png', 1200, 800)],
        ])->assertCreated();

        $path = $product->media()->first()->path;
        $thumb = ImageThumbnail::relative($path);
        Storage::disk('public')->assertExists($thumb);

        $size = getimagesize(Storage::disk('public')->path($thumb));
        $this->assertSame(IMAGETYPE_WEBP, $size[2]);
        $this->assertSame(640, max($size[0], $size[1]));

        $shown = $this->getJson('/api/v1/products/battery-thumb')->assertOk();
        $this->assertSame(basename($path), basename($shown->json('data.cover_url')));
        $this->assertSame(basename($thumb), basename($shown->json('data.cover_thumb_url')));
        $this->assertSame(basename($thumb), basename($upload->json('images.0.thumb_url')));

        $this->deleteJson('/api/v1/my/products/'.$product->id.'/images/'.$upload->json('images.0.id'))
            ->assertOk();

        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing($thumb);
    }

    public function test_uploading_a_category_image_builds_a_card_thumbnail(): void
    {
        $admin = User::create([
            'tenant_id' => $this->platform()->id,
            'name' => 'مدير المنصة',
            'email' => 'cat-admin@test.local',
            'password' => 'password',
            'status' => 'active',
        ]);

        $category = Category::where('slug', 'parts')->firstOrFail();

        $upload = $this->actingAs($admin, 'sanctum')->post('/api/v1/admin/categories/'.$category->id.'/image', [
            'image' => UploadedFile::fake()->image('parts.png', 1000, 800),
        ])->assertOk();

        $category->refresh();
        $thumb = ImageThumbnail::relative($category->image_path);
        Storage::disk('public')->assertExists($thumb);

        $size = getimagesize(Storage::disk('public')->path($thumb));
        $this->assertSame(640, max($size[0], $size[1]));

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.image_thumb_url', $upload->json('image_thumb_url'));
    }
}
