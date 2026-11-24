<?php

namespace Tests\Feature\Admin;

use App\Models\Accounting\CommissionRule;
use App\Models\Tenancy\SupplierProfile;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function platformUser(): User
    {
        return User::create([
            'tenant_id' => $this->platform()->id,
            'name' => 'مدير المنصة',
            'email' => 'admin@test.local',
            'password' => 'password',
        ]);
    }

    public function test_only_platform_users_reach_the_admin_panel(): void
    {
        $supplier = $this->makeSupplier();

        $supplierUser = User::create([
            'tenant_id' => $supplier->id,
            'name' => 'مستورد',
            'email' => 's@test.local',
            'password' => 'password',
        ]);

        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $buyerUser = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'b@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($supplierUser, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->actingAs($buyerUser, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->actingAs($this->platformUser(), 'sanctum')->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_approving_a_supplier_activates_it_and_writes_an_audit_entry(): void
    {
        $admin = $this->platformUser();

        $supplier = Tenant::create([
            'type' => Tenant::TYPE_SUPPLIER,
            'name' => 'مستورد جديد',
            'slug' => 'new-importer',
            'base_currency' => 'SYP',
            'country_code' => 'SY',
            'status' => 'pending',
        ]);

        SupplierProfile::create([
            'tenant_id' => $supplier->id,
            'legal_name' => 'مستورد جديد',
            'display_name' => ['ar' => 'مستورد جديد', 'en' => 'New Importer'],
            'verification_status' => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/suppliers/{$supplier->id}/verify")
            ->assertOk();

        $supplier->refresh()->load('supplierProfile');

        $this->assertSame('active', $supplier->status);
        $this->assertSame('verified', $supplier->supplierProfile->verification_status);
        $this->assertNotNull($supplier->supplierProfile->verified_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier.verified',
            'user_id' => $admin->id,
            'auditable_id' => $supplier->id,
        ]);
    }

    public function test_rejection_requires_a_reason_and_records_it(): void
    {
        $admin = $this->platformUser();

        $supplier = Tenant::create([
            'type' => Tenant::TYPE_SUPPLIER,
            'name' => 'مستورد مرفوض',
            'slug' => 'rejected-importer',
            'base_currency' => 'SYP',
            'country_code' => 'SY',
            'status' => 'pending',
        ]);

        SupplierProfile::create([
            'tenant_id' => $supplier->id,
            'legal_name' => 'مستورد مرفوض',
            'display_name' => ['ar' => 'مستورد مرفوض', 'en' => 'Rejected'],
            'verification_status' => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/suppliers/{$supplier->id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/suppliers/{$supplier->id}/reject", ['reason' => 'السجل التجاري منتهٍ'])
            ->assertOk();

        $supplier->refresh()->load('supplierProfile');

        $this->assertSame('rejected', $supplier->status);
        $this->assertSame('السجل التجاري منتهٍ', $supplier->supplierProfile->rejection_reason);
    }

    public function test_the_global_commission_rule_cannot_be_deleted(): void
    {
        $admin = $this->platformUser();

        $rule = CommissionRule::create([
            'scope' => 'global',
            'type' => 'percentage',
            'value' => 5,
            'accrual_trigger' => 'delivered',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/commission-rules/{$rule->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('commission_rules', ['id' => $rule->id]);
    }

    public function test_a_percentage_rule_above_one_hundred_is_rejected(): void
    {
        $this->actingAs($this->platformUser(), 'sanctum')
            ->postJson('/api/v1/admin/commission-rules', [
                'scope' => 'supplier',
                'scope_id' => 1,
                'type' => 'percentage',
                'value' => 150,
            ])
            ->assertStatus(422);
    }

    public function test_the_audit_log_cannot_be_altered(): void
    {
        $admin = $this->platformUser();
        $this->actingAs($admin);

        $log = app(AuditLogger::class)->record('test.action');

        $this->expectException(\RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }
}
