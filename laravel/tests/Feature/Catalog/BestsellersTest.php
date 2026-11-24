<?php

namespace Tests\Feature\Catalog;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Orders\Order;
use App\Models\Orders\OrderItem;
use App\Models\Returns\ReturnRequest;
use App\Models\Returns\ReturnRequestItem;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الرئيسية تعرض أكثر عشرة منتجات مبيعاً. الكمية من الطلب القائم،
 * والمرتجع المستلم يُنقصها، والمسودّة والملغى لا يُحسبان.
 */
class BestsellersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $supplier;

    private Tenant $buyer;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform();
        $this->supplier = $this->makeSupplier('مستورد');
        $this->buyer = app(\App\Services\Tenancy\TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);
        $this->user = User::create([
            'tenant_id' => $this->buyer->id,
            'name' => 'مشترٍ',
            'email' => 'buyer-'.Str::lower(Str::random(6)).'@test.local',
            'password' => 'password',
        ]);
        $this->category = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
        ]);
    }

    public function test_the_homepage_ranks_products_by_net_sales(): void
    {
        $steady = $this->product('steady', 'فلتر');
        $returned = $this->product('returned', 'بطارية');
        $cancelled = $this->product('cancelled', 'بوجيه');
        $draft = $this->product('draft', 'فحمات');
        $archived = $this->product('archived', 'كرسي', 'archived');

        $this->sell($steady, 5, 'delivered');
        $line = $this->sell($returned, 9, 'delivered');
        $this->sell($cancelled, 20, 'cancelled');
        $this->sell($draft, 8, 'draft');
        $this->sell($archived, 30, 'delivered');

        $request = ReturnRequest::create([
            'order_id' => $line->order_id,
            'buyer_tenant_id' => $this->buyer->id,
            'supplier_tenant_id' => $this->supplier->id,
            'reference_no' => 'RET-'.Str::upper(Str::random(4)),
            'type' => 'refund',
            'reason' => 'damaged',
            'status' => 'completed',
            'currency' => 'SYP',
            'created_by' => $this->user->id,
        ]);

        ReturnRequestItem::create([
            'return_request_id' => $request->id,
            'order_item_id' => $line->id,
            'quantity' => 7,
            'approved_quantity' => 7,
            'received_quantity' => 7,
            'unit_price' => 1000,
            'line_total' => 7000,
        ]);

        $names = $this->getJson('/api/v1/products/bestsellers', ['X-Locale' => 'ar'])
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['فلتر', 'بطارية'], $names);
    }

    public function test_a_tie_goes_to_the_more_recent_sale_and_the_list_stops_at_ten(): void
    {
        $older = $this->product('older', 'الأقدم');
        $newer = $this->product('newer', 'الأحدث');
        $this->sell($older, 4, 'delivered', now()->subDay());
        $this->sell($newer, 4, 'delivered', now());

        foreach (range(1, 10) as $index) {
            $this->sell($this->product('extra-'.$index, 'إضافي '.$index), 1, 'confirmed');
        }

        $names = $this->getJson('/api/v1/products/bestsellers', ['X-Locale' => 'ar'])
            ->assertOk()
            ->json('data.*.name');

        $this->assertCount(10, $names);
        $this->assertSame(['الأحدث', 'الأقدم'], array_slice($names, 0, 2));
    }

    private function product(string $slug, string $name, string $status = 'active'): Product
    {
        return Product::query()->create([
            'tenant_id' => $this->supplier->id,
            'category_id' => $this->category->id,
            'name' => ['ar' => $name, 'en' => $name],
            'slug' => $slug,
            'sku' => strtoupper($slug),
            'base_price' => 1000,
            'currency' => 'SYP',
            'status' => $status,
        ]);
    }

    private function sell(Product $product, int $quantity, string $status, mixed $when = null): OrderItem
    {
        $when ??= now();

        $order = Order::create([
            'order_no' => 'PO-'.Str::upper(Str::random(8)),
            'buyer_tenant_id' => $this->buyer->id,
            'supplier_tenant_id' => $this->supplier->id,
            'created_by' => $this->user->id,
            'subtotal' => $quantity * 1000,
            'total' => $quantity * 1000,
            'base_total' => $quantity * 1000,
            'currency' => 'SYP',
            'status' => $status,
            'confirmed_at' => in_array($status, ['draft', 'awaiting_approval', 'cancelled'], true) ? null : $when,
            'delivered_at' => $status === 'delivered' ? $when : null,
            'created_at' => $when,
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'description' => $product->t('name'),
            'quantity' => $quantity,
            'unit_price' => 1000,
            'line_total' => $quantity * 1000,
        ]);
    }
}
