<?php

namespace App\Notifications;

use App\Models\Returns\Dispute;
use App\Models\User;

class DisputeUpdateNotification extends PlatformNotification
{
    private const LABELS = [
        'opened' => ['ar' => 'فُتح نزاع على طلبك', 'en' => 'A dispute was opened on your order'],
        'message' => ['ar' => 'رسالة جديدة في النزاع', 'en' => 'New message in the dispute'],
        'awaiting_party' => ['ar' => 'المنصة تطلب إيضاحاً', 'en' => 'The platform requested a clarification'],
        'resolved' => ['ar' => 'صدر قرار في النزاع', 'en' => 'The dispute has been decided'],
    ];

    public function __construct(
        private readonly Dispute $dispute,
        private readonly string $event,
    ) {}

    public function event(): string
    {
        return 'dispute.update';
    }

    public function icon(): string
    {
        return 'scale';
    }

    public function title(): array
    {
        return self::LABELS[$this->event] ?? ['ar' => 'تحديث نزاع', 'en' => 'Dispute update'];
    }

    public function body(): array
    {
        $amount = number_format($this->dispute->claimed_amount / 100).' '.$this->dispute->currency;

        return [
            'ar' => "{$this->dispute->reference_no} · المطالبة {$amount}",
            'en' => "{$this->dispute->reference_no} · claim {$amount}",
        ];
    }

    public function actionUrl(): ?string
    {
        return "/returns/disputes/{$this->dispute->id}";
    }

    public function payload(): array
    {
        return [
            'dispute_id' => $this->dispute->id,
            'order_id' => $this->dispute->order_id,
            'status' => $this->dispute->status,
        ];
    }

    public function mailKey(): ?string
    {
        return 'dispute';
    }

    public function mailVariables(User $user): array
    {
        $locale = $user->locale === 'en' ? 'en' : 'ar';
        $label = self::LABELS[$this->event][$locale] ?? ($locale === 'en' ? 'Dispute update' : 'تحديث نزاع');

        return [
            'reference' => $this->dispute->reference_no,
            'status' => $label,
            'amount' => number_format($this->dispute->claimed_amount / 100, 2).' '.$this->dispute->currency,
        ];
    }
}
