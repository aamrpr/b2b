<?php

namespace App\Services\Shipping;

use App\Exceptions\BusinessRuleException;
use App\Models\Orders\Order;
use App\Models\Shipping\Carrier;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentEvent;
use App\Models\Shipping\ShipmentItem;
use App\Models\User;
use App\Notifications\ShipmentUpdateNotification;
use App\Services\Accounting\DocumentSequencer;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Services\Orders\OrderFulfillmentService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShipmentService
{
    public function __construct(
        private readonly DocumentSequencer $sequencer,
        private readonly JournalPoster $poster,
        private readonly OrderFulfillmentService $fulfillment,
    ) {}

    /** الكميات التي لم تُشحَن بعد من كل سطر. */
    public function pendingQuantities(Order $order): array
    {
        $shipped = ShipmentItem::query()
            ->whereHas('shipment', fn ($q) => $q->where('order_id', $order->id)
                ->whereNotIn('status', ['cancelled', 'returned']))
            ->groupBy('order_item_id')
            ->selectRaw('order_item_id, SUM(quantity) AS shipped')
            ->pluck('shipped', 'order_item_id');

        return $order->items
            ->mapWithKeys(fn ($line) => [
                $line->id => $line->quantity - (int) ($shipped[$line->id] ?? 0),
            ])
            ->all();
    }

    public function create(Order $order, User $actor, array $data): Shipment
    {
        $pending = $this->pendingQuantities($order);

        $lines = collect($data['items'] ?? [])
            ->filter(fn ($row) => ($row['quantity'] ?? 0) > 0)
            ->values();

        // بلا تحديد أصناف: تُشحَن كل الكميات المتبقية
        if ($lines->isEmpty()) {
            $lines = collect($pending)
                ->filter(fn ($quantity) => $quantity > 0)
                ->map(fn ($quantity, $id) => ['order_item_id' => $id, 'quantity' => $quantity])
                ->values();
        }

        if ($lines->isEmpty()) {
            throw new BusinessRuleException('كل أصناف هذا الطلب مشحونة بالفعل.');
        }

        foreach ($lines as $row) {
            $available = $pending[$row['order_item_id']] ?? 0;

            if ($row['quantity'] > $available) {
                throw new BusinessRuleException(
                    "الكمية المطلوب شحنها ({$row['quantity']}) تتجاوز المتبقي ({$available})."
                );
            }
        }

        return DB::transaction(function () use ($order, $actor, $data, $lines) {
            $carrier = isset($data['carrier_id']) ? Carrier::find($data['carrier_id']) : null;

            $shipment = new Shipment([
                'order_id' => $order->id,
                'carrier_id' => $carrier?->id,
                'shipment_no' => $this->sequencer->next($order->supplier_tenant_id, 'shipment', 'SHP'),
                'tracking_number' => $data['tracking_number'] ?? null,
                'tracking_token' => Str::lower(Str::random(32)),
                'incoterm' => $data['incoterm'] ?? $order->incoterm,
                'origin_address' => $data['origin_address'] ?? null,
                'destination_address' => $data['destination_address'] ?? $order->shipping_address,
                'weight_kg' => $data['weight_kg'] ?? null,
                'packages_count' => $data['packages_count'] ?? 1,
                'shipping_cost' => $data['shipping_cost'] ?? 0,
                'currency' => $data['currency'] ?? $order->currency,
                'is_cod' => $data['is_cod'] ?? false,
                'cod_amount' => $data['cod_amount'] ?? 0,
                'estimated_delivery' => $data['estimated_delivery'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
            $shipment->tenant_id = $order->supplier_tenant_id;
            $shipment->save();

            foreach ($lines as $row) {
                ShipmentItem::create([
                    'shipment_id' => $shipment->id,
                    'order_item_id' => $row['order_item_id'],
                    'quantity' => $row['quantity'],
                ]);
            }

            $this->appendEvent($shipment, 'draft', [
                'ar' => 'أُنشئت الشحنة',
                'en' => 'Shipment created',
            ], $data['origin_address']['city'] ?? null, $actor);

            return $shipment->load('items', 'carrier');
        });
    }

    /**
     * نقل الشحنة إلى حالة جديدة مع تسجيل الحدث ومزامنة الطلب.
     */
    public function transition(
        Shipment $shipment,
        string $status,
        ?User $actor = null,
        ?string $location = null,
        ?array $description = null,
        ?string $receivedBy = null,
    ): Shipment {
        if (! $shipment->canMoveTo($status)) {
            throw new BusinessRuleException(
                "لا يمكن نقل الشحنة من '{$shipment->status}' إلى '{$status}'."
            );
        }

        DB::transaction(function () use ($shipment, $status, $actor, $location, $description, $receivedBy) {
            $shipment->status = $status;

            match ($status) {
                'picked_up' => $shipment->shipped_at ??= now(),
                'delivered' => $shipment->delivered_at ??= now(),
                default => null,
            };

            if ($receivedBy) {
                $shipment->received_by = $receivedBy;
            }

            $shipment->save();

            $this->appendEvent($shipment, $status, $description ?? $this->defaultDescription($status), $location, $actor);
        });

        $this->syncOrder($shipment->fresh()->load('order.items'), $actor);
        $this->notifyBuyer($shipment, $status);

        return $shipment->refresh();
    }

    /** حدث تتبّع وسيط بلا تغيير حالة — "وصلت الشحنة إلى حمص". */
    public function addEvent(Shipment $shipment, User $actor, ?string $location, ?array $description): ShipmentEvent
    {
        return $this->appendEvent($shipment, $shipment->status, $description, $location, $actor);
    }

    /** ترحيل تكلفة الشحن المدفوعة لشركة النقل. */
    public function postShippingCost(Shipment $shipment, string $counterAccount = 'accounts_payable'): void
    {
        if ($shipment->shipping_cost <= 0 || $shipment->journal_entry_id) {
            return;
        }

        $amount = Money::of($shipment->shipping_cost, $shipment->currency);

        $entry = $this->poster->post(
            JournalDraft::for($shipment->tenant, $amount->currency)
                ->describe("تكلفة شحن {$shipment->shipment_no}")
                ->from($shipment)
                ->idempotent("shipping_cost:shipment:{$shipment->id}")
                ->debit('shipping_expense', $amount)
                ->credit($counterAccount, $amount)
        );

        $shipment->forceFill(['journal_entry_id' => $entry->id])->save();
    }

    /**
     * مزامنة حالة الطلب مع شحناته:
     * أول شحنة تُسلَّم للناقل ⟵ الطلب "تم الشحن"،
     * وتسليم كل الكميات ⟵ الطلب "تم التسليم" وتُرحَّل ماليّاته.
     */
    private function syncOrder(Shipment $shipment, ?User $actor): void
    {
        $order = $shipment->order;

        if (! $order) {
            return;
        }

        if (in_array($shipment->status, ['picked_up', 'in_transit', 'out_for_delivery'], true)
            && in_array($order->status, ['confirmed', 'in_production'], true)) {
            $this->fulfillment->transition($order, 'shipped', $actor, "شحنة {$shipment->shipment_no}");

            return;
        }

        if ($shipment->status === 'delivered' && $this->isFullyDelivered($order)) {
            $this->fulfillment->transition($order, 'delivered', $actor, "تسليم الشحنة {$shipment->shipment_no}");
        }
    }

    private function isFullyDelivered(Order $order): bool
    {
        $delivered = ShipmentItem::query()
            ->whereHas('shipment', fn ($q) => $q->where('order_id', $order->id)->where('status', 'delivered'))
            ->groupBy('order_item_id')
            ->selectRaw('order_item_id, SUM(quantity) AS delivered')
            ->pluck('delivered', 'order_item_id');

        foreach ($order->items as $line) {
            if ((int) ($delivered[$line->id] ?? 0) < $line->quantity) {
                return false;
            }
        }

        return true;
    }

    private function appendEvent(
        Shipment $shipment,
        string $status,
        ?array $description,
        ?string $location,
        ?User $actor,
    ): ShipmentEvent {
        return ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => $status,
            'location' => $location,
            'description' => $description,
            'occurred_at' => now(),
            'recorded_by' => $actor?->id,
            'source' => 'manual',
        ]);
    }

    private function defaultDescription(string $status): array
    {
        return match ($status) {
            'ready' => ['ar' => 'الشحنة جاهزة للاستلام', 'en' => 'Ready for pickup'],
            'picked_up' => ['ar' => 'استلمتها شركة الشحن', 'en' => 'Picked up by carrier'],
            'in_transit' => ['ar' => 'الشحنة في الطريق', 'en' => 'In transit'],
            'out_for_delivery' => ['ar' => 'خرجت للتسليم', 'en' => 'Out for delivery'],
            'delivered' => ['ar' => 'تم التسليم', 'en' => 'Delivered'],
            'failed' => ['ar' => 'تعذّر التسليم', 'en' => 'Delivery failed'],
            'returned' => ['ar' => 'أُعيدت الشحنة', 'en' => 'Returned to sender'],
            'cancelled' => ['ar' => 'أُلغيت الشحنة', 'en' => 'Cancelled'],
            default => ['ar' => 'تحديث', 'en' => 'Update'],
        };
    }

    private function notifyBuyer(Shipment $shipment, string $status): void
    {
        $order = $shipment->order;

        if (! $order) {
            return;
        }

        User::where('tenant_id', $order->buyer_tenant_id)
            ->where('status', 'active')
            ->get()
            ->each(fn (User $user) => $user->notify(new ShipmentUpdateNotification($shipment, $status)));
    }
}
