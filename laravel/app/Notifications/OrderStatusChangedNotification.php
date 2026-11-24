<?php

namespace App\Notifications;

use App\Models\Orders\Order;
use App\Models\User;

class OrderStatusChangedNotification extends PlatformNotification
{
    private const LABELS = [
        'confirmed' => ['ar' => 'مؤكَّد', 'en' => 'Confirmed'],
        'in_production' => ['ar' => 'قيد التجهيز', 'en' => 'In production'],
        'shipped' => ['ar' => 'تم الشحن', 'en' => 'Shipped'],
        'delivered' => ['ar' => 'تم التسليم', 'en' => 'Delivered'],
        'closed' => ['ar' => 'مغلق', 'en' => 'Closed'],
        'cancelled' => ['ar' => 'ملغى', 'en' => 'Cancelled'],
    ];

    public function __construct(
        private readonly Order $order,
        private readonly string $status,
    ) {}

    public function event(): string
    {
        return 'order.status';
    }

    public function icon(): string
    {
        return 'package';
    }

    public function title(): array
    {
        return [
            'ar' => "تحديث على الطلب {$this->order->order_no}",
            'en' => "Update on order {$this->order->order_no}",
        ];
    }

    public function body(): array
    {
        $label = self::LABELS[$this->status] ?? ['ar' => $this->status, 'en' => $this->status];

        return [
            'ar' => "الحالة الآن: {$label['ar']}",
            'en' => "Status is now: {$label['en']}",
        ];
    }

    public function actionUrl(): ?string
    {
        return '/orders';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_no' => $this->order->order_no,
            'status' => $this->status,
        ];
    }

    public function mailKey(): ?string
    {
        return 'order';
    }

    public function mailVariables(User $user): array
    {
        $locale = $user->locale === 'en' ? 'en' : 'ar';
        $label = self::LABELS[$this->status][$locale] ?? $this->status;

        return [
            'order_no' => $this->order->order_no,
            'status' => $label,
            'amount' => number_format($this->order->total / 100, 2).' '.$this->order->currency,
        ];
    }
}
