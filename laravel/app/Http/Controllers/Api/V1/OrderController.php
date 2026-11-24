<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Orders\Order;
use App\Services\Orders\OrderFulfillmentService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderFulfillmentService $fulfillment) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $query = Order::query()
            ->when($tenant->isSupplier(), fn ($q) => $q->where('supplier_tenant_id', $tenant->id))
            ->when($tenant->isBuyer(), fn ($q) => $q->where('buyer_tenant_id', $tenant->id))
            ->with(['supplier.supplierProfile', 'buyer', 'items', 'commission']);

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['order_no'])
                ->sortable([
                    'id' => 'id',
                    'order_no' => 'order_no',
                    'status' => 'status',
                    'payment_status' => 'payment_status',
                    'total' => 'total',
                    'created_at' => 'created_at',
                ])
                ->filter('status', fn ($q, $value) => $q->where('status', $value))
                ->filter('payment_status', fn ($q, $value) => $q->where('payment_status', $value))
                ->filter('counterpart', function ($q, $value) use ($tenant) {
                    $tenant->isSupplier()
                        ? $q->whereHas('buyer', fn ($buyer) => $buyer->where('name', 'like', "%{$value}%"))
                        : ColumnMatch::tenantName($q, 'supplier', $value);
                })
                ->filter('total', fn ($q, $value) => ColumnMatch::money($q, 'total', $value))
                ->filter('commission', fn ($q, $value) => ColumnMatch::money($q, 'commission_amount', $value))
                ->defaultOrder('id')
                ->transform(fn (Order $order) => (new OrderResource($order))->resolve($request))
                ->toArray()
        );
    }

    public function show(Request $request, Order $order): OrderResource
    {
        $tenantId = $request->user()->tenant_id;
        abort_unless(in_array($tenantId, [$order->supplier_tenant_id, $order->buyer_tenant_id], true), 403);

        return new OrderResource($order->load(['items', 'supplier.supplierProfile', 'buyer', 'statusHistory']));
    }

    /**
     * تحديث حالة الطلب يدوياً.
     * الطلبات المشحونة تُحدَّث من شاشة الشحنة لا من هنا — فحالة الطلب
     * تُشتق من شحناته، وتحديثها يدوياً يفتح باب التناقض بين الاثنين.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->supplier_tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'status' => ['required', 'in:in_production,shipped,delivered,closed,cancelled'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['status'] === 'delivered') {
            $shortages = $this->fulfillment->blockingShortages($order);

            if ($shortages !== []) {
                return response()->json([
                    'message' => __('الرصيد غير كافٍ لتسليم هذا الطلب.'),
                    'shortages' => $shortages,
                ], 422);
            }
        }

        $this->fulfillment->transition($order, $data['status'], $request->user(), $data['note'] ?? null);

        return response()->json([
            'message' => __('تم تحديث حالة الطلب.'),
            'order' => new OrderResource($order->fresh()->load(['items', 'commission'])),
        ]);
    }
}
