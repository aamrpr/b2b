<?php

namespace Tests\Feature\Rfq;

use App\Models\Catalog\Category;
use App\Models\Catalog\SupplierCategory;
use App\Models\Rfq\Rfq;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Rfq\RfqBroadcaster;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * السيناريو الأساسي: تاجر الجملة ينشر طلباً في صنف،
 * فيصل إلى المستوردين المسجّلين في ذلك الصنف وحدهم.
 */
class RfqBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Category $autoParts;

    private Category $furniture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autoParts = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
        ]);

        $this->furniture = Category::create([
            'name' => ['ar' => 'أثاث', 'en' => 'Furniture'],
            'slug' => 'furniture',
        ]);
    }

    private function supplierIn(Category $category): Tenant
    {
        $tenant = $this->makeSupplier();

        SupplierCategory::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'is_primary' => true,
            'receives_rfq' => true,
        ]);

        return $tenant;
    }

    private function buyerRfq(Category $category): Rfq
    {
        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $user = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'b'.$buyer->id.'@test.local',
            'password' => 'password',
        ]);

        return Rfq::create([
            'buyer_tenant_id' => $buyer->id,
            'created_by' => $user->id,
            'reference_no' => 'RFQ-'.$buyer->id,
            'title' => 'مطلوب 200 فلتر زيت',
            'category_id' => $category->id,
            'currency' => 'SYP',
            'delivery_country' => 'SY',
            'expires_at' => now()->addHours(72),
            'visibility' => 'public',
            'status' => 'draft',
        ]);
    }

    public function test_it_reaches_only_suppliers_registered_in_the_category(): void
    {
        $inCategory = [$this->supplierIn($this->autoParts), $this->supplierIn($this->autoParts)];
        $this->supplierIn($this->furniture);   // لا يجب أن يصله الطلب

        $rfq = $this->buyerRfq($this->autoParts);

        $reached = app(RfqBroadcaster::class)->dispatch($rfq);

        $this->assertSame(2, $reached);

        $notified = $rfq->invitations()->pluck('supplier_tenant_id')->sort()->values()->all();
        $expected = collect($inCategory)->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $notified);
        $this->assertSame('open', $rfq->fresh()->status);
        $this->assertSame(2, $rfq->fresh()->notified_count);
    }

    public function test_it_reaches_suppliers_registered_in_a_child_category(): void
    {
        $child = Category::create([
            'parent_id' => $this->autoParts->id,
            'name' => ['ar' => 'فلاتر', 'en' => 'Filters'],
            'slug' => 'filters',
        ]);

        $this->supplierIn($child);

        $rfq = $this->buyerRfq($this->autoParts);

        $this->assertSame(1, app(RfqBroadcaster::class)->dispatch($rfq));
    }

    public function test_rebroadcasting_does_not_duplicate_invitations(): void
    {
        $this->supplierIn($this->autoParts);
        $rfq = $this->buyerRfq($this->autoParts);

        $broadcaster = app(RfqBroadcaster::class);
        $broadcaster->dispatch($rfq);
        $broadcaster->dispatch($rfq->fresh());

        $this->assertSame(1, $rfq->invitations()->count());
    }

    public function test_suppliers_who_opted_out_are_skipped(): void
    {
        $optedOut = $this->supplierIn($this->autoParts);
        SupplierCategory::where('tenant_id', $optedOut->id)->update(['receives_rfq' => false]);

        $rfq = $this->buyerRfq($this->autoParts);

        $this->assertSame(0, app(RfqBroadcaster::class)->dispatch($rfq));
    }
}
