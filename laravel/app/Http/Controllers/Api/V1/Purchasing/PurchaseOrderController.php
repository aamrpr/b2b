<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\Vendor;
use App\Services\Purchasing\PurchasingService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchasingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()->with(['vendor', 'items']);

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['reference_no', 'notes'])
                ->sortable([
                    'id' => 'id',
                    'reference_no' => 'reference_no',
                    'status' => 'status',
                    'total' => 'total',
                    'order_date' => 'order_date',
                    'expected_date' => 'expected_date',
                ])
                ->filter('status', fn ($q, $value) => $q->where('status', $value))
                ->filter('vendor', fn ($q, $value) => $q->where('vendor_id', $value))
                ->filter('order_date', fn ($q, $value) => ColumnMatch::date($q, 'order_date', $value))
                ->filter('subtotal', fn ($q, $value) => ColumnMatch::money($q, 'subtotal', $value))
                ->filter('total', fn ($q, $value) => ColumnMatch::money($q, 'total', $value))
                ->defaultOrder('id')
                ->with(['currency' => $request->user()->tenant->base_currency])
                ->transform(fn (PurchaseOrder $order) => $this->summary($order))
                ->toArray()
        );
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeOrder($request, $purchaseOrder);

        return response()->json([
            'order' => $this->detail($purchaseOrder->load('vendor', 'warehouse', 'items.product', 'receipts.items')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'order_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'shipping_amount' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_cost' => ['required', 'integer', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        $vendor = Vendor::findOrFail($data['vendor_id']);

        $order = $this->service->createOrder($request->user()->tenant, $request->user(), $vendor, $data, $data['items']);

        return response()->json([
            'message' => __('أُنشئ أمر الشراء.'),
            'order' => $this->detail($order->load('vendor', 'items.product')),
        ], 201);
    }

    public function send(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeOrder($request, $purchaseOrder);

        $this->service->send($purchaseOrder);

        return response()->json(['message' => __('أُرسل أمر الشراء إلى المورّد.')]);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeOrder($request, $purchaseOrder);

        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'received_date' => ['nullable', 'date'],
            'vendor_note_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
            'items.*.condition_note' => ['nullable', 'string', 'max:255'],
        ]);

        $receipt = $this->service->receive($purchaseOrder, $request->user(), $data['items'], $data);

        return response()->json([
            'message' => __('سُجِّل الاستلام :no.', ['no' => $receipt->reference_no]),
            'order' => $this->detail($purchaseOrder->fresh()->load('vendor', 'items.product', 'receipts.items')),
        ], 201);
    }

    public function bill(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeOrder($request, $purchaseOrder);

        $data = $request->validate([
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'vendor_invoice_no' => ['nullable', 'string', 'max:100'],
            'items' => ['nullable', 'array'],
            'items.*.purchase_order_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:0'],
        ]);

        $invoice = $this->service->bill($purchaseOrder, $request->user(), $data['items'] ?? [], $data);

        return response()->json([
            'message' => __('صدرت فاتورة المورّد :no.', ['no' => $invoice->invoice_no]),
            'invoice' => [
                'id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'subtotal' => $invoice->subtotal,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
                'balance_due' => $invoice->balance_due,
                'due_date' => $invoice->due_date->toDateString(),
                'currency' => $invoice->currency,
            ],
        ], 201);
    }

    private function authorizeOrder(Request $request, PurchaseOrder $order): void
    {
        abort_unless($order->tenant_id === $request->user()->tenant_id, 403);
    }

    private function summary(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference_no' => $order->reference_no,
            'vendor' => ['id' => $order->vendor_id, 'name' => $order->vendor?->name],
            'order_date' => $order->order_date->toDateString(),
            'expected_date' => $order->expected_date?->toDateString(),
            'subtotal' => $order->subtotal,
            'tax_amount' => $order->tax_amount,
            'total' => $order->total,
            'currency' => $order->currency,
            'status' => $order->status,
            'items_count' => $order->items->count(),
        ];
    }

    private function detail(PurchaseOrder $order): array
    {
        $order->loadMissing('items');

        return [
            ...$this->summary($order),
            'warehouse' => ['id' => $order->warehouse_id, 'name' => $order->warehouse?->t('name')],
            'incoterm' => $order->incoterm,
            'shipping_amount' => $order->shipping_amount,
            'notes' => $order->notes,
            'allowed_transitions' => PurchaseOrder::FLOW[$order->status] ?? [],
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'received_quantity' => $item->received_quantity,
                'invoiced_quantity' => $item->invoiced_quantity,
                'pending_receipt' => max(0, $item->quantity - $item->received_quantity),
                'pending_invoice' => max(0, $item->received_quantity - $item->invoiced_quantity),
                'unit' => $item->unit,
                'unit_cost' => $item->unit_cost,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
            ]),
            'receipts' => $order->relationLoaded('receipts')
                ? $order->receipts->map(fn ($receipt) => [
                    'id' => $receipt->id,
                    'reference_no' => $receipt->reference_no,
                    'received_date' => $receipt->received_date->toDateString(),
                    'total_cost' => $receipt->total_cost,
                    'vendor_note_no' => $receipt->vendor_note_no,
                    'items_count' => $receipt->items->count(),
                ])
                : [],
        ];
    }
}
