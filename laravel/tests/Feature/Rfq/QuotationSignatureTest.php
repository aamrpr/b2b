<?php

namespace Tests\Feature\Rfq;

use App\Models\Catalog\Category;
use App\Models\Rfq\Quotation;
use App\Models\Rfq\Rfq;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * العرض وثيقة بين طرفين: يقرأها كلاهما ولا يقرأها ثالث، ويوقّعها كلاهما.
 *
 * والتوقيع يصل من المتصفّح data URL لا ملفاً مرفوعاً، فيُفحص فحص الملفات
 * نفسه — وإلّا صار حقل التوقيع باباً لحفظ أيّ شيء في التخزين العامّ.
 */
class QuotationSignatureTest extends TestCase
{
    use RefreshDatabase;

    /** أصغر PNG صالح: بكسل شفّاف واحد */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $supplierUser;

    private User $buyerUser;

    private Quotation $quotation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform();

        $category = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
        ]);

        $supplier = $this->makeSupplier('مستورد');
        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $this->supplierUser = $this->userFor($supplier, 'supplier@test.local');
        $this->buyerUser = $this->userFor($buyer, 'buyer@test.local');

        $rfq = Rfq::create([
            'buyer_tenant_id' => $buyer->id,
            'created_by' => $this->buyerUser->id,
            'reference_no' => 'RFQ-1',
            'title' => 'مطلوب 500 فلتر زيت',
            'category_id' => $category->id,
            'currency' => 'SYP',
            'delivery_country' => 'SY',
            'expires_at' => now()->addHours(72),
            'visibility' => 'public',
            'status' => 'open',
        ]);

        $this->quotation = Quotation::create([
            'rfq_id' => $rfq->id,
            'supplier_tenant_id' => $supplier->id,
            'buyer_tenant_id' => $buyer->id,
            'created_by' => $this->supplierUser->id,
            'quotation_no' => 'QT-2026-00001',
            'subtotal' => 500000,
            'total' => 500000,
            'currency' => 'SYP',
            'lead_time_days' => 14,
            'valid_until' => now()->addHours(72),
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->quotation->items()->create([
            'description' => 'فلتر زيت',
            'quantity' => 500,
            'unit' => 'piece',
            'unit_price' => 1000,
            'line_total' => 500000,
        ]);
    }

    private function userFor(Tenant $tenant, string $email): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'مستخدم',
            'email' => $email,
            'password' => 'password',
            'status' => 'active',
        ]);
    }

    public function test_both_parties_read_the_quotation_and_a_stranger_does_not(): void
    {
        Sanctum::actingAs($this->supplierUser);
        $this->getJson("/api/v1/quotations/{$this->quotation->id}")->assertOk();

        Sanctum::actingAs($this->buyerUser);
        $this->getJson("/api/v1/quotations/{$this->quotation->id}")
            ->assertOk()
            ->assertJsonPath('data.quotation_no', 'QT-2026-00001');

        $stranger = $this->userFor($this->makeSupplier('غريب'), 'stranger@test.local');

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/quotations/{$this->quotation->id}")->assertForbidden();
    }

    public function test_the_importer_signs_its_own_quotation_once(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->supplierUser);

        $this->postJson("/api/v1/quotations/{$this->quotation->id}/sign", [
            'signature' => self::PNG,
            'signed_by' => 'أبو أحمد',
        ])->assertOk()->assertJsonPath('quotation.signatures.supplier.by', 'أبو أحمد');

        Storage::disk('public')->assertExists($this->quotation->fresh()->supplier_signature_path);

        // التوقيع مرّة واحدة: وثيقة موقّعة لا تُوقَّع من جديد بصورة أخرى
        $this->postJson("/api/v1/quotations/{$this->quotation->id}/sign", [
            'signature' => self::PNG,
            'signed_by' => 'شخص آخر',
        ])->assertStatus(422);
    }

    public function test_a_buyer_cannot_sign_the_importer_side(): void
    {
        Sanctum::actingAs($this->buyerUser);

        $this->postJson("/api/v1/quotations/{$this->quotation->id}/sign", [
            'signature' => self::PNG,
            'signed_by' => 'تاجر',
        ])->assertForbidden();
    }

    public function test_accepting_with_a_signature_records_it_and_creates_the_order(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->buyerUser);

        $this->postJson("/api/v1/quotations/{$this->quotation->id}/accept", [
            'signature' => self::PNG,
            'signed_by' => 'تاجر الجملة',
        ])->assertOk()->assertJsonPath('order.status', 'confirmed');

        $signed = $this->quotation->fresh();

        $this->assertSame('تاجر الجملة', $signed->buyer_signed_by);
        $this->assertNotNull($signed->buyer_signed_at);
        Storage::disk('public')->assertExists($signed->buyer_signature_path);
        $this->assertSame('accepted', $signed->status);
    }

    public function test_anything_that_is_not_a_png_is_refused(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->supplierUser);

        $this->postJson("/api/v1/quotations/{$this->quotation->id}/sign", [
            'signature' => 'data:image/png;base64,'.base64_encode('<?php echo "not a png";'),
            'signed_by' => 'أبو أحمد',
        ])->assertStatus(422)->assertJsonValidationErrors('signature');

        $this->assertNull($this->quotation->fresh()->supplier_signed_at);
    }
}
