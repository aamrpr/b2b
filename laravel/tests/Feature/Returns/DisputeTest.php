<?php

namespace Tests\Feature\Returns;

use App\Models\Returns\Dispute;
use App\Models\Returns\DisputeEvent;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Notifications\DisputeUpdateNotification;
use App\Services\Returns\DisputeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class DisputeTest extends ReturnsTestCase
{
    use RefreshDatabase;

    private function service(): DisputeService
    {
        return app(DisputeService::class);
    }

    private function open(array $extra = []): Dispute
    {
        return $this->service()->open($this->order, $this->buyerUser, [
            'reason' => 'damaged',
            'description' => 'وصلت البضاعة متضررة',
            'claimed_amount' => 500_000,
            ...$extra,
        ]);
    }

    public function test_only_a_party_to_the_order_can_open_a_dispute(): void
    {
        $outsider = $this->makeSupplier('Outsider');

        $stranger = User::create([
            'tenant_id' => $outsider->id,
            'name' => 'طرف ثالث',
            'email' => 'x'.$outsider->id.'@test.local',
            'password' => 'password',
        ]);

        $this->expectException(RuntimeException::class);
        $this->service()->open($this->order, $stranger, [
            'reason' => 'other',
            'description' => 'لا شأن لي',
        ]);
    }

    public function test_one_open_dispute_per_order(): void
    {
        $this->open();

        $this->expectException(RuntimeException::class);
        $this->open();
    }

    public function test_a_closed_dispute_frees_the_order_for_a_new_one(): void
    {
        $first = $this->open();
        $this->service()->close($first, $this->buyerUser);

        $second = $this->open();

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_opening_sets_a_response_deadline_and_logs_the_event(): void
    {
        $dispute = $this->open();

        $this->assertSame('open', $dispute->status);
        $this->assertNotNull($dispute->due_at);
        $this->assertSame($this->buyerTenant->id, $dispute->opened_by_tenant_id);
        $this->assertCount(1, $dispute->events()->get());
    }

    public function test_only_platform_users_can_mediate(): void
    {
        $dispute = $this->open();

        $this->expectException(RuntimeException::class);
        $this->service()->assign($dispute, $this->supplierUser);
    }

    public function test_assignment_moves_the_dispute_under_review(): void
    {
        $dispute = $this->service()->assign($this->open(), $this->platformUser);

        $this->assertSame('under_review', $dispute->status);
        $this->assertSame($this->platformUser->id, $dispute->assigned_to);
    }

    public function test_a_party_reply_returns_the_ball_to_the_mediator(): void
    {
        $dispute = $this->service()->assign($this->open(), $this->platformUser);
        $dispute = $this->service()->requestResponse($dispute, $this->platformUser, 'أرسل صور التلف');

        $this->assertSame('awaiting_party', $dispute->status);
        $this->assertNotNull($dispute->due_at);

        $this->service()->addMessage($dispute, $this->buyerUser, 'هذه الصور');

        $this->assertSame('under_review', $dispute->fresh()->status);
        $this->assertNull($dispute->fresh()->due_at);
    }

    public function test_internal_mediator_notes_are_hidden_from_the_parties(): void
    {
        $dispute = $this->service()->assign($this->open(), $this->platformUser);

        $this->service()->addMessage($dispute, $this->platformUser, 'ملاحظة داخلية', null, internal: true);
        $this->service()->addMessage($dispute, $this->platformUser, 'رسالة للطرفين');

        $this->actingAs($this->buyerUser, 'sanctum')
            ->getJson("/api/v1/disputes/{$dispute->id}")
            ->assertOk()
            ->assertJsonMissing(['body' => 'ملاحظة داخلية'])
            ->assertJsonFragment(['body' => 'رسالة للطرفين']);

        $this->actingAs($this->platformUser, 'sanctum')
            ->getJson("/api/v1/disputes/{$dispute->id}")
            ->assertOk()
            ->assertJsonFragment(['body' => 'ملاحظة داخلية']);
    }

    public function test_a_closed_dispute_accepts_no_more_messages(): void
    {
        $dispute = $this->open();
        $this->service()->close($dispute, $this->buyerUser);

        $this->expectException(RuntimeException::class);
        $this->service()->addMessage($dispute->fresh(), $this->buyerUser, 'رسالة متأخرة');
    }

    public function test_the_dispute_log_cannot_be_altered(): void
    {
        $dispute = $this->open();
        $event = DisputeEvent::where('dispute_id', $dispute->id)->first();

        try {
            $event->update(['body' => 'تلاعب']);
            $this->fail('تعديل سجل النزاع كان يجب أن يفشل.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->expectException(RuntimeException::class);
        $event->delete();
    }

    public function test_a_decision_for_the_buyer_needs_an_amount(): void
    {
        $dispute = $this->service()->assign($this->open(), $this->platformUser);

        $this->expectException(RuntimeException::class);
        $this->service()->resolve($dispute, $this->platformUser, ['outcome' => 'buyer_favored']);
    }

    public function test_compensation_cannot_exceed_the_claim(): void
    {
        $dispute = $this->service()->assign($this->open(), $this->platformUser);

        $this->expectException(RuntimeException::class);
        $this->service()->resolve($dispute, $this->platformUser, [
            'outcome' => 'buyer_favored',
            'resolution_amount' => 900_000,
        ]);
    }

    public function test_only_the_mediator_can_decide(): void
    {
        $dispute = $this->service()->assign($this->open(), $this->platformUser);

        $this->expectException(RuntimeException::class);
        $this->service()->resolve($dispute, $this->supplierUser, [
            'outcome' => 'supplier_favored',
        ]);
    }

    public function test_a_decision_for_the_buyer_posts_compensation_and_reverses_commission(): void
    {
        $commission = $this->order->commission;
        $originalCommission = $commission->amount;
        $platform = Tenant::platform();

        $dispute = $this->service()->assign($this->open(), $this->platformUser);

        $this->service()->resolve($dispute, $this->platformUser, [
            'outcome' => 'partial',
            'resolution_amount' => 400_000,
            'resolution_note' => 'تعويض جزئي',
        ]);

        // خصم مسموح به في دفتر المستورد يقابل خفض ذمة المشتري
        $this->assertSame(400_000, $this->accountBalance($this->supplier, 'discount_allowed'));
        $this->assertSame(
            $this->quantity * $this->unitPrice - 400_000,
            $this->accountBalance($this->supplier, 'accounts_receivable')
        );

        // المنصة تتنازل عن حصّتها من المبلغ المعوَّض
        $expectedReversal = (int) round($originalCommission * 400_000 / $commission->base_amount);

        $this->assertSame(
            $originalCommission - $expectedReversal,
            $this->accountBalance($platform, 'commission_revenue')
        );

        $this->assertLedgersBalanced();
    }

    public function test_a_decision_for_the_supplier_has_no_financial_effect(): void
    {
        $platform = Tenant::platform();
        $before = $this->accountBalance($platform, 'commission_revenue');

        $dispute = $this->service()->assign($this->open(), $this->platformUser);

        $resolved = $this->service()->resolve($dispute, $this->platformUser, [
            'outcome' => 'supplier_favored',
            'resolution_note' => 'المطالبة غير مدعومة',
        ]);

        $this->assertSame('resolved', $resolved->status);
        $this->assertSame(0, $this->accountBalance($this->supplier, 'discount_allowed'));
        $this->assertSame($before, $this->accountBalance($platform, 'commission_revenue'));
    }

    public function test_parties_see_only_their_own_disputes_while_the_platform_sees_all(): void
    {
        $this->open();

        $otherSupplier = $this->makeSupplier('Other');

        $otherUser = User::create([
            'tenant_id' => $otherSupplier->id,
            'name' => 'مستورد آخر',
            'email' => 'o'.$otherSupplier->id.'@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($this->buyerUser, 'sanctum')
            ->getJson('/api/v1/disputes')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/disputes')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 0);

        $this->actingAs($this->platformUser, 'sanctum')
            ->getJson('/api/v1/disputes')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('is_mediator', true);
    }

    public function test_resolving_is_refused_before_review_starts(): void
    {
        $dispute = $this->open();

        $this->assertFalse($dispute->canMoveTo('resolved'));

        $this->expectException(RuntimeException::class);
        $this->service()->resolve($dispute, $this->platformUser, ['outcome' => 'withdrawn']);
    }

    public function test_the_other_party_is_notified_when_a_dispute_opens(): void
    {
        Notification::fake();

        $this->open();

        Notification::assertSentTo(
            User::where('tenant_id', $this->supplier->id)->get(),
            DisputeUpdateNotification::class
        );

        Notification::assertNotSentTo(
            [$this->buyerUser],
            DisputeUpdateNotification::class
        );
    }

    public function test_overdue_disputes_surface_for_the_mediator(): void
    {
        $dispute = $this->open();
        $dispute->update(['due_at' => now()->subDay()]);

        $this->assertTrue($dispute->fresh()->isOverdue());
        $this->assertCount(1, $this->service()->overdue());
    }
}
