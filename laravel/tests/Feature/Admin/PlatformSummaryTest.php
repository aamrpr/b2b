<?php

namespace Tests\Feature\Admin;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Inventory\Warehouse;
use App\Models\Orders\Order;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ملخّص المنصة يعدّ المنصة كلّها لا دفتر مستأجرٍ فيها.
 *
 * وهذا موضع الزلل: مدير المنصة مستأجرٌ أيضاً، فما كان معزولاً بنطاق
 * المستأجر — المستودعات والفواتير والمخزون — يعود صفراً إن نُسي تجاوز
 * النطاق، فيبدو الرقم صحيحاً وهو كاذب.
 */
class PlatformSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform();
        $this->supplier = $this->makeSupplier('مستورد');

        $category = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
        ]);

        Category::create([
            'parent_id' => $category->id,
            'name' => ['ar' => 'فلاتر', 'en' => 'Filters'],
            'slug' => 'filters',
        ]);

        Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->supplier->id,
            'category_id' => $category->id,
            'name' => ['ar' => 'فلتر زيت', 'en' => 'Oil Filter'],
            'slug' => 'oil-filter',
            'base_price' => 45000,
            'currency' => 'SYP',
            'status' => 'active',
        ]);

        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        Order::create([
            'order_no' => 'PO-1',
            'buyer_tenant_id' => $buyer->id,
            'supplier_tenant_id' => $this->supplier->id,
            'created_by' => User::create([
                'tenant_id' => $buyer->id,
                'name' => 'مشترٍ',
                'email' => 'buyer@test.local',
                'password' => 'password',
            ])->id,
            'subtotal' => 100000,
            'total' => 100000,
            'base_total' => 100000,
            'currency' => 'SYP',
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $this->supplier->id,
            'name' => ['ar' => 'مستودع دمشق', 'en' => 'Damascus Warehouse'],
            'code' => 'WH-1',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function platformUser(): User
    {
        return User::create([
            'tenant_id' => $this->platform()->id,
            'name' => 'مدير المنصة',
            'email' => 'admin@test.local',
            'password' => 'password',
        ]);
    }

    public function test_the_summary_counts_the_whole_platform(): void
    {
        Sanctum::actingAs($this->platformUser());

        $response = $this->getJson('/api/v1/admin/dashboard/summary')->assertOk();

        $response->assertJsonPath('highlights.suppliers', 1)
            ->assertJsonPath('highlights.buyers', 1)
            ->assertJsonPath('highlights.products', 1)
            ->assertJsonPath('highlights.categories', 2)
            ->assertJsonPath('highlights.completed_orders', 1);

        // القمع يصل مرتّباً من الطلب إلى التسليم: الرسم يقرأ ترتيبه لا مفاتيحه
        $this->assertSame(
            ['rfqs', 'quotations', 'orders', 'completed'],
            array_column($response->json('charts.funnel'), 'key'),
        );
        $this->assertSame(1, collect($response->json('charts.funnel'))->firstWhere('key', 'completed')['value']);

        // مستودع المستورد يُعدّ وإن كان المدير في مستأجرٍ آخر
        $inventory = collect($response->json('sections'))->firstWhere('key', 'inventory');
        $warehouses = collect($inventory['items'])->firstWhere('key', 'warehouses');

        $this->assertSame(1, $warehouses['value']);
    }

    public function test_an_importer_cannot_read_the_platform_summary(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->supplier->id,
            'name' => 'مستورد',
            'email' => 'importer@test.local',
            'password' => 'password',
        ]));

        $this->getJson('/api/v1/admin/dashboard/summary')->assertForbidden();
    }
}
