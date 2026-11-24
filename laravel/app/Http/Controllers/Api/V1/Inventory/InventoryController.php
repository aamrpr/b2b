<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // حدّ النقص شرطٌ على الصفحة لا عمودٌ يُصفّى، فيُقرأ من معاملات الطلب
        $query = InventoryItem::query()
            ->with(['product', 'warehouse', 'variant'])
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock());

        return response()->json(
            DataTable::make($request, $query)
                ->sortable([
                    'id' => 'id',
                    'quantity' => 'quantity',
                    'average_cost' => 'average_cost',
                    'reorder_point' => 'reorder_point',
                ])
                ->filter('product', fn ($q, $value) => $q->whereHas('product', function ($p) use ($value) {
                    $p->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar')) LIKE ?", ["%{$value}%"])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) LIKE ?", ["%{$value}%"])
                        ->orWhere('sku', 'like', "%{$value}%");
                }))
                ->filter('warehouse', fn ($q, $value) => $q->where('warehouse_id', $value))
                ->filter('quantity', fn ($q, $value) => ColumnMatch::number($q, 'quantity_on_hand', $value))
                ->filter('average_cost', fn ($q, $value) => ColumnMatch::money($q, 'average_cost', $value, null))
                ->filter('value', fn ($q, $value) => ColumnMatch::money($q, 'total_value', $value, null))
                ->filter('reorder_point', fn ($q, $value) => ColumnMatch::number($q, 'reorder_point', $value))
                ->defaultOrder('id')
                ->with(['currency' => $request->user()->tenant->base_currency])
                ->transform(fn (InventoryItem $item) => $this->present($item))
                ->toArray()
        );
    }

    public function show(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        $this->authorizeItem($request, $inventoryItem);

        $inventoryItem->load(['product', 'warehouse', 'variant']);

        $movements = $inventoryItem->movements()
            ->with('journalEntry')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'currency' => $request->user()->tenant->base_currency,
            'item' => $this->present($inventoryItem),
            'derived_quantity' => $inventoryItem->derivedQuantity(),
            'movements' => $movements->map(fn (InventoryMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'unit_cost' => $movement->unit_cost,
                'total_cost' => $movement->total_cost,
                'balance_after' => $movement->balance_after,
                'average_cost_after' => $movement->average_cost_after,
                'source' => $movement->source_type
                    ? class_basename($movement->source_type).'#'.$movement->source_id
                    : null,
                'journal_entry_no' => $movement->journalEntry?->entry_no,
                'notes' => $movement->notes,
                'created_at' => $movement->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /** تقرير تقييم المخزون — يجب أن يطابق رصيد حساب المخزون في الأستاذ. */
    public function valuation(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $rows = InventoryItem::query()
            ->with(['product', 'warehouse'])
            ->where('quantity_on_hand', '!=', 0)
            ->get();

        $total = $rows->sum(fn (InventoryItem $item) => $item->total_value);

        $ledger = Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('system_key', 'inventory')
            ->first()
            ?->balance() ?? 0;

        return response()->json([
            'currency' => $tenant->base_currency,
            'costing_method' => $tenant->costing_method,
            'total_value' => (int) $total,
            'ledger_balance' => $ledger,
            'difference' => (int) $total - $ledger,
            'is_reconciled' => (int) $total === $ledger,
            'items' => $rows->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'product' => $item->product?->t('name'),
                'sku' => $item->product?->sku,
                'warehouse' => $item->warehouse?->t('name'),
                'quantity' => $item->quantity_on_hand,
                'average_cost' => $item->average_cost,
                'value' => $item->total_value,
            ])->values(),
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $items = InventoryItem::query()
            ->lowStock()
            ->with(['product', 'warehouse'])
            ->orderBy('quantity_on_hand')
            ->get();

        return response()->json([
            'currency' => $request->user()->tenant->base_currency,
            'items' => $items->map(fn (InventoryItem $item) => $this->present($item)),
        ]);
    }

    public function updateSettings(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        $this->authorizeItem($request, $inventoryItem);

        $data = $request->validate([
            'reorder_point' => ['required', 'integer', 'min:0'],
        ]);

        $inventoryItem->update($data);

        return response()->json(['item' => $this->present($inventoryItem->fresh()->load('product', 'warehouse'))]);
    }

    private function authorizeItem(Request $request, InventoryItem $item): void
    {
        abort_unless($item->tenant_id === $request->user()->tenant_id, 403);
    }

    private function present(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'product' => [
                'id' => $item->product_id,
                'name' => $item->product?->t('name'),
                'sku' => $item->product?->sku,
                'unit' => $item->product?->unit_of_measure,
            ],
            'variant' => $item->variant ? ['id' => $item->variant->id, 'sku' => $item->variant->sku] : null,
            'warehouse' => ['id' => $item->warehouse_id, 'name' => $item->warehouse?->t('name')],
            'quantity_on_hand' => $item->quantity_on_hand,
            'quantity_reserved' => $item->quantity_reserved,
            'quantity_available' => $item->availableQuantity(),
            'average_cost' => $item->average_cost,
            'value' => $item->total_value,
            'reorder_point' => $item->reorder_point,
            'is_low' => $item->isBelowReorderPoint(),
            'last_movement_at' => $item->last_movement_at?->toIso8601String(),
        ];
    }
}
