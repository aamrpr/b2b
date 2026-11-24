<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_tenant_id' => $this->sender_tenant_id,
            'sender_name' => $this->whenLoaded('sender', fn () => $this->sender->name),
            'sender_company' => $this->whenLoaded('senderTenant', fn () => $this->senderTenant->name),
            'body' => $this->body,
            'type' => $this->type,
            'attachment_url' => $this->attachmentUrl(),
            'attachment_name' => $this->attachment_name,
            'attachment_mime' => $this->attachment_mime,
            'thumbnail_url' => $this->thumbnailUrl(),
            'is_mine' => $this->sender_tenant_id === $request->user()?->tenant_id,
            'seen' => $this->sender_tenant_id === $request->user()?->tenant_id
                && (int) $this->id <= (int) $request->attributes->get('counterpart_read_up_to', 0),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
