<?php

namespace App\Notifications;

use App\Models\Communication\Message;
use App\Models\User;
use Illuminate\Support\Str;

class NewMessageNotification extends PlatformNotification
{
    public function __construct(
        private readonly Message $message,
        private readonly User $sender,
    ) {}

    public function event(): string
    {
        return 'message.received';
    }

    public function icon(): string
    {
        return 'message-circle';
    }

    public function title(): array
    {
        $company = $this->sender->tenant?->name ?? $this->sender->name;

        return [
            'ar' => "رسالة جديدة من {$company}",
            'en' => "New message from {$company}",
        ];
    }

    public function body(): array
    {
        if ($this->message->body) {
            $excerpt = Str::limit($this->message->body, 120);

            return ['ar' => $excerpt, 'en' => $excerpt];
        }

        return match ($this->message->type) {
            'image' => ['ar' => 'صورة', 'en' => 'Photo'],
            'audio' => ['ar' => 'رسالة صوتية', 'en' => 'Voice message'],
            'video' => ['ar' => 'فيديو', 'en' => 'Video'],
            default => ['ar' => 'مرفق', 'en' => 'Attachment'],
        };
    }

    public function actionUrl(): ?string
    {
        return "/messages/{$this->message->conversation_id}";
    }

    public function payload(): array
    {
        return [
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
        ];
    }

    public function mailKey(): ?string
    {
        return 'message';
    }

    public function mailVariables(User $user): array
    {
        $locale = $user->locale === 'en' ? 'en' : 'ar';

        return [
            'sender' => $this->sender->tenant?->name ?? $this->sender->name,
            'excerpt' => $this->body()[$locale] ?? '',
        ];
    }
}
