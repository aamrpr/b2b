<?php

namespace Tests\Feature\Payments;

use App\Models\Accounting\CreditNote;
use App\Models\Accounting\Invoice;
use App\Services\Payments\CreditNoteService;
use App\Services\Payments\PaymentService;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Returns\ReturnsTestCase;

/**
 * تطبيق إشعار الائتمان على الفواتير.
 * الإشعار أسقط الذمة في الدفتر عند إصداره، فالتطبيق توزيع للرصيد
 * بين الفواتير — لا قيد جديد، وإلا خُصم المبلغ مرتين.
 */
class CreditNoteApplicationTest extends ReturnsTestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invoice = Invoice::withoutGlobalScope('tenant')
            ->where('order_id', $this->order->id)
            ->firstOrFail();
    }

    private function service(): CreditNoteService
    {
        return app(CreditNoteService::class);
    }

    /** يُنشئ إشعاراً عبر دورة مرتجع كاملة. */
    private function creditNoteFor(int $quantity): CreditNote
    {
        $returns = app(ReturnService::class);

        $request = $returns->request($this->order->fresh()->load('items'), $this->buyerUser, [
            'reason' => 'damaged',
            'items' => [['order_item_id' => $this->line->id, 'quantity' => $quantity]],
        ]);

        $returns->approve($request, $this->supplierUser);
        $returns->receive($request->fresh()->load('items'), $this->supplierUser);

        return $returns->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));
    }

    public function test_a_credit_note_is_applied_to_its_own_invoice_on_issue(): void
    {
        $note = $this->creditNoteFor(10);

        $this->assertSame($note->total, $note->applied_amount);
        $this->assertSame(0, $note->unappliedAmount());
        $this->assertSame($this->invoice->total - $note->total, $this->invoice->fresh()->balance_due);
    }

    public function test_applying_does_not_touch_the_ledger_twice(): void
    {
        $receivableBefore = $this->accountBalance($this->supplier, 'accounts_receivable');

        $note = $this->creditNoteFor(10);

        // الذمة نقصت مرة واحدة فقط — بقيمة الإشعار لا ضعفها
        $this->assertSame(
            $receivableBefore - $note->total,
            $this->accountBalance($this->supplier, 'accounts_receivable')
        );

        $this->assertLedgersBalanced();
    }

    public function test_credit_beyond_the_invoice_balance_stays_open_for_later(): void
    {
        // تُسدَّد الفاتورة أولاً فلا يبقى لها رصيد يستوعب الإشعار
        app(PaymentService::class)->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => $this->invoice->total, 'method' => 'cash', 'contact' => $this->buyerTenant],
            [['invoice_id' => $this->invoice->id, 'amount' => $this->invoice->total]],
        );

        $note = $this->creditNoteFor(10);

        $this->assertSame(0, $note->applied_amount, 'لا رصيد في الفاتورة ليُطبَّق عليه');
        $this->assertSame($note->total, $note->unappliedAmount());
        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    public function test_an_open_credit_can_be_applied_to_a_later_invoice(): void
    {
        app(PaymentService::class)->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => $this->invoice->total, 'method' => 'cash', 'contact' => $this->buyerTenant],
            [['invoice_id' => $this->invoice->id, 'amount' => $this->invoice->total]],
        );

        $note = $this->creditNoteFor(10);
        $available = $note->unappliedAmount();

        $next = new Invoice([
            'invoice_no' => 'INV-NEXT',
            'type' => 'sales',
            'contact_type' => $this->buyerTenant::class,
            'contact_id' => $this->buyerTenant->id,
            'issue_date' => today(),
            'due_date' => today()->addDays(30),
            'subtotal' => 2_000_000,
            'total' => 2_000_000,
            'balance_due' => 2_000_000,
            'currency' => 'SYP',
            'status' => 'issued',
        ]);
        $next->tenant_id = $this->supplier->id;
        $next->save();

        $applied = $this->service()->applyToInvoice($note->fresh(), $next);

        $this->assertSame($available, $applied);
        $this->assertSame(2_000_000 - $available, $next->fresh()->balance_due);
        $this->assertSame(0, $note->fresh()->unappliedAmount());
    }

    public function test_a_credit_cannot_be_applied_beyond_its_total(): void
    {
        app(PaymentService::class)->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => $this->invoice->total, 'method' => 'cash', 'contact' => $this->buyerTenant],
            [['invoice_id' => $this->invoice->id, 'amount' => $this->invoice->total]],
        );

        $note = $this->creditNoteFor(10);

        $big = new Invoice([
            'invoice_no' => 'INV-BIG',
            'type' => 'sales',
            'contact_type' => $this->buyerTenant::class,
            'contact_id' => $this->buyerTenant->id,
            'issue_date' => today(),
            'due_date' => today()->addDays(30),
            'subtotal' => 9_000_000,
            'total' => 9_000_000,
            'balance_due' => 9_000_000,
            'currency' => 'SYP',
            'status' => 'issued',
        ]);
        $big->tenant_id = $this->supplier->id;
        $big->save();

        // محاولة تطبيق أكثر من الرصيد المتاح تُقصَر عليه
        $applied = $this->service()->applyToInvoice($note->fresh(), $big, 99_000_000);

        $this->assertSame($note->total, $applied);

        $this->expectException(RuntimeException::class);
        $this->service()->applyToInvoice($note->fresh(), $big, 1);
    }

    public function test_a_credit_cannot_cross_to_another_tenant(): void
    {
        $note = $this->creditNoteFor(10);
        $other = $this->makeSupplier('Other');

        $foreign = new Invoice([
            'invoice_no' => 'INV-FOREIGN',
            'type' => 'sales',
            'issue_date' => today(),
            'due_date' => today()->addDays(30),
            'subtotal' => 100_000,
            'total' => 100_000,
            'balance_due' => 100_000,
            'currency' => 'SYP',
            'status' => 'issued',
        ]);
        $foreign->tenant_id = $other->id;
        $foreign->save();

        $this->expectException(RuntimeException::class);
        $this->service()->applyToInvoice($note, $foreign);
    }

    public function test_available_credit_is_reported_per_customer(): void
    {
        app(PaymentService::class)->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => $this->invoice->total, 'method' => 'cash', 'contact' => $this->buyerTenant],
            [['invoice_id' => $this->invoice->id, 'amount' => $this->invoice->total]],
        );

        $note = $this->creditNoteFor(10);

        $available = $this->service()->availableCreditFor(
            $this->supplier->id,
            $this->buyerTenant::class,
            $this->buyerTenant->id,
        );

        $this->assertSame($note->total, $available);
    }
}
