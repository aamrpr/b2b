<?php

namespace App\Events;

use App\Models\Communication\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * يُبثّ لكل طرف في المحادثة على قناته الخاصة،
 * فلا يحتاج الطرفان لقناة مشتركة يمكن التنصّت عليها.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->message->conversation
            ->participants
            ->map(fn ($participant) => new PrivateChannel("tenant.{$participant->tenant_id}"))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        $message = $this->message->loadMissing('sender', 'senderTenant');

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_tenant_id' => $message->sender_tenant_id,
            'sender_name' => $message->sender?->name,
            'sender_company' => $message->senderTenant?->name,
            'body' => $message->body,
            'type' => $message->type,
            'attachment_url' => $message->attachmentUrl(),
            'attachment_name' => $message->attachment_name,
            'attachment_mime' => $message->attachment_mime,
            'thumbnail_url' => $message->thumbnailUrl(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
