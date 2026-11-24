<?php

namespace App\Notifications;

use App\Models\Rfq\Rfq;
use App\Models\User;

/**
 * يصل للمستورد عندما يُنشر طلب عرض سعر في أحد أصنافه.
 * هذا هو الإشعار الذي يحرّك السوق كله.
 */
class RfqBroadcastNotification extends PlatformNotification
{
    public function __construct(private readonly Rfq $rfq) {}

    public function event(): string
    {
        return 'rfq.broadcast';
    }

    public function icon(): string
    {
        return 'file-search';
    }

    public function title(): array
    {
        return [
            'ar' => 'طلب عرض سعر جديد في أصنافك',
            'en' => 'New quotation request in your categories',
        ];
    }

    public function body(): array
    {
        $category = $this->rfq->category;

        return [
            'ar' => $this->rfq->title.' — '.($category?->t('name', 'ar') ?? ''),
            'en' => $this->rfq->title.' — '.($category?->t('name', 'en') ?? ''),
        ];
    }

    public function actionUrl(): ?string
    {
        return "/rfqs/{$this->rfq->id}/quote";
    }

    public function payload(): array
    {
        return [
            'rfq_id' => $this->rfq->id,
            'reference_no' => $this->rfq->reference_no,
            'expires_at' => $this->rfq->expires_at?->toIso8601String(),
        ];
    }

    public function mailKey(): ?string
    {
        return 'rfqinbox';
    }

    public function mailVariables(User $user): array
    {
        $locale = $user->locale === 'en' ? 'en' : 'ar';

        return [
            'title' => $this->rfq->title,
            'reference' => $this->rfq->reference_no,
            'category' => $this->rfq->category?->t('name', $locale) ?? '',
        ];
    }
}
