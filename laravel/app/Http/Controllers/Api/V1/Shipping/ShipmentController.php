<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Models\Orders\Order;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentDocument;
use App\Models\Shipping\ShipmentEvent;
use App\Services\Shipping\ShipmentService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function __construct(private readonly ShipmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $query = Shipment::withoutGlobalScope('tenant')
            ->when($tenant->isSupplier(), fn ($q) => $q->where('tenant_id', $tenant->id))
            ->when($tenant->isBuyer(), fn ($q) => $q->whereHas('order', fn ($o) => $o->where('buyer_tenant_id', $tenant->id)))
            ->with(['carrier', 'order']);

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['shipment_no', 'tracking_number'])
                ->sortable([
                    'id' => 'id',
                    'shipment_no' => 'shipment_no',
                    'status' => 'status',
                    'estimated_delivery' => 'estimated_delivery',
                    'shipped_at' => 'shipped_at',
                    'created_at' => 'created_at',
                ])
                ->filter('status', fn ($q, $value) => $q->where('status', $value))
                ->filter('order', fn ($q, $value) => $q->whereHas('order', fn ($o) => $o->where('order_no', 'like', "%{$value}%")))
                ->filter('shipping_cost', fn ($q, $value) => ColumnMatch::money($q, 'shipping_cost', $value))
                ->filter('carrier', function ($q, $value) {
                    // الرقم يبقى معرّف الشركة. والنص يطابق اسمها كما يُعرض في العمود
                    if (ctype_digit($value)) {
                        $q->where('carrier_id', $value);

                        return;
                    }

                    $q->whereHas('carrier', fn ($c) => $c->where('name', 'like', "%{$value}%"));
                })
                ->defaultOrder('id')
                ->transform(fn (Shipment $shipment) => $this->summary($shipment))
                ->toArray()
        );
    }

    /** الكميات المتبقية للشحن من طلب — تملأ نموذج الشحنة. */
    public function pending(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $order->load('items');
        $pending = $this->service->pendingQuantities($order);

        return response()->json([
            'order_no' => $order->order_no,
            'items' => $order->items->map(fn ($line) => [
                'order_item_id' => $line->id,
                'description' => $line->description,
                'ordered' => $line->quantity,
                'pending' => $pending[$line->id] ?? 0,
                'unit' => $line->unit,
            ]),
        ]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->supplier_tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'carrier_id' => ['nullable', 'integer', 'exists:carriers,id'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'origin_address' => ['nullable', 'array'],
            'destination_address' => ['nullable', 'array'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'packages_count' => ['nullable', 'integer', 'min:1'],
            'shipping_cost' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_cod' => ['nullable', 'boolean'],
            'cod_amount' => ['nullable', 'integer', 'min:0'],
            'estimated_delivery' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.order_item_id' => ['required_with:items', 'integer', 'exists:order_items,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ]);

        $order->load('items');

        $shipment = $this->service->create($order, $request->user(), $data);

        if ($shipment->shipping_cost > 0) {
            $this->service->postShippingCost($shipment);
        }

        return response()->json([
            'message' => __('تم إنشاء الشحنة.'),
            'shipment' => $this->detail($shipment->fresh()->load('carrier', 'items.orderItem', 'events', 'documents', 'order')),
        ], 201);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        $this->authorizeShipment($request, $shipment);

        return response()->json([
            'shipment' => $this->detail($shipment->load('carrier', 'items.orderItem', 'events.recorder', 'documents', 'order')),
        ]);
    }

    public function transition(Request $request, Shipment $shipment): JsonResponse
    {
        abort_unless($shipment->tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'status' => ['required', 'in:ready,picked_up,in_transit,out_for_delivery,delivered,failed,returned,cancelled'],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:255'],
            'description.en' => ['nullable', 'string', 'max:255'],
            'received_by' => ['nullable', 'string', 'max:150'],
        ]);

        if (! $shipment->canMoveTo($data['status'])) {
            return response()->json([
                'message' => __('انتقال غير مسموح من :from إلى :to.', [
                    'from' => $shipment->status,
                    'to' => $data['status'],
                ]),
                'allowed' => Shipment::FLOW[$shipment->status] ?? [],
            ], 422);
        }

        $shipment = $this->service->transition(
            $shipment,
            $data['status'],
            $request->user(),
            $data['location'] ?? null,
            $data['description'] ?? null,
            $data['received_by'] ?? null,
        );

        return response()->json([
            'message' => __('تم تحديث حالة الشحنة.'),
            'shipment' => $this->detail($shipment->load('carrier', 'items.orderItem', 'events.recorder', 'documents', 'order')),
        ]);
    }

    /** حدث تتبّع وسيط بلا تغيير حالة. */
    public function addEvent(Request $request, Shipment $shipment): JsonResponse
    {
        abort_unless($shipment->tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'location' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'array'],
            'description.ar' => ['required', 'string', 'max:255'],
            'description.en' => ['nullable', 'string', 'max:255'],
        ]);

        $this->service->addEvent($shipment, $request->user(), $data['location'] ?? null, $data['description']);

        return response()->json([
            'shipment' => $this->detail($shipment->fresh()->load('carrier', 'items.orderItem', 'events.recorder', 'documents', 'order')),
        ], 201);
    }

    public function uploadDocument(Request $request, Shipment $shipment): JsonResponse
    {
        abort_unless($shipment->tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'type' => ['required', 'in:bill_of_lading,packing_list,commercial_invoice,certificate_of_origin,delivery_note,other'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,xlsx,docx'],
        ]);

        $file = $request->file('file');

        $document = ShipmentDocument::create([
            'shipment_id' => $shipment->id,
            'type' => $data['type'],
            'file_path' => $file->store('shipments', 'public'),
            'original_name' => $file->getClientOriginalName(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json([
            'document' => [
                'id' => $document->id,
                'type' => $document->type,
                'url' => $document->url(),
                'original_name' => $document->original_name,
            ],
        ], 201);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $tenantId = $request->user()->tenant_id;
        abort_unless(in_array($tenantId, [$order->supplier_tenant_id, $order->buyer_tenant_id], true), 403);
    }

    private function authorizeShipment(Request $request, Shipment $shipment): void
    {
        $tenantId = $request->user()->tenant_id;
        $shipment->loadMissing('order');

        abort_unless(
            $shipment->tenant_id === $tenantId || $shipment->order?->buyer_tenant_id === $tenantId,
            403
        );
    }

    private function summary(Shipment $shipment): array
    {
        return [
            'id' => $shipment->id,
            'shipment_no' => $shipment->shipment_no,
            'order' => ['id' => $shipment->order_id, 'order_no' => $shipment->order?->order_no],
            'carrier' => $shipment->carrier?->t('name'),
            'tracking_number' => $shipment->tracking_number,
            'tracking_token' => $shipment->tracking_token,
            'status' => $shipment->status,
            'packages_count' => $shipment->packages_count,
            'weight_kg' => $shipment->weight_kg,
            'shipping_cost' => $shipment->shipping_cost,
            'currency' => $shipment->currency,
            'is_cod' => $shipment->is_cod,
            'estimated_delivery' => $shipment->estimated_delivery?->toDateString(),
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            'created_at' => $shipment->created_at?->toIso8601String(),
        ];
    }

    private function detail(Shipment $shipment): array
    {
        return [
            ...$this->summary($shipment),
            'incoterm' => $shipment->incoterm,
            'origin_address' => $shipment->origin_address,
            'destination_address' => $shipment->destination_address,
            'cod_amount' => $shipment->cod_amount,
            'received_by' => $shipment->received_by,
            'notes' => $shipment->notes,
            'carrier_tracking_url' => $shipment->carrierTrackingUrl(),
            'allowed_transitions' => Shipment::FLOW[$shipment->status] ?? [],
            'items' => $shipment->relationLoaded('items')
                ? $shipment->items->map(fn ($line) => [
                    'id' => $line->id,
                    'order_item_id' => $line->order_item_id,
                    'description' => $line->orderItem?->description,
                    'quantity' => $line->quantity,
                ])
                : [],
            'events' => $shipment->relationLoaded('events')
                ? $shipment->events->map(fn (ShipmentEvent $event) => [
                    'id' => $event->id,
                    'status' => $event->status,
                    'location' => $event->location,
                    'description' => $event->t('description'),
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'recorded_by' => $event->recorder?->name,
                ])
                : [],
            'documents' => $shipment->relationLoaded('documents')
                ? $shipment->documents->map(fn (ShipmentDocument $document) => [
                    'id' => $document->id,
                    'type' => $document->type,
                    'url' => $document->url(),
                    'original_name' => $document->original_name,
                ])
                : [],
        ];
    }
}
