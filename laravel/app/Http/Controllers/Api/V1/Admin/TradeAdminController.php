<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Orders\Order;
use App\Models\Rfq\Quotation;
use App\Services\Admin\AuditLogger;
use App\Services\Orders\OrderFulfillmentService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الصفقات من عين المنصة: العروض وأوامر الشراء بين كل الأطراف.
 *
 * قراءةٌ لا تدخّل: العرض عقدٌ بين مستوردٍ ومشترٍ، وتعديله من خارجه
 * يُفسد سنده. فما تحتاجه المنصة هنا أن ترى وتتابع — وإن وقع خلاف
 * فبابه النزاعات، لا تحرير الوثيقة من الخلف.
 */
class TradeAdminController extends Controller
{
    public function __construct(
        private readonly OrderFulfillmentService $fulfillment,
        private readonly AuditLogger $audit,
    ) {}

    public function quotations(Request $request): JsonResponse
    {
        return response()->json(
            DataTable::make($request, Quotation::query()->with(['supplier.supplierProfile', 'buyer.supplierProfile', 'rfq']))
                ->searchable(['quotation_no'])
                ->sortable([
                    'id' => 'id',
                    'quotation_no' => 'quotation_no',
                    'status' => 'status',
                    'total' => 'total',
                    'created_at' => 'created_at',
                ])
                ->filter('status', fn ($query, $value) => $query->where('status', $value))
                ->filter('supplier', fn ($query, $value) => ColumnMatch::tenantName($query, 'supplier', $value))
                ->filter('buyer', fn ($query, $value) => ColumnMatch::tenantName($query, 'buyer', $value))
                ->filter('total', fn ($query, $value) => ColumnMatch::money($query, 'total', $value))
                ->defaultOrder('id')
                ->transform(fn (Quotation $quotation) => [
                    'id' => $quotation->id,
                    'quotation_no' => $quotation->quotation_no,
                    'status' => $quotation->status,
                    'total' => $quotation->total,
                    'currency' => $quotation->currency,
                    'supplier' => $this->partyName($quotation->supplier),
                    'buyer' => $this->partyName($quotation->buyer),
                    'rfq_title' => $quotation->rfq?->title,
                    'signed' => [
                        'supplier' => $quotation->supplier_signed_at !== null,
                        'buyer' => $quotation->buyer_signed_at !== null,
                    ],
                    'created_at' => $quotation->created_at?->toIso8601String(),
                ])
                ->toArray()
        );
    }

    public function orders(Request $request): JsonResponse
    {
        return response()->json(
            DataTable::make($request, Order::query()->with(['supplier.supplierProfile', 'buyer.supplierProfile']))
                ->searchable(['order_no'])
                ->sortable([
                    'id' => 'id',
                    'order_no' => 'order_no',
                    'status' => 'status',
                    'payment_status' => 'payment_status',
                    'total' => 'total',
                    'created_at' => 'created_at',
                ])
                ->filter('status', fn ($query, $value) => $query->where('status', $value))
                ->filter('supplier', fn ($query, $value) => ColumnMatch::tenantName($query, 'supplier', $value))
                ->filter('buyer', fn ($query, $value) => ColumnMatch::tenantName($query, 'buyer', $value))
                ->filter('total', fn ($query, $value) => ColumnMatch::money($query, 'total', $value))
                ->defaultOrder('id')
                ->transform(fn (Order $order) => [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'total' => $order->total,
                    'currency' => $order->currency,
                    'supplier' => $this->partyName($order->supplier),
                    'buyer' => $this->partyName($order->buyer),
                    'created_at' => $order->created_at?->toIso8601String(),
                ])
                ->toArray()
        );
    }

    /**
     * تصحيح حالة أمر شراء.
     *
     * يمرّ بخدمة التنفيذ نفسها التي يستعملها المستورد لا بكتابةٍ مباشرة
     * على العمود: الحالة تجرّ وراءها قيوداً ومخزوناً وإشعاراً، وكتابتها
     * وحدها تترك الدفتر يقول شيئاً والطلب يقول آخر.
     */
    public function updateOrderStatus(Request $request, Order $order): JsonResponse
    {
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

        $previous = $order->status;

        $this->fulfillment->transition($order, $data['status'], $request->user(), $data['note'] ?? null);

        $this->audit->record('order.status_changed', $order,
            ['status' => $previous],
            ['status' => $data['status'], 'note' => $data['note'] ?? null],
        );

        return response()->json([
            'message' => __('تم تحديث حالة الطلب.'),
            'status' => $order->fresh()->status,
        ]);
    }

    private function partyName($tenant): ?string
    {
        return $tenant?->supplierProfile?->t('display_name') ?? $tenant?->name;
    }

    private function meta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }
}
