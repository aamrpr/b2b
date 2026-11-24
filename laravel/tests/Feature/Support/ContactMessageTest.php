<?php

namespace Tests\Feature\Support;

use App\Models\Support\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * رسالة التواصل: من الزائر ومن المسجَّل.
 *
 * وهويّة المرسِل تُؤخذ من رمزه لا ممّا كتبه في النموذج — الادّعاء سهل
 * والرمز لا يُدّعى — فالاختبار يحرس أنّ الحساب يُنسب إلى صاحبه.
 */
class ContactMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_writes_to_the_platform(): void
    {
        $this->postJson('/api/v1/contact', [
            'name' => 'أبو خالد',
            'email' => 'visitor@example.com',
            'subject' => 'سؤال عن التسجيل',
            'message' => 'كيف أسجّل كمستورد؟',
        ])->assertCreated();

        $message = ContactMessage::sole();

        $this->assertSame('سؤال عن التسجيل', $message->subject);
        $this->assertNull($message->user_id);
        $this->assertSame('new', $message->status);
    }

    public function test_a_signed_in_sender_is_attributed_from_their_token(): void
    {
        $this->platform();
        $tenant = $this->makeSupplier('مستورد');

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'مدير',
            'email' => 'owner@test.local',
            'password' => 'password',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/contact', [
            'name' => 'شخص آخر',
            'email' => 'other@example.com',
            'subject' => 'عمولة',
            'message' => 'سؤال عن العمولة.',
        ])->assertCreated();

        $message = ContactMessage::sole();

        $this->assertSame($user->id, $message->user_id);
        $this->assertSame($tenant->id, $message->tenant_id);
    }

    public function test_only_the_platform_reads_the_inbox(): void
    {
        $this->platform();
        $tenant = $this->makeSupplier('مستورد');

        Sanctum::actingAs(User::create([
            'tenant_id' => $tenant->id,
            'name' => 'مستورد',
            'email' => 'importer@test.local',
            'password' => 'password',
            'status' => 'active',
        ]));

        $this->getJson('/api/v1/admin/contact-messages')->assertForbidden();

        Sanctum::actingAs(User::create([
            'tenant_id' => $this->platform()->id,
            'name' => 'مدير المنصة',
            'email' => 'admin@test.local',
            'password' => 'password',
            'status' => 'active',
        ]));

        $this->getJson('/api/v1/admin/contact-messages')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
