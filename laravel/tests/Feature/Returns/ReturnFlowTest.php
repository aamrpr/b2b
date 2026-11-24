<?php

namespace Tests\Feature\Returns;

use App\Models\Returns\ReturnRequest;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

class ReturnFlowTest extends ReturnsTestCase
{
    use RefreshDatabase;

    private function service(): ReturnService
    {
        return app(ReturnService::class);
    }

    private function open(int $quantity, array $extra = []): ReturnRequest
    {
        return $this->service()->request($this->order->fresh()->load('items'), $this->buyerUser, [
            'reason' => 'damaged',
            'items' => [['order_item_id' => $this->line->id, 'quantity' => $quantity]],
            ...$extra,
        ]);
    }

    public function test_a_return_can_only_be_opened_on_a_delivered_order(): void
    {
        $this->order->update(['status' => 'confirmed']);

        $this->expectException(RuntimeException::class);
        $this->open(10);
    }

    public function test_returnable_quantity_shrinks_with_each_request(): void
    {
        $this->assertSame(
            [$this->line->id => 100],
            $this->service()->returnableQuantities($this->order)
        );

        $this->open(30);

        $this->assertSame(
            [$this->line->id => 70],
            $this->service()->returnableQuantities($this->order->fresh()->load('items'))
        );
    }

    public function test_returning_more_than_was_delivered_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->open(101);
    }

    public function test_a_rejected_request_frees_its_quantity_again(): void
    {
        $request = $this->open(40);
        $this->service()->reject($request, $this->supplierUser, 'خارج مدة الإرجاع');

        $this->assertSame(
            [$this->line->id => 100],
            $this->service()->returnableQuantities($this->order->fresh()->load('items')),
            'الكمية المرفوضة يجب أن تعود قابلة للإرجاع'
        );
    }

    public function test_the_supplier_may_approve_fewer_units_than_requested(): void
    {
        $request = $this->open(40);
        $item = $request->items->first();

        $approved = $this->service()->approve($request, $this->supplierUser, [$item->id => 25], 'قبلنا 25 فقط');

        $this->assertSame('approved', $approved->status);
        $this->assertSame(25, $approved->items->first()->approved_quantity);
        $this->assertSame(25 * $this->unitPrice, $approved->approved_amount);
    }

    public function test_approving_more_than_requested_is_capped(): void
    {
        $request = $this->open(10);
        $item = $request->items->first();

        $approved = $this->service()->approve($request, $this->supplierUser, [$item->id => 999]);

        $this->assertSame(10, $approved->items->first()->approved_quantity);
    }

    public function test_the_flow_cannot_skip_receiving(): void
    {
        $request = $this->open(10);

        $this->assertFalse($request->canMoveTo('completed'));

        $this->expectException(RuntimeException::class);
        $this->service()->complete($request);
    }

    public function test_receiving_records_only_what_actually_arrived(): void
    {
        $request = $this->open(40);
        $item = $request->items->first();

        $this->service()->approve($request, $this->supplierUser, [$item->id => 40]);
        $received = $this->service()->receive($request->fresh()->load('items'), $this->supplierUser, [$item->id => 32]);

        $this->assertSame(32, $received->items->first()->received_quantity);
        $this->assertSame(32 * $this->unitPrice, $received->receivedValue());
    }

    public function test_received_quantity_cannot_exceed_what_was_approved(): void
    {
        $request = $this->open(40);
        $item = $request->items->first();

        $this->service()->approve($request, $this->supplierUser, [$item->id => 20]);
        $received = $this->service()->receive($request->fresh()->load('items'), $this->supplierUser, [$item->id => 40]);

        $this->assertSame(20, $received->items->first()->received_quantity);
    }

    public function test_completing_with_nothing_received_is_refused(): void
    {
        $request = $this->open(10);
        $item = $request->items->first();

        $this->service()->approve($request, $this->supplierUser);
        $this->service()->receive($request->fresh()->load('items'), $this->supplierUser, [$item->id => 0]);

        $this->expectException(RuntimeException::class);
        $this->service()->complete($request->fresh()->load('items.orderItem', 'order'));
    }

    public function test_a_rejected_request_is_terminal(): void
    {
        $request = $this->open(10);
        $this->service()->reject($request, $this->supplierUser, 'لا');

        $this->assertSame([], ReturnRequest::FLOW['rejected']);
        $this->assertFalse($request->fresh()->canMoveTo('approved'));
    }
}
