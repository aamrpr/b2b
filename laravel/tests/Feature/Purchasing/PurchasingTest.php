<?php

namespace Tests\Feature\Purchasing;

use App\Exceptions\BusinessRuleException;
use App\Models\Inventory\InventoryItem;
use App\Models\Purchasing\Vendor;
use App\Services\Inventory\InventoryService;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** دورة الشراء بمطابقتها الثلاثية. */
class PurchasingTest extends PurchasingTestCase
{
    use RefreshDatabase;

    public function test_an_order_totals_its_lines_with_tax_and_shipping(): void
    {
        $order = $this->order();

        $this->assertSame($this->quantity * $this->unitCost, $order->subtotal);
        $this->assertSame($this->orderTax, $order->tax_amount);
        $this->assertSame($order->subtotal + $this->orderTax, $order->total);
        $this->assertSame('sent', $order->status);
    }

    public function test_an_empty_order_is_refused(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->service()->createOrder($this->tenant, $this->user, $this->vendor, [], []);
    }

    public function test_receiving_adds_stock_against_an_uninvoiced_liability(): void
    {
        $order = $this->order();
        $item = $order->items->first();

        $receipt = $this->service()->receive($order, $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 60],
        ]);

        $stock = InventoryItem::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertSame(60, $stock->quantity_on_hand);
        $this->assertSame(60 * $this->unitCost, $receipt->total_cost);

        // المخزون دخل مقابل التزام مؤقت لا ذمة دائنة
        $this->assertSame(60 * $this->unitCost, $this->balance('inventory'));
        $this->assertSame(60 * $this->unitCost, $this->balance('goods_received_not_invoiced'));
        $this->assertSame(0, $this->balance('accounts_payable'));

        $this->assertBalanced();
    }

    public function test_partial_receipt_leaves_the_order_partially_received(): void
    {
        $order = $this->order();
        $item = $order->items->first();

        $this->service()->receive($order, $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 40],
        ]);

        $this->assertSame('partially_received', $order->fresh()->status);
        $this->assertSame([$item->id => 60], $order->fresh()->load('items')->pendingQuantities());

        $this->service()->receive($order->fresh()->load('items'), $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 60],
        ]);

        $this->assertSame('received', $order->fresh()->status);
    }

    public function test_receiving_more_than_ordered_is_refused(): void
    {
        $order = $this->order();
        $item = $order->items->first();

        $this->expectException(BusinessRuleException::class);
        $this->service()->receive($order, $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => $this->quantity + 1],
        ]);
    }

    public function test_billing_clears_the_temporary_liability_and_creates_the_payable(): void
    {
        $order = $this->order();
        $item = $order->items->first();

        $this->service()->receive($order, $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 100],
        ]);

        $bill = $this->service()->bill($order->fresh()->load('items'), $this->user, []);

        $net = $this->quantity * $this->unitCost;

        $this->assertSame($net, $bill->subtotal);
        $this->assertSame($this->orderTax, $bill->tax_amount);
        $this->assertSame($net + $this->orderTax, $bill->total);
        $this->assertSame('purchase', $bill->type);

        // الالتزام المؤقت صفر، والذمة الدائنة تحمل القيمة بالضريبة
        $this->assertSame(0, $this->balance('goods_received_not_invoiced'));
        $this->assertSame($net + $this->orderTax, $this->balance('accounts_payable'));
        $this->assertSame($this->orderTax, $this->balance('input_vat'));
        $this->assertSame($net, $this->balance('inventory'), 'الفوترة لا تمسّ المخزون');

        $this->assertBalanced();
    }

    public function test_billing_a_quantity_that_was_never_received_is_refused(): void
    {
        $order = $this->order();
        $item = $order->items->first();

        $this->service()->receive($order, $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 30],
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->service()->bill($order->fresh()->load('items'), $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 50],
        ]);
    }

    public function test_billing_without_any_receipt_is_refused(): void
    {
        $order = $this->order();

        $this->expectException(BusinessRuleException::class);
        $this->service()->bill($order, $this->user, []);
    }

    public function test_two_partial_bills_add_up_to_the_order(): void
    {
        $order = $this->order();
        $item = $order->items->first();

        $this->service()->receive($order, $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 100],
        ]);

        $first = $this->service()->bill($order->fresh()->load('items'), $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 60],
        ]);

        $second = $this->service()->bill($order->fresh()->load('items'), $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 40],
        ]);

        $this->assertSame(60 * $this->unitCost, $first->subtotal);
        $this->assertSame(40 * $this->unitCost, $second->subtotal);

        // الضريبة تُقسَّم بنسبة الكميات فلا تُفقَد ولا تُضاعَف
        $this->assertSame($this->orderTax, $first->tax_amount + $second->tax_amount);
        $this->assertSame(0, $this->balance('goods_received_not_invoiced'));

        $this->assertBalanced();
    }

    public function test_paying_the_vendor_closes_the_payable(): void
    {
        $order = $this->order();
        $item = $order->items->first();

        $this->service()->receive($order, $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 100],
        ]);

        $bill = $this->service()->bill($order->fresh()->load('items'), $this->user, []);

        app(PaymentService::class)->pay(
            $this->tenant,
            $this->user,
            ['amount' => $bill->total, 'method' => 'bank'],
            [['invoice_id' => $bill->id, 'amount' => $bill->total]],
        );

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0, $this->balance('accounts_payable'));
        $this->assertSame(-$bill->total, $this->balance('bank'));

        $this->assertBalanced();
    }

    public function test_the_received_cost_drives_the_inventory_average(): void
    {
        // رصيد سابق بتكلفة مختلفة
        $stock = app(InventoryService::class)->itemFor($this->product);
        app(InventoryService::class)->receive($stock, 100, 20_000, 'opening', null, true, 'capital');

        $order = $this->order();
        $item = $order->items->first();

        $this->service()->receive($order, $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 100],
        ]);

        // (100×20,000 + 100×30,000) ÷ 200 = 25,000
        $this->assertSame(25_000, $stock->fresh()->average_cost);
        $this->assertSame(200, $stock->fresh()->quantity_on_hand);
        $this->assertSame(200 * 25_000, $stock->fresh()->total_value);
    }

    public function test_the_vendor_outstanding_reflects_unpaid_bills(): void
    {
        $order = $this->order();
        $item = $order->items->first();

        $this->service()->receive($order, $this->user, [
            ['purchase_order_item_id' => $item->id, 'quantity' => 100],
        ]);

        $bill = $this->service()->bill($order->fresh()->load('items'), $this->user, []);

        $this->assertSame($bill->total, $this->vendor->fresh()->outstanding());
    }

    public function test_vendors_are_isolated_per_tenant(): void
    {
        $other = $this->makeSupplier('Other');
        $this->actingAsTenant($other);

        $this->assertSame(0, Vendor::count(), 'مورّد مستأجر آخر لا يظهر');

        $this->actingAsTenant($this->tenant);
        $this->assertSame(1, Vendor::count());
    }
}
