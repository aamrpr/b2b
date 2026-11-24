<?php

namespace Tests\Feature\Payments;

use App\Models\Accounting\Invoice;
use App\Models\Accounting\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Returns\ReturnsTestCase;

/**
 * التحصيل. الفاتورة الأصلية 5,000,000 على طلب مُسلَّم.
 */
class PaymentServiceTest extends ReturnsTestCase
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

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function receive(int $amount, ?int $allocate = null, array $extra = []): Payment
    {
        return $this->service()->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => $amount, 'method' => 'cash', 'contact' => $this->buyerTenant, ...$extra],
            $allocate === null ? [] : [['invoice_id' => $this->invoice->id, 'amount' => $allocate]],
        );
    }

    public function test_a_full_payment_clears_the_invoice_and_the_receivable(): void
    {
        $total = $this->invoice->total;

        $this->receive($total, $total);

        $invoice = $this->invoice->fresh();

        $this->assertSame($total, $invoice->paid_amount);
        $this->assertSame(0, $invoice->balance_due);
        $this->assertSame('paid', $invoice->status);

        $this->assertSame($total, $this->accountBalance($this->supplier, 'cash'));
        $this->assertSame(0, $this->accountBalance($this->supplier, 'accounts_receivable'));

        $this->assertLedgersBalanced();
    }

    public function test_a_partial_payment_leaves_the_rest_outstanding(): void
    {
        $this->receive(2_000_000, 2_000_000);

        $invoice = $this->invoice->fresh();

        $this->assertSame(2_000_000, $invoice->paid_amount);
        $this->assertSame(3_000_000, $invoice->balance_due);
        $this->assertSame('partially_paid', $invoice->status);
        $this->assertSame(3_000_000, $this->accountBalance($this->supplier, 'accounts_receivable'));
    }

    public function test_an_unallocated_receipt_is_a_liability_not_a_collection(): void
    {
        $this->receive(1_500_000);

        // النقد دخل، لكن ذمة العميل لم تُمسّ — المبلغ دفعة مقدمة
        $this->assertSame(1_500_000, $this->accountBalance($this->supplier, 'cash'));
        $this->assertSame(1_500_000, $this->accountBalance($this->supplier, 'customer_advances'));
        $this->assertSame($this->invoice->total, $this->accountBalance($this->supplier, 'accounts_receivable'));
        $this->assertSame($this->invoice->total, $this->invoice->fresh()->balance_due);

        $this->assertLedgersBalanced();
    }

    public function test_allocating_later_turns_the_advance_into_a_collection(): void
    {
        $payment = $this->receive(1_500_000);

        $this->service()->allocate($payment, [
            ['invoice_id' => $this->invoice->id, 'amount' => 1_500_000],
        ]);

        $this->assertSame(0, $this->accountBalance($this->supplier, 'customer_advances'));
        $this->assertSame(
            $this->invoice->total - 1_500_000,
            $this->accountBalance($this->supplier, 'accounts_receivable')
        );
        $this->assertSame(1_500_000, $this->invoice->fresh()->paid_amount);

        $this->assertLedgersBalanced();
    }

    public function test_allocating_more_than_the_payment_is_refused(): void
    {
        $payment = $this->receive(1_000_000);

        $this->expectException(RuntimeException::class);
        $this->service()->allocate($payment, [
            ['invoice_id' => $this->invoice->id, 'amount' => 1_500_000],
        ]);
    }

    public function test_allocating_more_than_the_invoice_owes_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->receive(9_000_000, 9_000_000);
    }

    public function test_the_allocation_total_cannot_exceed_the_payment(): void
    {
        $this->expectException(RuntimeException::class);
        $this->receive(1_000_000, 2_000_000);
    }

    public function test_a_payment_cannot_be_allocated_to_another_tenants_invoice(): void
    {
        $other = $this->makeSupplier('Other');

        $foreign = new Invoice([
            'invoice_no' => 'INV-X',
            'type' => 'sales',
            'issue_date' => today(),
            'due_date' => today()->addDays(30),
            'subtotal' => 100,
            'total' => 100,
            'balance_due' => 100,
            'currency' => 'SYP',
            'status' => 'issued',
        ]);
        $foreign->tenant_id = $other->id;
        $foreign->save();

        $this->expectException(RuntimeException::class);
        $this->service()->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => 100, 'method' => 'cash', 'contact' => $this->buyerTenant],
            [['invoice_id' => $foreign->id, 'amount' => 100]],
        );
    }

    public function test_a_collection_fee_reduces_cash_but_not_the_customer_credit(): void
    {
        $this->receive(1_000_000, 1_000_000, ['fee_amount' => 25_000]);

        // العميل يُحسب له المبلغ كاملاً، والرسوم مصروف على المستورد
        $this->assertSame(975_000, $this->accountBalance($this->supplier, 'cash'));
        $this->assertSame(1_000_000, $this->invoice->fresh()->paid_amount);
        $this->assertSame(
            $this->invoice->total - 1_000_000,
            $this->accountBalance($this->supplier, 'accounts_receivable')
        );

        $this->assertLedgersBalanced();
    }

    public function test_a_fee_as_large_as_the_payment_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->receive(100_000, null, ['fee_amount' => 100_000]);
    }

    public function test_one_payment_can_settle_several_invoices(): void
    {
        $second = new Invoice([
            'invoice_no' => 'INV-2',
            'type' => 'sales',
            'contact_type' => $this->buyerTenant::class,
            'contact_id' => $this->buyerTenant->id,
            'issue_date' => today(),
            'due_date' => today()->addDays(30),
            'subtotal' => 800_000,
            'total' => 800_000,
            'balance_due' => 800_000,
            'currency' => 'SYP',
            'status' => 'issued',
        ]);
        $second->tenant_id = $this->supplier->id;
        $second->save();

        $this->service()->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => 1_800_000, 'method' => 'bank', 'contact' => $this->buyerTenant],
            [
                ['invoice_id' => $this->invoice->id, 'amount' => 1_000_000],
                ['invoice_id' => $second->id, 'amount' => 800_000],
            ],
        );

        $this->assertSame(1_000_000, $this->invoice->fresh()->paid_amount);
        $this->assertSame('paid', $second->fresh()->status);
        $this->assertSame(1_800_000, $this->accountBalance($this->supplier, 'bank'));
    }

    public function test_paying_a_vendor_clears_the_payable(): void
    {
        $bill = new Invoice([
            'invoice_no' => 'BILL-1',
            'type' => 'purchase',
            'issue_date' => today(),
            'due_date' => today()->addDays(15),
            'subtotal' => 600_000,
            'total' => 600_000,
            'balance_due' => 600_000,
            'currency' => 'SYP',
            'status' => 'issued',
        ]);
        $bill->tenant_id = $this->supplier->id;
        $bill->save();

        $this->service()->pay(
            $this->supplier,
            $this->supplierUser,
            ['amount' => 600_000, 'method' => 'bank'],
            [['invoice_id' => $bill->id, 'amount' => 600_000]],
        );

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(-600_000, $this->accountBalance($this->supplier, 'bank'));
        $this->assertSame(-600_000, $this->accountBalance($this->supplier, 'accounts_payable'));

        $this->assertLedgersBalanced();
    }

    public function test_a_sales_invoice_cannot_be_paid_as_a_purchase(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->pay(
            $this->supplier,
            $this->supplierUser,
            ['amount' => 100_000, 'method' => 'cash'],
            [['invoice_id' => $this->invoice->id, 'amount' => 100_000]],
        );
    }

    public function test_the_statement_runs_a_balance_across_invoices_and_payments(): void
    {
        $this->receive(2_000_000, 2_000_000);

        $statement = $this->service()->statement($this->supplier, $this->buyerTenant);

        $this->assertCount(2, $statement['lines']);
        $this->assertSame($this->invoice->total, $statement['lines'][0]['debit']);
        $this->assertSame(2_000_000, $statement['lines'][1]['credit']);
        $this->assertSame($this->invoice->total - 2_000_000, $statement['balance']);
        $this->assertSame($this->invoice->total - 2_000_000, $statement['outstanding']);
    }

    public function test_an_overdue_unpaid_invoice_reports_itself_overdue(): void
    {
        $this->invoice->forceFill(['due_date' => today()->subDays(10)])->save();

        $this->assertSame('overdue', $this->invoice->fresh()->derivedStatus());

        $this->receive(1_000_000, 1_000_000);

        // سداد جزئي متأخر يبقى متأخراً لا "مسدَّد جزئياً"
        $this->assertSame('overdue', $this->invoice->fresh()->status);
    }
}
