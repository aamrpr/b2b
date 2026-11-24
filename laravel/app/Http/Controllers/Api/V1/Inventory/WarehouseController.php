<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(): JsonResponse
    {
        $warehouses = Warehouse::withCount('items')->orderByDesc('is_default')->get();

        return response()->json([
            'warehouses' => $warehouses->map(fn (Warehouse $warehouse) => $this->present($warehouse)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateWarehouse($request);

        abort_if(
            Warehouse::where('code', $data['code'])->exists(),
            422,
            __('رمز المستودع مستخدم مسبقاً.')
        );

        $warehouse = Warehouse::create($data);

        if ($warehouse->is_default) {
            $this->clearOtherDefaults($warehouse);
        }

        return response()->json(['warehouse' => $this->present($warehouse)], 201);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $data = $this->validateWarehouse($request, partial: true);

        $warehouse->update($data);

        if ($warehouse->is_default) {
            $this->clearOtherDefaults($warehouse);
        }

        return response()->json(['warehouse' => $this->present($warehouse->fresh())]);
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        abort_if(
            $warehouse->items()->where('quantity_on_hand', '!=', 0)->exists(),
            422,
            __('لا يمكن حذف مستودع يحوي بضاعة — انقلها أولاً.')
        );

        $warehouse->update(['is_active' => false]);

        return response()->json(['message' => __('تم تعطيل المستودع.')]);
    }

    private function validateWarehouse(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'array'],
            'name.ar' => [$required, 'string', 'max:191'],
            'name.en' => ['nullable', 'string', 'max:191'],
            'code' => [$required, 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function clearOtherDefaults(Warehouse $warehouse): void
    {
        Warehouse::where('id', '!=', $warehouse->id)->update(['is_default' => false]);
    }

    private function present(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'name' => $warehouse->t('name'),
            'name_translations' => $warehouse->name,
            'code' => $warehouse->code,
            'address' => $warehouse->address,
            'lat' => $warehouse->lat,
            'lng' => $warehouse->lng,
            'is_default' => $warehouse->is_default,
            'is_active' => $warehouse->is_active,
            'items_count' => $warehouse->items_count ?? null,
            'value' => $warehouse->value(),
        ];
    }
}
