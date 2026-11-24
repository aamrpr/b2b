<?php

namespace App\Events;

use App\Models\Communication\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** علامة المشاهدة: قرأ الطرف الرسائل حتى هذا المعرّف. */
class ConversationRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly int $tenantId,
        public readonly int $messageId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->conversation->participants
            ->map(fn ($participant) => new PrivateChannel("tenant.{$participant->tenant_id}"))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'tenant_id' => $this->tenantId,
            'message_id' => $this->messageId,
        ];
    }
}
