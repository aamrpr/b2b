<?php

namespace App\Services\Communication;

use App\Events\ConversationRead;
use App\Events\MessageSent;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationParticipant;
use App\Models\Communication\Message;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    /**
     * محادثة واحدة لكل زوج طرفين ضمن نفس السياق.
     * مراسلة مستورد من صفحته تفتح محادثة عامة (بلا سياق)،
     * ومراسلته حول طلب معيّن تفتح محادثة مرتبطة بذلك الطلب.
     */
    public function between(Tenant $first, Tenant $second, ?Model $context = null, ?string $subject = null): Conversation
    {
        $contextType = $context ? $context::class : null;
        $contextId = $context?->getKey();

        $existing = Conversation::query()
            ->where('context_type', $contextType)
            ->where('context_id', $contextId)
            ->whereHas('participants', fn ($q) => $q->where('tenant_id', $first->id))
            ->whereHas('participants', fn ($q) => $q->where('tenant_id', $second->id))
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($first, $second, $contextType, $contextId, $subject) {
            $conversation = Conversation::create([
                'subject' => $subject,
                'context_type' => $contextType,
                'context_id' => $contextId,
            ]);

            foreach ([$first, $second] as $tenant) {
                ConversationParticipant::create([
                    'conversation_id' => $conversation->id,
                    'tenant_id' => $tenant->id,
                ]);
            }

            return $conversation->load('participants');
        });
    }

    public function send(Conversation $conversation, User $sender, array $attributes): Message
    {
        $message = DB::transaction(function () use ($conversation, $sender, $attributes) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_tenant_id' => $sender->tenant_id,
                'sender_id' => $sender->id,
                'body' => $attributes['body'] ?? null,
                'type' => $attributes['type'] ?? 'text',
                'attachment_path' => $attributes['attachment_path'] ?? null,
                'attachment_name' => $attributes['attachment_name'] ?? null,
                'attachment_mime' => $attributes['attachment_mime'] ?? null,
                'thumbnail_path' => $attributes['thumbnail_path'] ?? null,
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            // المرسِل قرأ رسالته بالتأكيد
            ConversationParticipant::where('conversation_id', $conversation->id)
                ->where('tenant_id', $sender->tenant_id)
                ->update([
                    'last_read_at' => $message->created_at,
                    'last_read_message_id' => $message->id,
                ]);

            return $message;
        });

        $conversation->loadMissing('participants');

        broadcast(new MessageSent($message))->toOthers();
        // الرد يعني أن ما قبله قُرئ، فتُحدَّث علامة المشاهدة عند الطرف الآخر
        broadcast(new ConversationRead($conversation, (int) $sender->tenant_id, $message->id));

        $this->notifyRecipients($conversation, $message, $sender);

        return $message;
    }

    public function markRead(Conversation $conversation, int $tenantId): void
    {
        $lastMessageId = (int) $conversation->messages()->max('id');

        if ($lastMessageId === 0) {
            return;
        }

        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('tenant_id', $tenantId)
            ->first();

        // لا نعيد البث إن كان المؤشر عند آخر رسالة: فتح المحادثة يتكرّر
        if (! $participant || (int) $participant->last_read_message_id >= $lastMessageId) {
            return;
        }

        $participant->update([
            'last_read_at' => now(),
            'last_read_message_id' => $lastMessageId,
        ]);

        $conversation->loadMissing('participants');
        broadcast(new ConversationRead($conversation, $tenantId, $lastMessageId));
    }

    /** عدد المحادثات التي تحوي رسائل غير مقروءة لمستأجر. */
    public function unreadConversationCount(int $tenantId): int
    {
        return Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereHas('messages', function ($q) use ($tenantId) {
                $q->where('sender_tenant_id', '!=', $tenantId)
                    ->whereRaw(
                        'messages.id > COALESCE((
                            SELECT last_read_message_id FROM conversation_participants
                            WHERE conversation_id = conversations.id AND tenant_id = ?
                        ), 0)',
                        [$tenantId]
                    );
            })
            ->count();
    }

    private function notifyRecipients(Conversation $conversation, Message $message, User $sender): void
    {
        $recipientTenantIds = $conversation->participants
            ->pluck('tenant_id')
            ->reject(fn ($id) => $id === $sender->tenant_id);

        $users = User::whereIn('tenant_id', $recipientTenantIds)
            ->where('status', 'active')
            ->get();

        foreach ($users as $user) {
            $user->notify(new NewMessageNotification($message, $sender));
        }
    }
}
