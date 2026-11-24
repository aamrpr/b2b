<?php

namespace Tests\Feature\Returns;

use App\Models\Accounting\Invoice;
use App\Models\Inventory\InventoryMovement;
use App\Models\Returns\ReturnRequest;
use App\Models\Tenancy\Tenant;
use App\Services\Accounting\FinancialReportService;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * الأثر المالي للمرتجع. هذه الاختبارات هي التي تحمي الدفتر:
 * رقم خاطئ هنا يمرّ صامتاً ولا يظهر إلا في القوائم المالية.
 */
class ReturnAccountingTest extends ReturnsTestCase
{
    use RefreshDatabase;

    private function service(): ReturnService
    {
        return app(ReturnService::class);
    }

    /** يفتح مرتجعاً ويمضي به حتى الاستلام بكمية محددة. */
    private function receiveReturn(int $quantity, bool $restock = true): ReturnRequest
    {
        $request = $this->service()->request($this->order->fresh()->load('items'), $this->buyerUser, [
            'reason' => 'damaged',
            'restock' => $restock,
            'items' => [['order_item_id' => $this->line->id, 'quantity' => $quantity]],
        ]);

        $this->service()->approve($request, $this->supplierUser);

        return $this->service()->receive($request->fresh()->load('items'), $this->supplierUser);
    }

    public function test_the_credit_note_is_valued_on_what_was_received(): void
    {
        $request = $this->receiveReturn(20);
        $note = $this->service()->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $this->assertSame(20 * $this->unitPrice, $note->subtotal);
        $this->assertSame(20 * $this->unitPrice, $note->total, 'لا ضريبة على الفاتورة الأصلية');
        $this->assertSame('issued', $note->status);
        $this->assertNotNull($note->journal_entry_id);
        $this->assertCount(1, $note->items);
    }

    public function test_the_credit_note_entry_debits_returns_and_credits_the_customer(): void
    {
        $request = $this->receiveReturn(20);
        $this->service()->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $returns = $this->accountBalance($this->supplier, 'sales_returns');
        $receivable = $this->accountBalance($this->supplier, 'accounts_receivable');

        $this->assertSame(20 * $this->unitPrice, $returns);

        // الذمة = الفاتورة الأصلية ناقص المرتجع
        $this->assertSame(
            ($this->quantity - 20) * $this->unitPrice,
            $receivable
        );
    }

    public function test_received_goods_return_to_stock_and_reverse_their_cost(): void
    {
        $beforeStock = $this->stock->fresh()->quantity_on_hand;
        $beforeCogs = $this->accountBalance($this->supplier, 'cogs');

        $request = $this->receiveReturn(20);
        $this->service()->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $this->assertSame($beforeStock + 20, $this->stock->fresh()->quantity_on_hand);
        $this->assertSame($beforeCogs - 20 * $this->unitCost, $this->accountBalance($this->supplier, 'cogs'));

        $movement = InventoryMovement::withoutGlobalScope('tenant')
            ->where('source_type', ReturnRequest::class)
            ->where('type', 'return_in')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(20, $movement->quantity);
    }

    public function test_a_return_without_restock_leaves_stock_and_cost_untouched(): void
    {
        $beforeStock = $this->stock->fresh()->quantity_on_hand;
        $beforeCogs = $this->accountBalance($this->supplier, 'cogs');

        $request = $this->receiveReturn(20, restock: false);
        $this->service()->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $this->assertSame($beforeStock, $this->stock->fresh()->quantity_on_hand, 'البضاعة التالفة لا تعود للمخزون');
        $this->assertSame($beforeCogs, $this->accountBalance($this->supplier, 'cogs'), 'تكلفتها تبقى مصروفاً');
    }

    public function test_the_commission_is_reversed_in_proportion_to_the_refund(): void
    {
        $commission = $this->order->commission;
        $this->assertNotNull($commission);

        $originalAmount = $commission->amount;
        $platform = Tenant::platform();

        // 20 من 100 = خُمس الصفقة
        $request = $this->receiveReturn(20);
        $this->service()->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $expectedReversal = (int) round($originalAmount * 0.2);

        $this->assertSame(
            $originalAmount - $expectedReversal,
            $this->accountBalance($platform, 'commission_revenue')
        );

        $this->assertSame(
            $originalAmount - $expectedReversal,
            $this->accountBalance($this->supplier, 'platform_commission')
        );

        // `amount` يبقى العمولة الأصلية، والمعكوس يُتتبَّع مستقلاً
        $this->assertSame($originalAmount, $commission->fresh()->amount);
        $this->assertSame($expectedReversal, $commission->fresh()->reversed_amount);
        $this->assertSame($originalAmount - $expectedReversal, $commission->fresh()->netAmount());
    }

    public function test_returning_everything_wipes_the_commission_out(): void
    {
        $platform = Tenant::platform();

        $request = $this->receiveReturn($this->quantity);
        $this->service()->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $this->assertSame(0, $this->accountBalance($platform, 'commission_revenue'));
        $this->assertSame(0, $this->accountBalance($this->supplier, 'platform_commission'));
        $this->assertSame(0, $this->order->commission->fresh()->netAmount());
        $this->assertSame('reversed', $this->order->commission->fresh()->status);
    }

    public function test_the_invoice_balance_drops_by_the_credit_note(): void
    {
        $invoice = Invoice::withoutGlobalScope('tenant')->where('order_id', $this->order->id)->first();
        $before = $invoice->balance_due;

        $request = $this->receiveReturn(20);
        $note = $this->service()->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $this->assertSame($before - $note->total, $invoice->fresh()->balance_due);
    }

    public function test_two_partial_returns_add_up_without_double_counting(): void
    {
        $platform = Tenant::platform();
        $originalCommission = $this->order->commission->amount;

        $first = $this->receiveReturn(20);
        $this->service()->complete($first->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $second = $this->receiveReturn(30);
        $this->service()->complete($second->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $this->assertSame(50 * $this->unitPrice, $this->accountBalance($this->supplier, 'sales_returns'));
        $this->assertSame(
            ($this->quantity - 50) * $this->unitPrice,
            $this->accountBalance($this->supplier, 'accounts_receivable')
        );

        // نصف العمولة عُكس — والباقي سليم
        $this->assertSame(
            $originalCommission - (int) round($originalCommission * 0.5),
            $this->accountBalance($platform, 'commission_revenue')
        );
    }

    public function test_both_ledgers_stay_balanced_after_a_return(): void
    {
        $request = $this->receiveReturn(35);
        $this->service()->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $this->assertLedgersBalanced();
    }

    public function test_the_supplier_profit_reflects_the_return(): void
    {
        $request = $this->receiveReturn(20);
        $this->service()->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        $statement = app(FinancialReportService::class)->incomeStatement(
            $this->supplier,
            Carbon::today()->startOfYear(),
            Carbon::today(),
        );

        $soldQuantity = $this->quantity - 20;
        $commission = $this->order->commission->fresh()->netAmount();

        // صافي المبيعات = المبيعات − المردودات، والتكلفة على المباع فعلاً
        $this->assertSame($soldQuantity * $this->unitPrice, $statement['revenue']['total']);
        $this->assertSame($soldQuantity * $this->unitCost, $statement['cost_of_sales']['total']);
        $this->assertSame(
            $soldQuantity * ($this->unitPrice - $this->unitCost) - $commission,
            $statement['net_profit'],
        );
    }
}
