<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentEvent;
use Illuminate\Http\JsonResponse;

/**
 * تتبّع عام بلا تسجيل دخول.
 *
 * المفتاح رمز عشوائي لا رقم التتبّع: الأرقام متسلسلة وقابلة للتخمين،
 * ومعها يستطيع أي شخص تصفّح شحنات الآخرين. والرد مختصر عمداً —
 * لا أسعار ولا أطراف، فالرابط يُشارَك مع السائق أحياناً.
 */
class TrackingController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $shipment = Shipment::withoutGlobalScope('tenant')
            ->where('tracking_token', $token)
            ->with(['carrier', 'events.recorder', 'order'])
            ->first();

        if (! $shipment) {
            return response()->json(['message' => __('لم يُعثر على شحنة بهذا الرمز.')], 404);
        }

        return response()->json([
            'shipment' => [
                'shipment_no' => $shipment->shipment_no,
                'order_no' => $shipment->order?->order_no,
                'carrier' => $shipment->carrier?->t('name'),
                'carrier_phone' => $shipment->carrier?->phone,
                'carrier_tracking_url' => $shipment->carrierTrackingUrl(),
                'tracking_number' => $shipment->tracking_number,
                'status' => $shipment->status,
                'packages_count' => $shipment->packages_count,
                'destination_city' => $shipment->destination_address['city'] ?? null,
                'estimated_delivery' => $shipment->estimated_delivery?->toDateString(),
                'shipped_at' => $shipment->shipped_at?->toIso8601String(),
                'delivered_at' => $shipment->delivered_at?->toIso8601String(),
                'received_by' => $shipment->received_by,
            ],
            'events' => $shipment->events->map(fn (ShipmentEvent $event) => [
                'status' => $event->status,
                'location' => $event->location,
                'description' => $event->t('description'),
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ]),
        ]);
    }
}
