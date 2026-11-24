<?php

namespace Tests\Feature\Inventory;

use App\Models\Accounting\Account;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\Warehouse;
use App\Models\Tenancy\Tenant;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
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
            'name' => ['ar' => 'فلتر زيت', 'en' => 'Oil Filter'],
            'slug' => 'oil-filter-'.Str::lower(Str::random(6)),
            'unit_of_measure' => 'piece',
            'base_price' => 50_000,
            'currency' => 'SYP',
            'status' => 'active',
        ]);
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }

    private function item(Tenant $tenant): InventoryItem
    {
        return app(InventoryService::class)->itemFor($this->product($tenant));
    }

    public function test_weighted_average_cost_moves_with_each_receipt(): void
    {
        $tenant = $this->makeSupplier();
        $this->actingAsTenant($tenant);

        $service = app(InventoryService::class);
        $item = $this->item($tenant);

        $service->receive($item, 100, 30_000);
        $this->assertSame(30_000, $item->fresh()->average_cost);

        $service->receive($item, 100, 40_000);
        $this->assertSame(35_000, $item->fresh()->average_cost);

        // دفعة صغيرة بسعر مرتفع تحرّك المتوسط بقدر وزنها فقط
        $service->receive($item, 50, 50_000);
        $this->assertSame(38_000, $item->fresh()->average_cost);
        $this->assertSame(250, $item->fresh()->quantity_on_hand);
    }

    public function test_issuing_uses_the_average_cost_and_reduces_stock(): void
    {
        $tenant = $this->makeSupplier();
        $this->actingAsTenant($tenant);

        $service = app(InventoryService::class);
        $item = $this->item($tenant);

        $service->receive($item, 100, 30_000);
        $service->receive($item, 100, 40_000);

        $movement = $service->issue($item, 120);

        $this->assertSame(-120, $movement->quantity);
        $this->assertSame(-(120 * 35_000), $movement->total_cost);
        $this->assertSame(80, $item->fresh()->quantity_on_hand);
        $this->assertSame(35_000, $item->fresh()->average_cost, 'الإخراج لا يغيّر المتوسط');
    }

    public function test_fifo_consumes_the_oldest_layers_first(): void
    {
        $tenant = $this->makeSupplier();
        $tenant->update(['costing_method' => 'fifo']);
        $this->actingAsTenant($tenant);

        $service = app(InventoryService::class);
        $item = $this->item($tenant);

        $service->receive($item, 100, 30_000);
        $service->receive($item, 100, 40_000);

        // 120 قطعة = 100 بـ 30,000 + 20 بـ 40,000
        $movement = $service->issue($item->fresh(), 120);

        $this->assertSame(-(100 * 30_000 + 20 * 40_000), $movement->total_cost);
    }

    public function test_issuing_more_than_available_is_refused(): void
    {
        $tenant = $this->makeSupplier();
        $this->actingAsTenant($tenant);

        $service = app(InventoryService::class);
        $item = $this->item($tenant);

        $service->receive($item, 10, 1_000);

        $this->expectException(RuntimeException::class);
        $service->issue($item->fresh(), 11);
    }

    public function test_the_stored_balance_always_matches_the_movements(): void
    {
        $tenant = $this->makeSupplier();
        $this->actingAsTenant($tenant);

        $service = app(InventoryService::class);
        $item = $this->item($tenant);

        $service->receive($item, 100, 10_000);
        $service->issue($item->fresh(), 30);
        $service->receive($item->fresh(), 50, 12_000);
        $service->issue($item->fresh(), 20);

        $fresh = $item->fresh();

        $this->assertSame(100, $fresh->quantity_on_hand);
        $this->assertSame($fresh->quantity_on_hand, $fresh->derivedQuantity());
    }

    public function test_a_shortage_adjustment_posts_an_expense_and_credits_inventory(): void
    {
        $tenant = $this->makeSupplier();
        $this->actingAsTenant($tenant);

        $service = app(InventoryService::class);
        $item = $this->item($tenant);

        $service->receive($item, 100, 20_000, 'purchase_in', null, true);

        $service->adjust($item->fresh(), 95, 'عجز جرد');

        $inventory = Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)->where('system_key', 'inventory')->first();
        $expense = Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)->where('system_key', 'operating_expense')->first();

        $this->assertSame(95 * 20_000, $inventory->balance());
        $this->assertSame(5 * 20_000, $expense->balance());
    }

    public function test_inventory_valuation_matches_the_ledger_account(): void
    {
        $tenant = $this->makeSupplier();
        $this->actingAsTenant($tenant);

        $service = app(InventoryService::class);
        $item = $this->item($tenant);

        $service->receive($item, 100, 25_000, 'purchase_in', null, true);
        $service->receive($item->fresh(), 40, 30_000, 'purchase_in', null, true);

        $fresh = $item->fresh();

        $ledger = Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)->where('system_key', 'inventory')->first()->balance();

        // القيمة المخزَّنة هي مجموع التكاليف الفعلي — تطابق الأستاذ تماماً
        $this->assertSame($ledger, $fresh->total_value);
        $this->assertSame(100 * 25_000 + 40 * 30_000, $fresh->total_value);

        // بينما (الكمية × المتوسط المقرَّب) تنحرف — وهذا سبب تخزين القيمة
        $this->assertNotSame($ledger, $fresh->quantity_on_hand * $fresh->average_cost);
    }

    public function test_a_movement_cannot_be_edited_or_deleted(): void
    {
        $tenant = $this->makeSupplier();
        $this->actingAsTenant($tenant);

        $movement = app(InventoryService::class)->receive($this->item($tenant), 10, 5_000);

        try {
            $movement->update(['quantity' => 999]);
            $this->fail('تعديل حركة مخزون كان يجب أن يفشل.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->expectException(RuntimeException::class);
        $movement->delete();
    }

    public function test_transfer_moves_quantity_between_warehouses_without_changing_total(): void
    {
        $tenant = $this->makeSupplier();
        $this->actingAsTenant($tenant);

        $service = app(InventoryService::class);
        $item = $this->item($tenant);
        $service->receive($item, 100, 20_000);

        $second = Warehouse::create([
            'name' => ['ar' => 'مستودع حلب', 'en' => 'Aleppo'],
            'code' => 'ALP',
        ]);

        $service->transfer($item->fresh(), $second, 30);

        $total = (int) InventoryItem::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->sum('quantity_on_hand');

        $this->assertSame(100, $total);
        $this->assertSame(70, $item->fresh()->quantity_on_hand);
    }
}
