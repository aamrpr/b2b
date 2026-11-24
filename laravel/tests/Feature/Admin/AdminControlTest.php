<?php

namespace Tests\Feature\Admin;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Orders\Order;
use App\Models\Rfq\Quotation;
use App\Models\Rfq\Rfq;
use App\Models\Support\AuditLog;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * تحكّم المنصة بما فيها.
 *
 * الصلاحية الواسعة تحتاج حدّين: أثرٌ يُسجَّل باسم فاعله — فصلاحيةٌ بلا
 * أثر بابُ تعسّف — ومنعُ المدير من تطبيقها على نفسه، فمن أوقف حسابه
 * أغلق الباب وأبقى المفتاح في الداخل.
 */
class AdminControlTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $platform = $this->platform();
        $this->supplier = $this->makeSupplier('مستورد');

        $this->admin = User::create([
            'tenant_id' => $platform->id,
            'name' => 'مدير المنصة',
            'email' => 'admin@test.local',
            'password' => 'password',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
        ]);

        $this->product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->supplier->id,
            'category_id' => $category->id,
            'name' => ['ar' => 'فلتر زيت', 'en' => 'Oil Filter'],
            'slug' => 'oil-filter',
            'sku' => 'OF-1',
            'base_price' => 45000,
            'currency' => 'SYP',
            'status' => 'active',
        ]);
    }

    private function supplierUser(string $email = 'importer@test.local'): User
    {
        return User::create([
            'tenant_id' => $this->supplier->id,
            'name' => 'مستورد',
            'email' => $email,
            'password' => 'password',
            'status' => 'active',
        ]);
    }

    public function test_the_platform_sees_every_importers_products(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/products')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.sku', 'OF-1');
    }

    public function test_an_importer_cannot_reach_the_platform_catalog(): void
    {
        Sanctum::actingAs($this->supplierUser());

        $this->getJson('/api/v1/admin/products')->assertForbidden();
    }

    public function test_the_platform_pulls_a_product_from_the_market_and_the_act_is_logged(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/admin/products/{$this->product->id}", [
            'status' => 'archived',
            'reason' => 'وصف مضلّل',
        ])->assertOk();

        $this->assertSame('archived', $this->product->fresh()->status);

        $log = AuditLog::where('action', 'product.status_changed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('active', $log->old_values['status']);
        $this->assertSame('وصف مضلّل', $log->new_values['reason']);
    }

    public function test_deleting_a_product_is_soft_so_old_orders_keep_their_reference(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/v1/admin/products/{$this->product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $this->product->id]);
    }

    public function test_suspending_a_user_ends_their_sessions(): void
    {
        $user = $this->supplierUser();
        $token = $user->createToken('api')->accessToken;

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/admin/users/{$user->id}", ['status' => 'suspended'])->assertOk();

        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_an_admin_cannot_suspend_their_own_account(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/admin/users/{$this->admin->id}", ['status' => 'suspended'])
            ->assertStatus(422);

        $this->assertSame('active', $this->admin->fresh()->status);
    }

    public function test_the_platform_resets_a_password_without_recording_it(): void
    {
        $user = $this->supplierUser();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/users/{$user->id}/password", [
            'password' => 'fresh-password',
            'password_confirmation' => 'fresh-password',
        ])->assertOk();

        $this->assertTrue(\Hash::check('fresh-password', $user->fresh()->password));

        $log = AuditLog::where('action', 'user.password_reset')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame([], array_intersect(['password', 'fresh-password'], (array) $log->new_values));
    }

    public function test_the_platform_edits_the_category_tree(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/categories', [
            'name' => ['ar' => 'أدوات كهربائية', 'en' => 'Power Tools'],
            'is_active' => true,
        ])->assertCreated();

        $created = Category::where('slug', 'power-tools')->sole();

        $this->putJson("/api/v1/admin/categories/{$created->id}", [
            'name' => ['ar' => 'عدد كهربائية'],
            'is_active' => false,
        ])->assertOk();

        $created->refresh();

        $this->assertSame('عدد كهربائية', $created->t('name', 'ar'));
        // is_active=false يجب أن يُكتب: المرشّحات التي تُسقط الفارغ تبتلع false
        $this->assertFalse((bool) $created->is_active);

        $this->deleteJson("/api/v1/admin/categories/{$created->id}")->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $created->id]);
    }

    public function test_a_category_that_still_holds_products_is_not_deleted(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/v1/admin/categories/{$this->product->category_id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('categories', ['id' => $this->product->category_id]);
    }

    public function test_the_platform_corrects_an_order_status_through_the_domain_service(): void
    {
        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $order = Order::create([
            'order_no' => 'PO-ADMIN-1',
            'buyer_tenant_id' => $buyer->id,
            'supplier_tenant_id' => $this->supplier->id,
            'created_by' => $this->supplierUser('creator@test.local')->id,
            'subtotal' => 100000,
            'total' => 100000,
            'base_total' => 100000,
            'currency' => 'SYP',
            'status' => 'confirmed',
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'cancelled',
            'note' => 'طلب مكرّر',
        ])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);

        $log = AuditLog::where('action', 'order.status_changed')->latest('id')->first();

        $this->assertSame('confirmed', $log->old_values['status']);
        $this->assertSame('طلب مكرّر', $log->new_values['note']);
    }

    public function test_the_platform_reads_a_quotation_it_is_not_party_to(): void
    {
        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $buyerUser = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'buyer@test.local',
            'password' => 'password',
        ]);

        $rfq = Rfq::create([
            'buyer_tenant_id' => $buyer->id,
            'created_by' => $buyerUser->id,
            'reference_no' => 'RFQ-1',
            'title' => 'مطلوب فلاتر',
            'category_id' => $this->product->category_id,
            'currency' => 'SYP',
            'delivery_country' => 'SY',
            'expires_at' => now()->addHours(72),
            'visibility' => 'public',
            'status' => 'open',
        ]);

        $quotation = Quotation::create([
            'rfq_id' => $rfq->id,
            'supplier_tenant_id' => $this->supplier->id,
            'buyer_tenant_id' => $buyer->id,
            'created_by' => $this->supplierUser()->id,
            'quotation_no' => 'QT-2026-00001',
            'subtotal' => 100000,
            'total' => 100000,
            'currency' => 'SYP',
            'valid_until' => now()->addDay(),
            'status' => 'sent',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('data.quotation_no', 'QT-2026-00001');

        $this->getJson('/api/v1/admin/quotations')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.supplier', $this->supplier->name);
    }
}
