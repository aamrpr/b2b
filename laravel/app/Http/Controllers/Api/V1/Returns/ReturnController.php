<?php

namespace App\Http\Controllers\Api\V1\Returns;

use App\Http\Controllers\Controller;
use App\Models\Orders\Order;
use App\Models\Returns\ReturnPhoto;
use App\Models\Returns\ReturnRequest;
use App\Services\Returns\ReturnService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $service) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $query = ReturnRequest::query()
            ->where(fn ($q) => $q->where('buyer_tenant_id', $tenantId)->orWhere('supplier_tenant_id', $tenantId))
            ->with(['order', 'items', 'buyer', 'supplier']);

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['reference_no', 'description'])
                ->sortable([
                    'id' => 'id',
                    'reference_no' => 'reference_no',
                    'status' => 'status',
                    'reason' => 'reason',
                    'created_at' => 'created_at',
                ])
                ->filter('status', fn ($q, $value) => $q->where('status', $value))
                ->filter('reason', fn ($q, $value) => $q->where('reason', $value))
                ->filter('order', fn ($q, $value) => $q->whereHas('order', fn ($o) => $o->where('order_no', 'like', "%{$value}%")))
                ->filter('counterpart', function ($q, $value) use ($tenantId) {
                    $q->where(function ($q) use ($value, $tenantId) {
                        $q->where(function ($q) use ($value, $tenantId) {
                            $q->where('buyer_tenant_id', $tenantId)
                                ->whereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$value}%"));
                        })->orWhere(function ($q) use ($value, $tenantId) {
                            $q->where('supplier_tenant_id', $tenantId)
                                ->whereHas('buyer', fn ($b) => $b->where('name', 'like', "%{$value}%"));
                        });
                    });
                })
                ->filter('requested_amount', fn ($q, $value) => ColumnMatch::money($q, 'requested_amount', $value))
                ->defaultOrder('id')
                ->transform(fn (ReturnRequest $row) => $this->summary($row, $tenantId))
                ->toArray()
        );
    }

    /** الكميات القابلة للإرجاع — تملأ نموذج الطلب. */
    public function returnable(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $order->load('items');
        $returnable = $this->service->returnableQuantities($order);

        return response()->json([
            'order_no' => $order->order_no,
            'currency' => $order->currency,
            'items' => $order->items->map(fn ($line) => [
                'order_item_id' => $line->id,
                'description' => $line->description,
                'ordered' => $line->quantity,
                'returnable' => $returnable[$line->id] ?? 0,
                'unit_price' => $line->unit_price,
                'unit' => $line->unit,
            ]),
        ]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->buyer_tenant_id === $request->user()->tenant_id, 403,
            __('طلب المرتجع يفتحه المشتري.'));

        $data = $request->validate([
            'type' => ['nullable', 'in:refund,replacement'],
            'reason' => ['required', 'in:'.implode(',', ReturnRequest::REASONS)],
            'description' => ['nullable', 'string', 'max:1000'],
            'restock' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.condition_note' => ['nullable', 'string', 'max:255'],
        ]);

        $order->load('items');

        $returnRequest = $this->service->request($order, $request->user(), $data);

        return response()->json([
            'message' => __('أُرسل طلب المرتجع إلى المستورد.'),
            'return' => $this->detail($returnRequest->load('items.orderItem', 'order', 'photos'), $request->user()->tenant_id),
        ], 201);
    }

    public function show(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->authorizeReturn($request, $returnRequest);

        return response()->json([
            'return' => $this->detail(
                $returnRequest->load('items.orderItem', 'order', 'photos', 'creditNote', 'buyer', 'supplier'),
                $request->user()->tenant_id
            ),
        ]);
    }

    public function approve(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->authorizeSupplier($request, $returnRequest);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0'],
        ]);

        $returnRequest->load('items');

        $updated = $this->service->approve(
            $returnRequest,
            $request->user(),
            $this->intKeys($data['quantities'] ?? []),
            $data['note'] ?? null,
        );

        return response()->json([
            'message' => __('تم اعتماد المرتجع.'),
            'return' => $this->detail($updated->load('items.orderItem', 'order'), $request->user()->tenant_id),
        ]);
    }

    public function reject(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->authorizeSupplier($request, $returnRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->service->reject($returnRequest, $request->user(), $data['reason']);

        return response()->json(['message' => __('تم رفض المرتجع.')]);
    }

    /** المشتري يعلن أنه أرسل البضاعة. */
    public function markInTransit(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        abort_unless($returnRequest->buyer_tenant_id === $request->user()->tenant_id, 403);

        $this->service->markInTransit($returnRequest, $request->user());

        return response()->json(['message' => __('تم تحديث حالة المرتجع.')]);
    }

    public function receive(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->authorizeSupplier($request, $returnRequest);

        $data = $request->validate([
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0'],
        ]);

        $returnRequest->load('items');

        $updated = $this->service->receive(
            $returnRequest,
            $request->user(),
            $this->intKeys($data['quantities'] ?? []),
        );

        return response()->json([
            'message' => __('تم تسجيل الاستلام.'),
            'return' => $this->detail($updated->load('items.orderItem', 'order'), $request->user()->tenant_id),
        ]);
    }

    /** الإقفال: إشعار دائن وقيوده وعكس العمولة. */
    public function complete(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->authorizeSupplier($request, $returnRequest);

        $note = $this->service->complete($returnRequest);

        return response()->json([
            'message' => __('صدر إشعار دائن :no.', ['no' => $note->note_no]),
            'credit_note' => [
                'id' => $note->id,
                'note_no' => $note->note_no,
                'subtotal' => $note->subtotal,
                'tax_amount' => $note->tax_amount,
                'total' => $note->total,
                'currency' => $note->currency,
            ],
        ]);
    }

    public function uploadPhoto(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->authorizeReturn($request, $returnRequest);

        $request->validate(['photo' => ['required', 'image', 'max:5120']]);

        $photo = ReturnPhoto::create([
            'return_request_id' => $returnRequest->id,
            'file_path' => $request->file('photo')->store('returns', 'public'),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['photo' => ['id' => $photo->id, 'url' => $photo->url()]], 201);
    }

    /** @return array<int, int> */
    private function intKeys(array $values): array
    {
        return collect($values)->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v])->all();
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $tenantId = $request->user()->tenant_id;
        abort_unless(in_array($tenantId, [$order->supplier_tenant_id, $order->buyer_tenant_id], true), 403);
    }

    private function authorizeReturn(Request $request, ReturnRequest $row): void
    {
        $tenantId = $request->user()->tenant_id;
        abort_unless(in_array($tenantId, [$row->supplier_tenant_id, $row->buyer_tenant_id], true), 403);
    }

    private function authorizeSupplier(Request $request, ReturnRequest $row): void
    {
        abort_unless($row->supplier_tenant_id === $request->user()->tenant_id, 403,
            __('هذا الإجراء للمستورد.'));
    }

    private function summary(ReturnRequest $row, int $viewerTenantId): array
    {
        return [
            'id' => $row->id,
            'reference_no' => $row->reference_no,
            'order' => ['id' => $row->order_id, 'order_no' => $row->order?->order_no],
            'counterpart' => $viewerTenantId === $row->buyer_tenant_id
                ? $row->supplier?->name
                : $row->buyer?->name,
            'is_mine' => $row->buyer_tenant_id === $viewerTenantId,
            'type' => $row->type,
            'reason' => $row->reason,
            'status' => $row->status,
            'requested_amount' => $row->requested_amount,
            'approved_amount' => $row->approved_amount,
            'currency' => $row->currency,
            'items_count' => $row->items->count(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function detail(ReturnRequest $row, int $viewerTenantId): array
    {
        return [
            ...$this->summary($row, $viewerTenantId),
            'description' => $row->description,
            'decision_note' => $row->decision_note,
            'restock' => $row->restock,
            'allowed_transitions' => ReturnRequest::FLOW[$row->status] ?? [],
            'received_value' => $row->receivedValue(),
            'decided_at' => $row->decided_at?->toIso8601String(),
            'received_at' => $row->received_at?->toIso8601String(),
            'completed_at' => $row->completed_at?->toIso8601String(),
            'credit_note' => $row->relationLoaded('creditNote') && $row->creditNote ? [
                'id' => $row->creditNote->id,
                'note_no' => $row->creditNote->note_no,
                'total' => $row->creditNote->total,
                'currency' => $row->creditNote->currency,
            ] : null,
            'items' => $row->relationLoaded('items')
                ? $row->items->map(fn ($item) => [
                    'id' => $item->id,
                    'order_item_id' => $item->order_item_id,
                    'description' => $item->orderItem?->description,
                    'quantity' => $item->quantity,
                    'approved_quantity' => $item->approved_quantity,
                    'received_quantity' => $item->received_quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'condition_note' => $item->condition_note,
                ])
                : [],
            'photos' => $row->relationLoaded('photos')
                ? $row->photos->map(fn ($photo) => ['id' => $photo->id, 'url' => $photo->url()])
                : [],
        ];
    }
}
