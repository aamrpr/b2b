<?php

namespace Tests\Feature\Communication;

use App\Events\ConversationRead;
use App\Events\ConversationTyping;
use App\Events\MessageSent;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Services\Communication\ConversationService;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private function actors(): array
    {
        $supplier = $this->makeSupplier();
        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);

        $supplierUser = User::create([
            'tenant_id' => $supplier->id,
            'name' => 'مستورد',
            'email' => 's'.$supplier->id.'@test.local',
            'password' => 'password',
        ]);

        $buyerUser = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'b'.$buyer->id.'@test.local',
            'password' => 'password',
        ]);

        return [$supplier, $buyer, $supplierUser, $buyerUser];
    }

    public function test_the_same_pair_reuses_one_conversation_per_context(): void
    {
        [$supplier, $buyer] = $this->actors();
        $service = app(ConversationService::class);

        $first = $service->between($buyer, $supplier);
        $second = $service->between($supplier, $buyer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Conversation::count());
        $this->assertCount(2, $first->participants);
    }

    public function test_sending_a_message_broadcasts_and_notifies_the_other_side_only(): void
    {
        Event::fake([MessageSent::class]);
        Notification::fake();

        [$supplier, $buyer, $supplierUser, $buyerUser] = $this->actors();
        $service = app(ConversationService::class);

        $conversation = $service->between($buyer, $supplier);
        $service->send($conversation, $buyerUser, ['body' => 'عندكم فلاتر زيت؟']);

        Event::assertDispatched(MessageSent::class);

        Notification::assertSentTo($supplierUser, NewMessageNotification::class);
        Notification::assertNotSentTo($buyerUser, NewMessageNotification::class);
    }

    public function test_unread_counts_track_the_message_id_not_the_timestamp(): void
    {
        [$supplier, $buyer, $supplierUser, $buyerUser] = $this->actors();
        $service = app(ConversationService::class);

        $conversation = $service->between($buyer, $supplier);

        // رسالتان في نفس الثانية — الطوابع وحدها لا تكفي للتمييز
        $service->send($conversation, $buyerUser, ['body' => 'الرسالة الأولى']);
        $service->send($conversation, $supplierUser, ['body' => 'الردّ في نفس الثانية']);

        $conversation->refresh()->load('participants', 'messages');

        $this->assertSame(1, $conversation->unreadCountFor($buyer->id));
        $this->assertSame(0, $conversation->unreadCountFor($supplier->id));
        $this->assertSame(1, $service->unreadConversationCount($buyer->id));
        $this->assertSame(0, $service->unreadConversationCount($supplier->id));
    }

    public function test_marking_read_clears_the_unread_count(): void
    {
        [$supplier, $buyer, $supplierUser, $buyerUser] = $this->actors();
        $service = app(ConversationService::class);

        $conversation = $service->between($buyer, $supplier);
        $service->send($conversation, $supplierUser, ['body' => 'أهلاً بك']);

        $this->assertSame(1, $service->unreadConversationCount($buyer->id));

        $service->markRead($conversation, $buyer->id);

        $this->assertSame(0, $service->unreadConversationCount($buyer->id));
    }

    public function test_an_outsider_cannot_read_a_conversation(): void
    {
        [$supplier, $buyer, , $buyerUser] = $this->actors();
        $outsider = $this->makeSupplier('Outsider');

        $outsiderUser = User::create([
            'tenant_id' => $outsider->id,
            'name' => 'طرف ثالث',
            'email' => 'x'.$outsider->id.'@test.local',
            'password' => 'password',
        ]);

        $conversation = app(ConversationService::class)->between($buyer, $supplier);

        $this->actingAs($buyerUser, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk();

        $this->actingAs($outsiderUser, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertForbidden();
    }

    public function test_the_broadcast_reaches_a_private_channel_per_participant(): void
    {
        [$supplier, $buyer, , $buyerUser] = $this->actors();

        $conversation = app(ConversationService::class)->between($buyer, $supplier);
        $message = app(ConversationService::class)->send($conversation, $buyerUser, ['body' => 'مرحباً']);

        $channels = collect((new MessageSent($message))->broadcastOn())
            ->map(fn ($channel) => $channel->name)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([$supplier->id, $buyer->id])->map(fn ($id) => "private-tenant.{$id}")->sort()->values()->all(),
            $channels
        );
    }

    public function test_a_participant_can_send_image_voice_video_and_pdf(): void
    {
        Storage::fake('public');

        [$supplier, $buyer, , $buyerUser] = $this->actors();
        $conversation = app(ConversationService::class)->between($buyer, $supplier);

        $cases = [
            ['kind' => 'image', 'file' => UploadedFile::fake()->image('منتج.jpg'), 'type' => 'image', 'mime' => 'image/jpeg', 'name' => 'منتج.jpg'],
            ['kind' => 'audio', 'file' => UploadedFile::fake()->create('voice.webm', 80, 'video/webm'), 'type' => 'audio', 'mime' => 'audio/webm', 'name' => 'voice.webm'],
            ['kind' => 'video', 'file' => UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4'), 'type' => 'video', 'mime' => 'video/mp4', 'name' => 'clip.mp4'],
            ['kind' => 'file', 'file' => UploadedFile::fake()->create('عرض.pdf', 40, 'application/pdf'), 'type' => 'file', 'mime' => 'application/pdf', 'name' => 'عرض.pdf'],
        ];

        foreach ($cases as $case) {
            $payload = [
                'kind' => $case['kind'],
                'attachment' => $case['file'],
            ];

            if ($case['kind'] !== 'audio') {
                $payload['body'] = 'مع المرفق';
            }

            if (in_array($case['kind'], ['video', 'file'], true)) {
                $payload['thumbnail'] = UploadedFile::fake()->image('poster.jpg', 32, 24);
            }

            $this->actingAs($buyerUser, 'sanctum')
                ->post("/api/v1/conversations/{$conversation->id}/messages", $payload, ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('message.type', $case['type'])
                ->assertJsonPath('message.attachment_name', $case['name'])
                ->assertJsonPath('message.attachment_mime', $case['mime'])
                ->assertJsonPath('message.body', $case['kind'] === 'audio' ? null : 'مع المرفق');
        }

        $this->assertSame(4, Message::count());

        foreach (Message::all() as $message) {
            Storage::disk('public')->assertExists($message->attachment_path);
            $this->assertNotNull($message->attachmentUrl());
        }

        $video = Message::where('type', 'video')->firstOrFail();
        $pdf = Message::where('attachment_mime', 'application/pdf')->firstOrFail();
        $audio = Message::where('type', 'audio')->firstOrFail();

        Storage::disk('public')->assertExists($video->thumbnail_path);
        Storage::disk('public')->assertExists($pdf->thumbnail_path);
        $this->assertNotNull($video->thumbnailUrl());
        $this->assertNull($audio->thumbnail_path);

        $this->actingAs($buyerUser, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_a_disallowed_or_oversized_attachment_is_rejected(): void
    {
        Storage::fake('public');

        [$supplier, $buyer, , $buyerUser] = $this->actors();
        $conversation = app(ConversationService::class)->between($buyer, $supplier);

        $this->actingAs($buyerUser, 'sanctum')
            ->post("/api/v1/conversations/{$conversation->id}/messages", [
                'attachment' => UploadedFile::fake()->create('shell.php', 8, 'application/x-php'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');

        $this->actingAs($buyerUser, 'sanctum')
            ->post("/api/v1/conversations/{$conversation->id}/messages", [
                'kind' => 'image',
                'attachment' => UploadedFile::fake()->image('big.jpg')->size(9000),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');

        $this->assertSame(0, Message::count());
    }

    public function test_the_sender_sees_when_the_other_side_has_read(): void
    {
        Event::fake([ConversationRead::class]);

        [$supplier, $buyer, $supplierUser, $buyerUser] = $this->actors();
        $conversation = app(ConversationService::class)->between($buyer, $supplier);
        app(ConversationService::class)->send($conversation, $buyerUser, ['body' => 'هل وصل العرض؟']);

        $this->actingAs($buyerUser, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.seen', false);

        $this->actingAs($supplierUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('ok', true);

        Event::assertDispatched(ConversationRead::class);

        $this->actingAs($supplierUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/read")
            ->assertOk();

        // الإرسال نفسه يبث قراءةً واحدة، وفتح الطرف الآخر يبث الثانية. التكرار لا يبث ثالثة.
        Event::assertDispatchedTimes(ConversationRead::class, 2);

        $this->actingAs($buyerUser, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.seen', true)
            ->assertJsonPath('data.0.is_mine', true);
    }

    public function test_typing_is_broadcast_only_to_participants(): void
    {
        Event::fake([ConversationTyping::class]);

        [$supplier, $buyer, $supplierUser, $buyerUser] = $this->actors();
        $conversation = app(ConversationService::class)->between($buyer, $supplier);
        $outsider = $this->makeSupplier('Outsider');
        $outsiderUser = User::create([
            'tenant_id' => $outsider->id,
            'name' => 'طرف ثالث',
            'email' => 'typing'.$outsider->id.'@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($buyerUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/typing", ['typing' => true])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Event::assertDispatched(ConversationTyping::class, function (ConversationTyping $event) use ($buyer, $conversation) {
            return $event->typing === true
                && $event->tenantId === $buyer->id
                && $event->conversation->id === $conversation->id;
        });

        $this->actingAs($outsiderUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/typing", ['typing' => true])
            ->assertForbidden();

        $this->actingAs($supplierUser, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/typing", [])
            ->assertStatus(422);
    }
}
