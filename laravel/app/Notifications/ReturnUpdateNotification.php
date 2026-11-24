<?php

namespace App\Notifications;

use App\Models\Returns\ReturnRequest;
use App\Models\User;

class ReturnUpdateNotification extends PlatformNotification
{
    private const LABELS = [
        'requested' => ['ar' => 'طلب مرتجع جديد', 'en' => 'New return request'],
        'approved' => ['ar' => 'اعتُمد طلب المرتجع', 'en' => 'Return request approved'],
        'rejected' => ['ar' => 'رُفض طلب المرتجع', 'en' => 'Return request rejected'],
        'in_transit' => ['ar' => 'البضاعة المرتجعة في الطريق', 'en' => 'Returned goods in transit'],
        'completed' => ['ar' => 'أُقفل المرتجع وصدر إشعار دائن', 'en' => 'Return closed with a credit note'],
    ];

    public function __construct(
        private readonly ReturnRequest $request,
        private readonly string $status,
    ) {}

    public function event(): string
    {
        return 'return.update';
    }

    public function icon(): string
    {
        return 'rotate-ccw';
    }

    public function title(): array
    {
        return self::LABELS[$this->status] ?? ['ar' => 'تحديث مرتجع', 'en' => 'Return update'];
    }

    public function body(): array
    {
        $amount = number_format($this->request->requested_amount / 100).' '.$this->request->currency;

        return [
            'ar' => "{$this->request->reference_no} · {$amount}",
            'en' => "{$this->request->reference_no} · {$amount}",
        ];
    }

    public function actionUrl(): ?string
    {
        return "/returns/{$this->request->id}";
    }

    public function payload(): array
    {
        return [
            'return_request_id' => $this->request->id,
            'order_id' => $this->request->order_id,
            'status' => $this->status,
        ];
    }

    public function mailKey(): ?string
    {
        return 'returns';
    }

    public function mailVariables(User $user): array
    {
        $locale = $user->locale === 'en' ? 'en' : 'ar';
        $label = self::LABELS[$this->status][$locale] ?? ($locale === 'en' ? 'Return update' : 'تحديث مرتجع');

        return [
            'reference' => $this->request->reference_no,
            'status' => $label,
            'amount' => number_format($this->request->requested_amount / 100, 2).' '.$this->request->currency,
        ];
    }
}
