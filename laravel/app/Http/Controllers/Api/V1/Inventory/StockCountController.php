<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\StockCount;
use App\Models\Inventory\StockCountItem;
use App\Models\Inventory\Warehouse;
use App\Services\Accounting\DocumentSequencer;
use App\Services\Inventory\InventoryService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الجرد الفعلي. يُفتح بلقطة من أرصدة الدفاتر، ثم تُدخَل الكميات المعدودة،
 * ثم يُعتمد فتُرحَّل الفروق كحركات تسوية بقيودها.
 */
class StockCountController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentSequencer $sequencer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockCount::with(['warehouse', 'creator'])->withCount('items');

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['reference_no'])
                ->sortable([
                    'id' => 'id',
                    'reference_no' => 'reference_no',
                    'count_date' => 'count_date',
                    'status' => 'status',
                    'approved_at' => 'approved_at',
                ])
                ->filter('status', fn ($q, $value) => $q->where('status', $value))
                ->filter('warehouse', fn ($q, $value) => $q->where('warehouse_id', $value))
                ->filter('count_date', fn ($q, $value) => ColumnMatch::date($q, 'count_date', $value))
                ->filter('items_count', fn ($q, $value) => ColumnMatch::number(
                    $q,
                    '(SELECT COUNT(*) FROM stock_count_items WHERE stock_count_items.stock_count_id = stock_counts.id)',
                    $value,
                ))
                ->defaultOrder('id')
                ->transform(fn (StockCount $count) => [
                    'id' => $count->id,
                    'reference_no' => $count->reference_no,
                    'warehouse' => $count->warehouse?->t('name'),
                    'count_date' => $count->count_date?->toDateString(),
                    'status' => $count->status,
                    'items_count' => $count->items_count,
                    'created_by' => $count->creator?->name,
                    'approved_at' => $count->approved_at?->toIso8601String(),
                ])
                ->toArray()
        );
    }

    /** فتح جرد: لقطة من أرصدة المستودع لحظة الفتح. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'count_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        $count = DB::transaction(function () use ($data, $warehouse, $request) {
            $count = StockCount::create([
                'warehouse_id' => $warehouse->id,
                'reference_no' => $this->sequencer->next($request->user()->tenant_id, 'stock_count', 'SC'),
                'count_date' => $data['count_date'] ?? today(),
                'status' => 'counting',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $items = InventoryItem::where('warehouse_id', $warehouse->id)->get();

            foreach ($items as $item) {
                StockCountItem::create([
                    'stock_count_id' => $count->id,
                    'inventory_item_id' => $item->id,
                    'expected_quantity' => $item->quantity_on_hand,
                    'unit_cost' => $item->average_cost,
                ]);
            }

            return $count;
        });

        return response()->json([
            'message' => __('تم فتح الجرد.'),
            'stock_count' => $this->present($count->load('items.item.product', 'warehouse')),
        ], 201);
    }

    public function show(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->authorizeCount($request, $stockCount);

        return response()->json([
            'stock_count' => $this->present($stockCount->load('items.item.product', 'warehouse', 'creator')),
        ]);
    }

    /** إدخال الكميات المعدودة. */
    public function record(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->authorizeCount($request, $stockCount);

        abort_unless($stockCount->status === 'counting', 422, __('هذا الجرد غير مفتوح للعدّ.'));

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:stock_count_items,id'],
            'items.*.counted_quantity' => ['required', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $stockCount) {
            foreach ($data['items'] as $row) {
                $line = StockCountItem::where('stock_count_id', $stockCount->id)->findOrFail($row['id']);

                $difference = $row['counted_quantity'] - $line->expected_quantity;

                $line->update([
                    'counted_quantity' => $row['counted_quantity'],
                    'difference' => $difference,
                    'value_difference' => $difference * $line->unit_cost,
                    'notes' => $row['notes'] ?? null,
                ]);
            }
        });

        return response()->json([
            'stock_count' => $this->present($stockCount->fresh()->load('items.item.product', 'warehouse')),
        ]);
    }

    /** اعتماد الجرد: ترحيل كل الفروق كتسويات بقيودها. */
    public function approve(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->authorizeCount($request, $stockCount);

        abort_unless($stockCount->status === 'counting', 422, __('هذا الجرد معتمَد مسبقاً أو ملغى.'));

        $pending = $stockCount->items()->whereNull('counted_quantity')->count();
        abort_if($pending > 0, 422, __('ما زال :count صنفاً بلا كمية معدودة.', ['count' => $pending]));

        $adjusted = 0;

        DB::transaction(function () use ($stockCount, $request, &$adjusted) {
            foreach ($stockCount->items()->with('item')->get() as $line) {
                if ($line->difference === 0 || ! $line->item) {
                    continue;
                }

                $this->inventory->adjust(
                    $line->item,
                    (int) $line->counted_quantity,
                    "جرد {$stockCount->reference_no}",
                    $stockCount,
                );

                $adjusted++;
            }

            $stockCount->update([
                'status' => 'completed',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
        });

        return response()->json([
            'message' => __('تم اعتماد الجرد وترحيل :count تسوية.', ['count' => $adjusted]),
            'adjusted' => $adjusted,
            'net_value' => $stockCount->netDifferenceValue(),
        ]);
    }

    private function authorizeCount(Request $request, StockCount $count): void
    {
        abort_unless($count->tenant_id === $request->user()->tenant_id, 403);
    }

    private function present(StockCount $count): array
    {
        return [
            'id' => $count->id,
            'reference_no' => $count->reference_no,
            'warehouse' => ['id' => $count->warehouse_id, 'name' => $count->warehouse?->t('name')],
            'count_date' => $count->count_date?->toDateString(),
            'status' => $count->status,
            'notes' => $count->notes,
            'net_value_difference' => $count->netDifferenceValue(),
            'approved_at' => $count->approved_at?->toIso8601String(),
            'items' => $count->relationLoaded('items')
                ? $count->items->map(fn (StockCountItem $line) => [
                    'id' => $line->id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'product' => $line->item?->product?->t('name'),
                    'sku' => $line->item?->product?->sku,
                    'expected_quantity' => $line->expected_quantity,
                    'counted_quantity' => $line->counted_quantity,
                    'difference' => $line->difference,
                    'unit_cost' => $line->unit_cost,
                    'value_difference' => $line->value_difference,
                    'notes' => $line->notes,
                ])
                : [],
        ];
    }
}
