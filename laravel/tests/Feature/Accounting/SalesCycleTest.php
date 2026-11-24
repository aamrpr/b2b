<?php

namespace Tests\Feature\Accounting;

use App\Models\Orders\Order;
use App\Models\Orders\OrderItem;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\Posting\CommissionPostingService;
use App\Services\Accounting\Posting\SalesPostingService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * الدورة الكاملة: فاتورة ⟵ تكلفة البضاعة المباعة ⟵ عمولة في الدفترين،
 * ثم التحقق من توازن ميزان المراجعة والميزانية في كلا الدفترين.
 */
class SalesCycleTest extends TestCase
{
    use RefreshDatabase;

    private function deliveredOrder(Tenant $supplier, int $unitPrice, int $unitCost, int $qty): Order
    {
        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $user = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'buyer'.$buyer->id.'@test.local',
            'password' => 'password',
        ]);

        $total = $unitPrice * $qty;

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
            'description' => 'فلتر زيت',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'line_total' => $total,
        ]);

        return $order->load('items', 'supplier', 'buyer');
    }

    public function test_a_delivered_order_produces_balanced_ledgers_on_both_sides(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $order = $this->deliveredOrder($supplier, unitPrice: 100_000, unitCost: 65_000, qty: 100);

        app(InvoiceService::class)->fromOrder($order);
        app(SalesPostingService::class)->postCostOfGoodsSold($order);
        $commission = app(CommissionPostingService::class)->accrue($order);

        $this->assertNotNull($commission);

        $reports = app(FinancialReportService::class);
        $from = Carbon::today()->startOfYear();
        $to = Carbon::today();

        foreach ([$supplier, Tenant::platform()] as $tenant) {
            $trial = $reports->trialBalance($tenant, $from, $to);
            $this->assertTrue(
                $trial['totals']['is_balanced'],
                "ميزان مراجعة المستأجر {$tenant->id} غير متوازن."
            );

            $sheet = $reports->balanceSheet($tenant, $to);
            $this->assertTrue(
                $sheet['check']['is_balanced'],
                "ميزانية المستأجر {$tenant->id} غير متوازنة."
            );
        }
    }

    public function test_supplier_profit_equals_sales_minus_cost_and_commission(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $order = $this->deliveredOrder($supplier, unitPrice: 100_000, unitCost: 65_000, qty: 100);

        app(InvoiceService::class)->fromOrder($order);
        app(SalesPostingService::class)->postCostOfGoodsSold($order);
        $commission = app(CommissionPostingService::class)->accrue($order);

        $statement = app(FinancialReportService::class)
            ->incomeStatement($supplier, Carbon::today()->startOfYear(), Carbon::today());

        $sales = 100_000 * 100;
        $cost = 65_000 * 100;

        $this->assertSame($sales, $statement['revenue']['total']);
        $this->assertSame($cost, $statement['cost_of_sales']['total']);
        $this->assertSame($sales - $cost, $statement['gross_profit']);
        $this->assertSame($commission->amount, $statement['operating_expenses']['total']);
        $this->assertSame($sales - $cost - $commission->amount, $statement['net_profit']);
    }

    public function test_platform_commission_revenue_matches_supplier_commission_expense(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $order = $this->deliveredOrder($supplier, unitPrice: 50_000, unitCost: 30_000, qty: 40);
        $commission = app(CommissionPostingService::class)->accrue($order);

        $reports = app(FinancialReportService::class);
        $from = Carbon::today()->startOfYear();
        $to = Carbon::today();

        $platformRevenue = $reports->incomeStatement(Tenant::platform(), $from, $to)['revenue']['total'];
        $supplierExpense = $reports->incomeStatement($supplier, $from, $to)['operating_expenses']['total'];

        $this->assertSame($commission->amount, $platformRevenue);
        $this->assertSame($commission->amount, $supplierExpense);
    }

    public function test_accruing_commission_twice_is_a_no_op(): void
    {
        $this->platform();
        $supplier = $this->makeSupplier();
        $this->actingAsTenant($supplier);

        $order = $this->deliveredOrder($supplier, unitPrice: 10_000, unitCost: 6_000, qty: 10);

        $service = app(CommissionPostingService::class);
        $first = $service->accrue($order);
        $second = $service->accrue($order->fresh()->load('items', 'supplier', 'commission'));

        $this->assertSame($first->id, $second->id);
    }
}
