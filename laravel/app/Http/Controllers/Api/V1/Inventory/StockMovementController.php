<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    /** سجلّ الحركات عبر كل الأصناف. */
    public function index(Request $request): JsonResponse
    {
        $query = InventoryMovement::query()->with(['item.product', 'item.warehouse']);

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['notes'])
                ->sortable([
                    'id' => 'id',
                    'type' => 'type',
                    'quantity' => 'quantity',
                    'unit_cost' => 'unit_cost',
                    'balance_after' => 'balance_after',
                    'created_at' => 'created_at',
                ])
                ->filter('type', fn ($q, $value) => $q->where('type', $value))
                ->filter('warehouse', fn ($q, $value) => $q->whereHas('item', fn ($i) => $i->where('warehouse_id', $value)))
                ->filter('product', fn ($q, $value) => $q->whereHas('item.product', function ($p) use ($value) {
                    $p->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar')) LIKE ?", ["%{$value}%"])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) LIKE ?", ["%{$value}%"]);
                }))
                ->filter('created_at', fn ($q, $value) => ColumnMatch::date($q, 'created_at', $value))
                ->filter('quantity', fn ($q, $value) => ColumnMatch::number($q, 'quantity', $value))
                ->filter('total_cost', fn ($q, $value) => ColumnMatch::money($q, 'total_cost', $value, null))
                ->filter('source', function ($q, $value) {
                    $q->where(function ($q) use ($value) {
                        $q->where('source_type', 'like', "%{$value}%")
                            ->orWhereRaw('CAST(source_id AS CHAR) LIKE ?', ["%{$value}%"]);
                    });
                })
                ->defaultOrder('id')
                ->with(['currency' => $request->user()->tenant->base_currency])
                ->transform(fn (InventoryMovement $movement) => [
                    'id' => $movement->id,
                    'type' => $movement->type,
                    'product' => $movement->item?->product?->t('name'),
                    'warehouse' => $movement->item?->warehouse?->t('name'),
                    'quantity' => $movement->quantity,
                    'unit_cost' => $movement->unit_cost,
                    'total_cost' => $movement->total_cost,
                    'balance_after' => $movement->balance_after,
                    'source' => $movement->source_type
                        ? class_basename($movement->source_type).'#'.$movement->source_id
                        : null,
                    'notes' => $movement->notes,
                    'created_at' => $movement->created_at?->toIso8601String(),
                ])
                ->toArray()
        );
    }

    /** إدخال بضاعة — شراء أو رصيد افتتاحي. */
    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'integer', 'min:0'],
            'type' => ['nullable', 'in:purchase_in,opening,return_in'],
            'post_journal' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $product = Product::owned()->findOrFail($data['product_id']);
        $warehouse = isset($data['warehouse_id'])
            ? Warehouse::findOrFail($data['warehouse_id'])
            : null;

        $item = $this->inventory->itemFor($product, $warehouse, $data['variant_id'] ?? null);

        $movement = $this->inventory->receive(
            $item,
            $data['quantity'],
            $data['unit_cost'],
            $data['type'] ?? 'purchase_in',
            null,
            $data['post_journal'] ?? true,
            'accounts_payable',
            $data['notes'] ?? null,
        );

        return response()->json([
            'message' => __('تم إدخال البضاعة.'),
            'movement_id' => $movement->id,
            'balance' => $movement->balance_after,
            'average_cost' => $movement->average_cost_after,
        ], 201);
    }

    /** تسوية يدوية لرصيد صنف. */
    public function adjust(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        abort_unless($inventoryItem->tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'counted_quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $movement = $this->inventory->adjust(
            $inventoryItem,
            $data['counted_quantity'],
            $data['reason'],
        );

        if (! $movement) {
            return response()->json(['message' => __('لا فرق — الرصيد مطابق.')]);
        }

        return response()->json([
            'message' => __('تم ترحيل التسوية.'),
            'difference' => $movement->quantity,
            'balance' => $movement->balance_after,
        ]);
    }

    /** نقل بين مستودعين. */
    public function transfer(Request $request, InventoryItem $inventoryItem): JsonResponse
    {
        abort_unless($inventoryItem->tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $destination = Warehouse::findOrFail($data['warehouse_id']);

        $result = $this->inventory->transfer(
            $inventoryItem,
            $destination,
            $data['quantity'],
            $data['notes'] ?? null,
        );

        return response()->json([
            'message' => __('تم النقل.'),
            'from_balance' => $result['out']->balance_after,
            'to_balance' => $result['in']->balance_after,
        ]);
    }
}
