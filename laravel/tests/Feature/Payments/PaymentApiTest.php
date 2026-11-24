<?php

namespace Tests\Feature\Payments;

use App\Models\Accounting\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Returns\ReturnsTestCase;

/** حدود المسارات: من يحصّل، ومن يرى دفعات من. */
class PaymentApiTest extends ReturnsTestCase
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

    public function test_the_outstanding_list_shows_what_the_customer_owes(): void
    {
        $this->actingAs($this->supplierUser, 'sanctum')
            ->getJson('/api/v1/payments/outstanding?contact_id='.$this->buyerTenant->id)
            ->assertOk()
            ->assertJsonPath('invoices.0.invoice_no', $this->invoice->invoice_no)
            ->assertJsonPath('invoices.0.balance_due', $this->invoice->total)
            ->assertJsonPath('available_credit', 0);
    }

    public function test_a_receipt_is_recorded_and_allocated_through_the_api(): void
    {
        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson('/api/v1/payments/receive', [
                'contact_id' => $this->buyerTenant->id,
                'method' => 'cash',
                'amount' => 2_000_000,
                'allocations' => [['invoice_id' => $this->invoice->id, 'amount' => 2_000_000]],
            ])
            ->assertCreated()
            ->assertJsonPath('payment.allocated_amount', 2_000_000)
            ->assertJsonPath('payment.unallocated_amount', 0);

        $this->assertSame(3_000_000, $this->invoice->fresh()->balance_due);
    }

    public function test_an_over_allocation_is_refused_with_a_clear_error(): void
    {
        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson('/api/v1/payments/receive', [
                'contact_id' => $this->buyerTenant->id,
                'method' => 'cash',
                'amount' => 1_000_000,
                'allocations' => [['invoice_id' => $this->invoice->id, 'amount' => 2_000_000]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        // لم تُسجَّل دفعة ولم يتغيّر رصيد الفاتورة
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($this->invoice->total, $this->invoice->fresh()->balance_due);
    }

    public function test_a_tenant_sees_only_its_own_payments(): void
    {
        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson('/api/v1/payments/receive', [
                'contact_id' => $this->buyerTenant->id,
                'method' => 'cash',
                'amount' => 500_000,
            ])
            ->assertCreated();

        $other = $this->makeSupplier('Other');

        $stranger = User::create([
            'tenant_id' => $other->id,
            'name' => 'مستورد آخر',
            'email' => 'o'.$other->id.'@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($this->supplierUser, 'sanctum')
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1);

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 0);
    }

    public function test_the_statement_is_served_per_customer(): void
    {
        $this->actingAs($this->supplierUser, 'sanctum')
            ->getJson("/api/v1/customers/{$this->buyerTenant->id}/statement")
            ->assertOk()
            ->assertJsonPath('outstanding', $this->invoice->total)
            ->assertJsonPath('balance', $this->invoice->total);
    }

    public function test_an_unallocated_receipt_can_be_allocated_later_through_the_api(): void
    {
        $response = $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson('/api/v1/payments/receive', [
                'contact_id' => $this->buyerTenant->id,
                'method' => 'bank',
                'amount' => 1_200_000,
            ])
            ->assertCreated();

        $paymentId = $response->json('payment.id');

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/payments/{$paymentId}/allocate", [
                'allocations' => [['invoice_id' => $this->invoice->id, 'amount' => 1_200_000]],
            ])
            ->assertOk()
            ->assertJsonPath('payment.unallocated_amount', 0);

        $this->assertSame($this->invoice->total - 1_200_000, $this->invoice->fresh()->balance_due);
        $this->assertLedgersBalanced();
    }

    public function test_an_outsider_cannot_allocate_someone_elses_payment(): void
    {
        $response = $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson('/api/v1/payments/receive', [
                'contact_id' => $this->buyerTenant->id,
                'method' => 'cash',
                'amount' => 300_000,
            ])
            ->assertCreated();

        $other = $this->makeSupplier('Other');

        $stranger = User::create([
            'tenant_id' => $other->id,
            'name' => 'مستورد آخر',
            'email' => 'z'.$other->id.'@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/payments/{$response->json('payment.id')}/allocate", [
                'allocations' => [['invoice_id' => $this->invoice->id, 'amount' => 300_000]],
            ])
            ->assertForbidden();
    }
}
