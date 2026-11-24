<?php

namespace Tests\Feature\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\Accounting\Invoice;
use App\Services\Payments\PaymentService;
use App\Services\Returns\ReturnService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Returns\ReturnsTestCase;

/**
 * الدفعة الموزَّعة على فاتورة عميل تخصّ ذلك العميل حتى لو لم يُذكر.
 * بدون ذلك يُسقط كشف الحساب الدفعة ويبقى الرصيد الجاري مضخَّماً.
 */
class StatementConsistencyTest extends ReturnsTestCase
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

    public function test_a_payment_without_a_contact_adopts_the_invoice_customer(): void
    {
        $payment = app(PaymentService::class)->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => 1_500_000, 'method' => 'cash'],   // بلا عميل
            [['invoice_id' => $this->invoice->id, 'amount' => 1_500_000]],
        );

        $this->assertSame($this->buyerTenant->id, $payment->fresh()->contact_id);
    }

    public function test_the_statement_balance_matches_what_is_outstanding(): void
    {
        app(PaymentService::class)->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => 1_500_000, 'method' => 'cash'],
            [['invoice_id' => $this->invoice->id, 'amount' => 1_500_000]],
        );

        $statement = app(PaymentService::class)->statement($this->supplier, $this->buyerTenant);

        $this->assertCount(2, $statement['lines'], 'الفاتورة والدفعة معاً');
        $this->assertSame($statement['outstanding'], $statement['balance']);
        $this->assertSame($this->invoice->total - 1_500_000, $statement['balance']);
    }

    public function test_a_credit_note_appears_on_the_statement_and_keeps_it_consistent(): void
    {
        $returns = app(ReturnService::class);

        $request = $returns->request($this->order->fresh()->load('items'), $this->buyerUser, [
            'reason' => 'damaged',
            'items' => [['order_item_id' => $this->line->id, 'quantity' => 10]],
        ]);
        $returns->approve($request, $this->supplierUser);
        $returns->receive($request->fresh()->load('items'), $this->supplierUser);
        $note = $returns->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        app(PaymentService::class)->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => 1_000_000, 'method' => 'cash'],
            [['invoice_id' => $this->invoice->id, 'amount' => 1_000_000]],
        );

        $statement = app(PaymentService::class)->statement($this->supplier, $this->buyerTenant);

        $references = array_column($statement['lines'], 'reference');
        $this->assertContains($note->note_no, $references, 'الإشعار بند في الكشف');

        // الرصيد الجاري يساوي مجموع أرصدة الفواتير
        $this->assertSame($statement['outstanding'], $statement['balance']);
        $this->assertSame(
            $this->invoice->total - $note->total - 1_000_000,
            $statement['balance'],
        );
    }

    public function test_an_unallocated_payment_keeps_the_contact_it_was_given(): void
    {
        $payment = app(PaymentService::class)->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => 500_000, 'method' => 'cash', 'contact' => $this->buyerTenant],
        );

        $this->assertSame($this->buyerTenant->id, $payment->fresh()->contact_id);
    }

    public function test_allocating_across_two_customers_in_one_payment_is_refused(): void
    {
        $otherBuyer = app(TenantProvisioner::class)
            ->createBuyer(['name' => 'تاجر آخر']);

        $second = new Invoice([
            'invoice_no' => 'INV-OTHER',
            'type' => 'sales',
            'contact_type' => $otherBuyer::class,
            'contact_id' => $otherBuyer->id,
            'issue_date' => today(),
            'due_date' => today()->addDays(30),
            'subtotal' => 400_000,
            'total' => 400_000,
            'balance_due' => 400_000,
            'currency' => 'SYP',
            'status' => 'issued',
        ]);
        $second->tenant_id = $this->supplier->id;
        $second->save();

        $this->expectException(BusinessRuleException::class);

        app(PaymentService::class)->receive(
            $this->supplier,
            $this->supplierUser,
            ['amount' => 900_000, 'method' => 'cash'],
            [
                ['invoice_id' => $this->invoice->id, 'amount' => 500_000],
                ['invoice_id' => $second->id, 'amount' => 400_000],
            ],
        );
    }
}
