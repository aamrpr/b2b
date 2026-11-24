<?php

namespace Tests\Feature\Communication;

use App\Models\Catalog\Category;
use App\Models\Catalog\SupplierCategory;
use App\Models\Communication\NotificationPreference;
use App\Models\Rfq\Rfq;
use App\Models\User;
use App\Notifications\RfqBroadcastNotification;
use App\Services\Rfq\RfqBroadcaster;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => ['ar' => 'قطع سيارات', 'en' => 'Auto Parts'],
            'slug' => 'auto-parts',
        ]);
    }

    private function supplierUser(): User
    {
        $tenant = $this->makeSupplier();

        SupplierCategory::create([
            'tenant_id' => $tenant->id,
            'category_id' => $this->category->id,
            'is_primary' => true,
            'receives_rfq' => true,
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'مستورد',
            'email' => 's'.$tenant->id.'@test.local',
            'password' => 'password',
        ]);
    }

    private function openRfq(): Rfq
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
            'category_id' => $this->category->id,
            'currency' => 'SYP',
            'delivery_country' => 'SY',
            'expires_at' => now()->addHours(72),
            'visibility' => 'public',
            'status' => 'draft',
        ]);
    }

    public function test_broadcasting_an_rfq_notifies_every_importer_in_the_category(): void
    {
        Notification::fake();

        $first = $this->supplierUser();
        $second = $this->supplierUser();

        app(RfqBroadcaster::class)->dispatch($this->openRfq());

        Notification::assertSentTo($first, RfqBroadcastNotification::class);
        Notification::assertSentTo($second, RfqBroadcastNotification::class);
    }

    public function test_the_notification_carries_both_languages(): void
    {
        $user = $this->supplierUser();

        app(RfqBroadcaster::class)->dispatch($this->openRfq());

        $stored = $user->notifications()->first();

        $this->assertNotNull($stored);
        $this->assertSame('rfq.broadcast', $stored->data['event']);
        $this->assertArrayHasKey('ar', $stored->data['title']);
        $this->assertArrayHasKey('en', $stored->data['title']);
        $this->assertNotSame($stored->data['title']['ar'], $stored->data['title']['en']);
    }

    public function test_a_user_can_switch_an_event_off(): void
    {
        $user = $this->supplierUser();

        NotificationPreference::create([
            'user_id' => $user->id,
            'event' => 'rfq.broadcast',
            'in_app' => false,
        ]);

        app(RfqBroadcaster::class)->dispatch($this->openRfq());

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_the_api_lists_and_clears_notifications(): void
    {
        $user = $this->supplierUser();

        app(RfqBroadcaster::class)->dispatch($this->openRfq());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.event', 'rfq.broadcast');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }
}
