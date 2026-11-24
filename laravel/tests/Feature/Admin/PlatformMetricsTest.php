<?php

namespace Tests\Feature\Admin;

use App\Models\Orders\Order;
use App\Models\Orders\OrderItem;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Accounting\Posting\CommissionPostingService;
use App\Services\Admin\PlatformMetricsService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlatformMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function deliveredOrder(Tenant $supplier, int $total): Order
    {
        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $user = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'b'.$buyer->id.'@test.local',
            'password' => 'password',
        ]);

        $order = Order::create([
            'order_no' => 'PO-'.$buyer->id,
            'buyer_tenant_id' => $buyer->id,
            'supplier_tenant_id' => $supplier->id,
            'created_by' => $user->id,
            'subtotal' => $total,
            'total' => $total,
            'base_total' => $total,
            'currency' => 'SYP',
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'description' => 'صنف',
            'quantity' => 1,
            'unit_price' => $total,
            'unit_cost' => (int) ($total * 0.6),
            'line_total' => $total,
        ]);

        return $order->load('items', 'supplier', 'buyer');
    }

    public function test_gmv_and_commission_revenue_match_the_ledger(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $first = $this->deliveredOrder($supplier, 1_000_000);
        $second = $this->deliveredOrder($supplier, 500_000);

        $commissions = app(CommissionPostingService::class);
        $a = $commissions->accrue($first);
        $b = $commissions->accrue($second);

        $overview = app(PlatformMetricsService::class)
            ->overview(Carbon::today()->startOfYear(), Carbon::today());

        $this->assertSame(1_500_000, $overview['gmv']['total']);
        $this->assertSame(2, $overview['orders']['total']);

        // الإيراد يُقرأ من دفتر المنصة — يجب أن يطابق مجموع العمولات
        $this->assertSame($a->amount + $b->amount, $overview['commission']['revenue']);
        $this->assertSame($a->amount + $b->amount, $overview['commission']['receivable']);
    }

    public function test_cancelled_orders_are_excluded_from_gmv(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $this->deliveredOrder($supplier, 1_000_000);
        $cancelled = $this->deliveredOrder($supplier, 900_000);
        $cancelled->update(['status' => 'cancelled']);

        $overview = app(PlatformMetricsService::class)
            ->overview(Carbon::today()->startOfYear(), Carbon::today());

        $this->assertSame(1_000_000, $overview['gmv']['total']);
    }

    public function test_settling_a_commission_clears_the_receivable(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $order = $this->deliveredOrder($supplier, 2_000_000);
        $commission = app(CommissionPostingService::class)->accrue($order);

        app(CommissionPostingService::class)->settle($commission);

        $overview = app(PlatformMetricsService::class)
            ->overview(Carbon::today()->startOfYear(), Carbon::today());

        $this->assertSame(0, $overview['commission']['receivable']);
        $this->assertSame($commission->amount, $overview['commission']['revenue']);
        $this->assertSame($commission->amount, $overview['commission']['settled']);
    }
}
