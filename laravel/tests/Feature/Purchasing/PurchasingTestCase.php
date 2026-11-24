<?php

namespace Tests\Feature\Purchasing;

use App\Models\Accounting\Account;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\Vendor;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * أرضية الشراء: مستورد ومورّد ومنتج، وأمر شراء جاهز للإرسال.
 * 100 قطعة بتكلفة 30,000 وضريبة 450,000.
 */
abstract class PurchasingTestCase extends TestCase
{
    protected Tenant $tenant;

    protected User $user;

    protected Vendor $vendor;

    protected Product $product;

    protected int $quantity = 100;

    protected int $unitCost = 30_000;

    protected int $orderTax = 450_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform();
        $this->tenant = $this->makeSupplier();
        $this->actingAsTenant($this->tenant);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'مشتريات',
            'email' => 'p'.$this->tenant->id.'@test.local',
            'password' => 'password',
        ]);

        $this->vendor = Vendor::create([
            'name' => 'مصنع تركي',
            'code' => 'TR-01',
            'default_currency' => 'SYP',
            'payment_terms_days' => 30,
        ]);

        $category = Category::firstOrCreate(
            ['slug' => 'auto-parts'],
            ['name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts']]
        );

        $product = new Product([
            'category_id' => $category->id,
            'name' => ['ar' => 'فلتر', 'en' => 'Filter'],
            'slug' => 'filter-'.Str::lower(Str::random(6)),
            'base_price' => 50_000,
            'currency' => 'SYP',
            'status' => 'active',
        ]);
        $product->tenant_id = $this->tenant->id;
        $product->save();

        $this->product = $product;
    }

    protected function service(): PurchasingService
    {
        return app(PurchasingService::class);
    }

    protected function order(): PurchaseOrder
    {
        $order = $this->service()->createOrder($this->tenant, $this->user, $this->vendor, [], [[
            'product_id' => $this->product->id,
            'description' => 'فلتر',
            'quantity' => $this->quantity,
            'unit_cost' => $this->unitCost,
            'tax_amount' => $this->orderTax,
        ]]);

        return $this->service()->send($order);
    }

    protected function balance(string $systemKey): int
    {
        return Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)
            ->where('system_key', $systemKey)
            ->first()
            ?->balance() ?? 0;
    }

    protected function assertBalanced(): void
    {
        $trial = app(FinancialReportService::class)->trialBalance(
            $this->tenant,
            Carbon::today()->startOfYear(),
            Carbon::today(),
        );

        $this->assertTrue($trial['totals']['is_balanced'], 'ميزان المراجعة غير متوازن.');
    }
}
