<?php

namespace Tests\Feature\Returns;

use App\Models\Returns\ReturnRequest;
use App\Models\Support\AuditLog;
use App\Models\Tenancy\Tenant;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * ما بعد المرتجع: التحصيل والتقارير وسجل التدقيق يجب أن تقرأ
 * العمولة الصافية. قراءة الإجمالي هنا تعني تحصيل مبلغ لا يستحقّه أحد.
 */
class CommissionSettlementAfterReturnTest extends ReturnsTestCase
{
    use RefreshDatabase;

    private function returnAndComplete(int $quantity): ReturnRequest
    {
        $service = app(ReturnService::class);

        $request = $service->request($this->order->fresh()->load('items'), $this->buyerUser, [
            'reason' => 'damaged',
            'items' => [['order_item_id' => $this->line->id, 'quantity' => $quantity]],
        ]);

        $service->approve($request, $this->supplierUser);
        $service->receive($request->fresh()->load('items'), $this->supplierUser);
        $service->complete($request->fresh()->load('items.orderItem', 'order.supplier', 'order.buyer'));

        return $request->fresh();
    }

    public function test_settling_after_a_return_collects_only_the_net_commission(): void
    {
        $commission = $this->order->commission;
        $gross = $commission->amount;
        $platform = Tenant::platform();

        $this->returnAndComplete(20);

        $net = $commission->fresh()->netAmount();
        $this->assertLessThan($gross, $net);

        $this->commissionService()->settle($commission->fresh());

        // النقدية تساوي الصافي لا الإجمالي، والذمة تُصفّى تماماً
        $this->assertSame($net, $this->accountBalance($platform, 'cash'));
        $this->assertSame(0, $this->accountBalance($platform, 'commission_receivable'));
        $this->assertSame(0, $this->accountBalance($this->supplier, 'due_to_platform'));

        $this->assertLedgersBalanced();
    }

    public function test_the_bulk_settlement_reports_the_net_it_actually_collected(): void
    {
        $gross = $this->order->commission->amount;
        $this->returnAndComplete(20);
        $net = $this->order->commission->fresh()->netAmount();

        $response = $this->actingAs($this->platformUser, 'sanctum')
            ->postJson("/api/v1/admin/finance/suppliers/{$this->supplier->id}/settle")
            ->assertOk();

        $this->assertSame($net, $response->json('total'), 'المبلغ المُعلَن يجب أن يطابق المُرحَّل');
        $this->assertNotSame($gross, $response->json('total'));

        // MySQL يعيد تنسيق JSON عند التخزين، فتُقرأ القيمة لا النص
        $log = AuditLog::where('action', 'commission.settled_bulk')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($net, $log->new_values['total']);
        $this->assertSame(1, $log->new_values['count']);
    }

    public function test_the_aging_report_shows_the_net_due(): void
    {
        $this->returnAndComplete(20);
        $net = $this->order->commission->fresh()->netAmount();

        $this->actingAs($this->platformUser, 'sanctum')
            ->getJson('/api/v1/admin/finance/commission-aging')
            ->assertOk()
            ->assertJsonPath('total', $net)
            ->assertJsonPath('by_supplier.0.total', $net);
    }

    public function test_dashboard_accrued_and_revenue_agree_after_a_return(): void
    {
        $this->returnAndComplete(20);
        $net = $this->order->commission->fresh()->netAmount();

        $response = $this->actingAs($this->platformUser, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        // الإيراد يأتي من الدفتر، والمستحق من الجداول — يجب أن يتطابقا
        $this->assertSame($net, $response->json('commission.revenue'));
        $this->assertSame($net, $response->json('commission.receivable'));
        $this->assertSame($net, $response->json('commission.accrued'));
    }
}
