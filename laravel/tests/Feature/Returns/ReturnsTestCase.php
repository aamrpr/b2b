<?php

namespace Tests\Feature\Returns;

use App\Models\Accounting\Account;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Inventory\InventoryItem;
use App\Models\Orders\Order;
use App\Models\Orders\OrderItem;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\Posting\CommissionPostingService;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * أرضية مشتركة: طلب مُسلَّم بمخزون متتبَّع وعمولة مستحقة —
 * وهي الحالة الوحيدة التي يُفتح عليها مرتجع أو نزاع.
 */
abstract class ReturnsTestCase extends TestCase
{
    protected Tenant $supplier;

    protected Tenant $buyerTenant;

    protected User $supplierUser;

    protected User $buyerUser;

    protected User $platformUser;

    protected Order $order;

    protected OrderItem $line;

    protected InventoryItem $stock;

    /** 100 قطعة بسعر 50,000 وتكلفة 30,000 */
    protected int $quantity = 100;

    protected int $unitPrice = 50_000;

    protected int $unitCost = 30_000;

    protected function setUp(): void
    {
        parent::setUp();

        $platform = $this->platform();

        $this->platformUser = User::create([
            'tenant_id' => $platform->id,
            'name' => 'وسيط المنصة',
            'email' => 'mediator@test.local',
            'password' => 'password',
        ]);

        $this->supplier = $this->makeSupplier();
        $this->actingAsTenant($this->supplier);

        $this->supplierUser = User::create([
            'tenant_id' => $this->supplier->id,
            'name' => 'مستورد',
            'email' => 's'.$this->supplier->id.'@test.local',
            'password' => 'password',
        ]);

        $category = Category::firstOrCreate(
            ['slug' => 'auto-parts'],
            ['name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts']]
        );

        $product = new Product([
            'category_id' => $category->id,
            'name' => ['ar' => 'فلتر زيت', 'en' => 'Oil Filter'],
            'slug' => 'oil-filter-'.Str::lower(Str::random(6)),
            'base_price' => $this->unitPrice,
            'currency' => 'SYP',
            'status' => 'active',
        ]);
        $product->tenant_id = $this->supplier->id;
        $product->save();

        // مخزون كافٍ بتكلفة معلومة
        $this->stock = app(InventoryService::class)->itemFor($product);
        app(InventoryService::class)->receive($this->stock, 500, $this->unitCost, 'opening', null, true, 'capital');
        $this->stock->refresh();

        $this->buyerTenant = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $this->buyerUser = User::create([
            'tenant_id' => $this->buyerTenant->id,
            'name' => 'مشترٍ',
            'email' => 'b'.$this->buyerTenant->id.'@test.local',
            'password' => 'password',
        ]);

        $total = $this->quantity * $this->unitPrice;

        $this->order = Order::create([
            'order_no' => 'PO-'.Str::upper(Str::random(6)),
            'buyer_tenant_id' => $this->buyerTenant->id,
            'supplier_tenant_id' => $this->supplier->id,
            'created_by' => $this->buyerUser->id,
            'subtotal' => $total,
            'total' => $total,
            'base_total' => $total,
            'currency' => 'SYP',
            'status' => 'confirmed',
        ]);

        $this->line = OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $product->id,
            'description' => 'فلتر زيت',
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'unit_cost' => 0,
            'line_total' => $total,
        ]);

        // التسليم يُطلق الفاتورة والتكلفة والعمولة
        app(OrderFulfillmentService::class)->transition($this->order, 'delivered', $this->supplierUser);

        $this->order = $this->order->fresh()->load('items', 'commission', 'supplier', 'buyer');
        $this->line = $this->order->items->first();
    }

    protected function accountBalance(Tenant $tenant, string $systemKey): int
    {
        return Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('system_key', $systemKey)
            ->first()
            ?->balance() ?? 0;
    }

    protected function assertLedgersBalanced(): void
    {
        $reports = app(FinancialReportService::class);
        $from = Carbon::today()->startOfYear();
        $to = Carbon::today();

        foreach ([$this->supplier, Tenant::platform()] as $tenant) {
            $this->assertTrue(
                $reports->trialBalance($tenant, $from, $to)['totals']['is_balanced'],
                "ميزان مراجعة المستأجر {$tenant->id} غير متوازن."
            );

            $this->assertTrue(
                $reports->balanceSheet($tenant, $to)['check']['is_balanced'],
                "ميزانية المستأجر {$tenant->id} غير متوازنة."
            );
        }
    }

    protected function commissionService(): CommissionPostingService
    {
        return app(CommissionPostingService::class);
    }
}
