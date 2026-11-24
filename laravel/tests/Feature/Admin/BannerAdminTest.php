<?php

namespace Tests\Feature\Admin;

use App\Models\Catalog\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بنرات الرئيسية يديرها مستخدم المنصة وحده. الرابط مسار داخلي،
 * والمعطَّل لا يصل إلى الزائر.
 */
class BannerAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_platform_admin_publishes_a_banner_and_a_visitor_sees_it(): void
    {
        Storage::fake('public');
        $admin = $this->platformAdmin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/banners', [
                'eyebrow' => ['ar' => 'عرض', 'en' => 'Offer'],
                'title' => ['ar' => 'عنوان البنر', 'en' => 'Banner title'],
                'text' => ['ar' => 'وصف', 'en' => 'Description'],
                'href' => '/products?category=auto-parts',
                'sort_order' => 1,
                'is_active' => 1,
                'image' => UploadedFile::fake()->image('banner.jpg'),
            ])
            ->assertCreated();

        $this->getJson('/api/v1/banners', ['X-Locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.0.title', 'عنوان البنر')
            ->assertJsonPath('data.0.href', '/products?category=auto-parts');

        $this->getJson('/api/v1/banners', ['X-Locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Banner title');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/banners/'.$created->json('id'), ['is_active' => false])
            ->assertOk();

        $this->getJson('/api/v1/banners')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_external_link_is_rejected_and_other_tenants_cannot_edit(): void
    {
        Storage::fake('public');
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/banners', [
                'title' => ['ar' => 'خارج'],
                'href' => 'https://example.com',
                'image' => UploadedFile::fake()->image('banner.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('href');

        $supplier = $this->makeSupplier();
        $supplierUser = User::create([
            'tenant_id' => $supplier->id,
            'name' => 'مستورد',
            'email' => 'supplier-banner@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($supplierUser, 'sanctum')
            ->getJson('/api/v1/admin/banners')
            ->assertForbidden();

        $this->assertSame(0, Banner::query()->count());
    }

    public function test_deleting_a_banner_removes_its_picture(): void
    {
        Storage::fake('public');
        $admin = $this->platformAdmin();

        $id = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/banners', [
                'title' => ['ar' => 'يُحذف'],
                'href' => '/products',
                'image' => UploadedFile::fake()->image('banner.jpg'),
            ])
            ->assertCreated()
            ->json('id');

        $path = Banner::query()->findOrFail($id)->image_path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/admin/banners/'.$id)
            ->assertOk();

        Storage::disk('public')->assertMissing($path);
        $this->assertNull(Banner::query()->find($id));
    }

    private function platformAdmin(): User
    {
        return User::create([
            'tenant_id' => $this->platform()->id,
            'name' => 'مدير المنصة',
            'email' => 'banner-admin@test.local',
            'password' => 'password',
        ]);
    }
}
