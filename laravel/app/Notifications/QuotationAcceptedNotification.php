<?php

namespace App\Notifications;

use App\Models\Orders\Order;
use App\Models\Rfq\Quotation;
use App\Models\User;

/** يصل للمستورد عندما يُقبل عرضه ويتحوّل إلى أمر شراء. */
class QuotationAcceptedNotification extends PlatformNotification
{
    public function __construct(
        private readonly Quotation $quotation,
        private readonly Order $order,
    ) {}

    public function event(): string
    {
        return 'quotation.accepted';
    }

    public function icon(): string
    {
        return 'check-circle';
    }

    public function title(): array
    {
        return [
            'ar' => 'تم قبول عرض السعر',
            'en' => 'Your quotation was accepted',
        ];
    }

    public function body(): array
    {
        $amount = number_format($this->order->total / 100).' '.$this->order->currency;

        return [
            'ar' => "أمر الشراء {$this->order->order_no} بقيمة {$amount}",
            'en' => "Purchase order {$this->order->order_no} for {$amount}",
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
            'quotation_id' => $this->quotation->id,
        ];
    }

    public function mailKey(): ?string
    {
        return 'accepted';
    }

    public function mailVariables(User $user): array
    {
        return [
            'order_no' => $this->order->order_no,
            'amount' => number_format($this->order->total / 100, 2).' '.$this->order->currency,
        ];
    }
}
