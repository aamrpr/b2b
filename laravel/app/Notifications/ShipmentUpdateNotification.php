<?php

namespace App\Notifications;

use App\Models\Shipping\Shipment;
use App\Models\User;

class ShipmentUpdateNotification extends PlatformNotification
{
    private const LABELS = [
        'ready' => ['ar' => 'جاهزة للاستلام', 'en' => 'Ready for pickup'],
        'picked_up' => ['ar' => 'استلمتها شركة الشحن', 'en' => 'Picked up'],
        'in_transit' => ['ar' => 'في الطريق', 'en' => 'In transit'],
        'out_for_delivery' => ['ar' => 'خرجت للتسليم', 'en' => 'Out for delivery'],
        'delivered' => ['ar' => 'تم التسليم', 'en' => 'Delivered'],
        'failed' => ['ar' => 'تعذّر التسليم', 'en' => 'Delivery failed'],
        'returned' => ['ar' => 'أُعيدت', 'en' => 'Returned'],
    ];

    public function __construct(
        private readonly Shipment $shipment,
        private readonly string $status,
    ) {}

    public function event(): string
    {
        return 'shipment.update';
    }

    public function icon(): string
    {
        return 'truck';
    }

    public function title(): array
    {
        return [
            'ar' => "تحديث على الشحنة {$this->shipment->shipment_no}",
            'en' => "Update on shipment {$this->shipment->shipment_no}",
        ];
    }

    public function body(): array
    {
        $label = self::LABELS[$this->status] ?? ['ar' => $this->status, 'en' => $this->status];

        return [
            'ar' => $label['ar'].($this->shipment->tracking_number ? " · رقم التتبّع {$this->shipment->tracking_number}" : ''),
            'en' => $label['en'].($this->shipment->tracking_number ? " · tracking {$this->shipment->tracking_number}" : ''),
        ];
    }

    public function actionUrl(): ?string
    {
        return "/track/{$this->shipment->tracking_token}";
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->shipment->id,
            'order_id' => $this->shipment->order_id,
            'status' => $this->status,
            'tracking_token' => $this->shipment->tracking_token,
        ];
    }

    public function mailKey(): ?string
    {
        return 'shipment';
    }

    public function mailVariables(User $user): array
    {
        $locale = $user->locale === 'en' ? 'en' : 'ar';
        $label = self::LABELS[$this->status][$locale] ?? $this->status;

        return [
            'shipment_no' => $this->shipment->shipment_no,
            'status' => $label,
            'tracking' => $this->shipment->tracking_number ?? '',
        ];
    }
}
