<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function __construct($resource, private readonly ?int $viewerTenantId = null)
    {
        parent::__construct($resource);
    }

    public function toArray($request): array
    {
        $viewerId = $this->viewerTenantId ?? $request->user()?->tenant_id;
        $counterpart = $viewerId ? $this->counterpart($viewerId) : null;
        $latest = $this->whenLoaded('latestMessage');

        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'context' => $this->context_type
                ? ['type' => class_basename($this->context_type), 'id' => $this->context_id]
                : null,
            'counterpart' => $counterpart ? [
                'id' => $counterpart->id,
                'name' => $counterpart->name,
                'slug' => $counterpart->slug,
                'type' => $counterpart->type,
            ] : null,
            'last_message' => $latest && $this->latestMessage ? [
                'body' => $this->latestMessage->body,
                'type' => $this->latestMessage->type,
                'attachment_mime' => $this->latestMessage->attachment_mime,
                'sender_tenant_id' => $this->latestMessage->sender_tenant_id,
                'created_at' => $this->latestMessage->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => $viewerId ? $this->unreadCountFor($viewerId) : 0,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}
