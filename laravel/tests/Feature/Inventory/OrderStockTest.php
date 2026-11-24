<?php

namespace Tests\Feature\Inventory;

use App\Models\Accounting\Account;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Orders\Order;
use App\Models\Orders\OrderItem;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Accounting\Posting\SalesPostingService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OrderStockService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrderStockTest extends TestCase
{
    use RefreshDatabase;

    private function product(Tenant $tenant): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'auto-parts'],
            ['name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts']]
        );

        $product = new Product([
            'category_id' => $category->id,
            'name' => ['ar' => 'فلتر', 'en' => 'Filter'],
            'slug' => 'filter-'.Str::lower(Str::random(6)),
            'base_price' => 50_000,
            'currency' => 'SYP',
            'status' => 'active',
        ]);
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }

    private function order(Tenant $supplier, Product $product, int $quantity, int $unitPrice, int $manualCost = 0): Order
    {
        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $user = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'b'.$buyer->id.'@test.local',
            'password' => 'password',
        ]);

        $order = Order::create([
            'order_no' => 'PO-'.Str::upper(Str::random(6)),
            'buyer_tenant_id' => $buyer->id,
            'supplier_tenant_id' => $supplier->id,
            'created_by' => $user->id,
            'subtotal' => $quantity * $unitPrice,
            'total' => $quantity * $unitPrice,
            'base_total' => $quantity * $unitPrice,
            'currency' => 'SYP',
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'description' => 'فلتر',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost' => $manualCost,
            'line_total' => $quantity * $unitPrice,
        ]);

        return $order->load('items', 'supplier', 'buyer');
    }

    public function test_cogs_uses_the_actual_issue_cost_when_stock_is_tracked(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $product = $this->product($supplier);
        $item = app(InventoryService::class)->itemFor($product);

        app(InventoryService::class)->receive($item, 100, 30_000, 'purchase_in', null, true);
        app(InventoryService::class)->receive($item->fresh(), 100, 40_000, 'purchase_in', null, true);

        // التكلفة اليدوية على السطر خاطئة عمداً — يجب أن يتجاهلها النظام
        $order = $this->order($supplier, $product, 120, 55_000, manualCost: 1);

        app(SalesPostingService::class)->postCostOfGoodsSold($order);

        $cogs = Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $supplier->id)->where('system_key', 'cogs')->first();

        $this->assertSame(120 * 35_000, $cogs->balance());
        $this->assertSame(80, $item->fresh()->quantity_on_hand);
        $this->assertSame(35_000, $order->items()->first()->unit_cost);
    }

    public function test_cogs_falls_back_to_the_line_cost_when_stock_is_not_tracked(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $product = $this->product($supplier);
        $order = $this->order($supplier, $product, 10, 50_000, manualCost: 30_000);

        app(SalesPostingService::class)->postCostOfGoodsSold($order);

        $cogs = Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $supplier->id)->where('system_key', 'cogs')->first();

        $this->assertSame(10 * 30_000, $cogs->balance());
    }

    public function test_delivering_more_than_available_is_refused(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $product = $this->product($supplier);
        $item = app(InventoryService::class)->itemFor($product);
        app(InventoryService::class)->receive($item, 10, 20_000);

        $order = $this->order($supplier, $product, 50, 60_000);

        $this->assertNotEmpty(app(OrderStockService::class)->shortages($order));

        $this->expectException(RuntimeException::class);
        app(OrderStockService::class)->issueForOrder($order);
    }

    public function test_the_same_order_never_issues_stock_twice(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $product = $this->product($supplier);
        $item = app(InventoryService::class)->itemFor($product);
        app(InventoryService::class)->receive($item, 100, 20_000);

        $order = $this->order($supplier, $product, 30, 50_000);

        $service = app(OrderStockService::class);
        $first = $service->issueForOrder($order);
        $second = $service->issueForOrder($order->fresh()->load('items'));

        $this->assertSame($first, $second);
        $this->assertSame(70, $item->fresh()->quantity_on_hand);
    }
}
