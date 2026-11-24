<?php

namespace Tests\Feature\Rfq;

use App\Models\Catalog\Category;
use App\Models\Catalog\SupplierCategory;
use App\Models\Rfq\Quotation;
use App\Models\Rfq\Rfq;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * الترقيم لكل مستورد على حدة، والقيد الفريد يجب أن يوافقه.
 * كان القيد على مستوى المنصة كلها، فينجح أول مستورد يعرض سعراً
 * ويسقط كل من بعده على QT-2026-00001 — وهذا يكسر جوهر المنصة:
 * أن يصل الطلب إلى كل المختصين وأن يردّوا جميعاً.
 */
class QuotationNumberingTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Rfq $rfq;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform();

        $this->category = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
        ]);

        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $buyerUser = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'buyer@test.local',
            'password' => 'password',
        ]);

        $this->rfq = Rfq::create([
            'buyer_tenant_id' => $buyer->id,
            'created_by' => $buyerUser->id,
            'reference_no' => 'RFQ-1',
            'title' => 'مطلوب 500 فلتر زيت',
            'category_id' => $this->category->id,
            'currency' => 'SYP',
            'delivery_country' => 'SY',
            'expires_at' => now()->addHours(72),
            'visibility' => 'public',
            'status' => 'open',
        ]);
    }

    private function importerUser(string $email): User
    {
        $tenant = $this->makeSupplier();

        SupplierCategory::create([
            'tenant_id' => $tenant->id,
            'category_id' => $this->category->id,
            'is_primary' => true,
            'receives_rfq' => true,
        ]);

        $this->rfq->invitations()->create([
            'supplier_tenant_id' => $tenant->id,
            'notified_at' => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'مستورد',
            'email' => $email,
            'password' => 'password',
        ]);
    }

    private function quote(User $user, int $unitPrice): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/v1/rfqs/{$this->rfq->id}/quotations", [
            'currency' => 'SYP',
            'lead_time_days' => 14,
            'payment_terms_days' => 30,
            'items' => [
                ['description' => 'فلتر زيت', 'quantity' => 500, 'unit' => 'قطعة', 'unit_price' => $unitPrice],
            ],
        ]);
    }

    public function test_every_invited_importer_can_send_a_quotation(): void
    {
        $first = $this->importerUser('i1@test.local');
        $second = $this->importerUser('i2@test.local');
        $third = $this->importerUser('i3@test.local');

        $this->quote($first, 85000)->assertCreated();
        $this->quote($second, 92000)->assertCreated();
        $this->quote($third, 99000)->assertCreated();

        $this->assertSame(3, Quotation::withoutGlobalScopes()->count());
    }

    public function test_each_importer_numbers_its_own_quotations_from_one(): void
    {
        $first = $this->importerUser('i1@test.local');
        $second = $this->importerUser('i2@test.local');

        $this->quote($first, 85000)->assertCreated();
        $this->quote($second, 92000)->assertCreated();

        $numbers = Quotation::withoutGlobalScopes()
            ->orderBy('id')
            ->pluck('quotation_no')
            ->all();

        // الرقم نفسه في دفترَين مختلفَين — وهذا هو المقصود
        $this->assertSame([$numbers[0], $numbers[0]], $numbers);
        $this->assertStringEndsWith('-00001', $numbers[0]);
    }

    public function test_one_importer_cannot_reuse_its_own_quotation_number(): void
    {
        $importer = $this->importerUser('i1@test.local');
        $tenantId = $importer->tenant_id;

        $this->quote($importer, 85000)->assertCreated();

        $existing = Quotation::withoutGlobalScopes()->where('supplier_tenant_id', $tenantId)->sole();

        $this->expectException(UniqueConstraintViolationException::class);

        Quotation::withoutGlobalScopes()->create([
            'rfq_id' => $this->rfq->id,
            'supplier_tenant_id' => $tenantId,
            'buyer_tenant_id' => $this->rfq->buyer_tenant_id,
            'created_by' => $importer->id,
            'quotation_no' => $existing->quotation_no,
            'currency' => 'SYP',
            'valid_until' => now()->addDays(7),
            'status' => 'draft',
        ]);
    }

    public function test_the_unique_index_is_scoped_to_the_importer(): void
    {
        $indexes = collect(\DB::select('
            SELECT s.INDEX_NAME i, GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) cols
            FROM information_schema.STATISTICS s
            WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = "quotations" AND s.NON_UNIQUE = 0
            GROUP BY s.INDEX_NAME
        '))->pluck('cols');

        $this->assertTrue($indexes->contains('supplier_tenant_id,quotation_no'));
        $this->assertFalse($indexes->contains('quotation_no'));
    }
}
