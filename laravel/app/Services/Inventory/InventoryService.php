<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\Catalog\Product;
use App\Models\Inventory\InventoryCostLayer;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Warehouse;
use App\Models\Tenancy\Tenant;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * محرك المخزون.
 *
 * ضماناته:
 *  1. كل تغيّر في الرصيد يمرّ بحركة مسجَّلة — لا تحديث مباشر للعمود.
 *  2. الحركة والرصيد والقيد تُكتب في معاملة واحدة.
 *  3. قفل متشائم على سطر الصنف يمنع تضارب طلبين متزامنين.
 *  4. لا إخراج أكثر من المتاح — العجز خطأ بيانات يجب أن يظهر لا أن يُبتلع.
 *  5. التكلفة تُحتسب لحظة الإخراج بطريقة المستأجر (متوسط مرجّح أو FIFO).
 */
class InventoryService
{
    public function __construct(private readonly JournalPoster $poster) {}

    /** إيجاد أو إنشاء سطر المخزون لصنف في مستودع. */
    public function itemFor(Product $product, ?Warehouse $warehouse = null, ?int $variantId = null): InventoryItem
    {
        $warehouse ??= $this->defaultWarehouse($product->tenant);

        return InventoryItem::withoutGlobalScope('tenant')->firstOrCreate(
            [
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouse->id,
            ],
            ['quantity_on_hand' => 0, 'average_cost' => 0]
        );
    }

    public function defaultWarehouse(Tenant $tenant): Warehouse
    {
        $warehouse = Warehouse::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('is_default')
            ->first();

        if ($warehouse) {
            return $warehouse;
        }

        $created = new Warehouse([
            'name' => ['ar' => 'المستودع الرئيسي', 'en' => 'Main Warehouse'],
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]);
        $created->tenant_id = $tenant->id;
        $created->save();

        return $created;
    }

    /**
     * إدخال بضاعة. يُحدِّث المتوسط المرجّح ويفتح طبقة تكلفة لـ FIFO.
     */
    public function receive(
        InventoryItem $item,
        int $quantity,
        int $unitCost,
        string $type = 'purchase_in',
        ?Model $source = null,
        bool $postJournal = false,
        string $counterAccount = 'accounts_payable',
        ?string $notes = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new BusinessRuleException('كمية الإدخال يجب أن تكون موجبة.');
        }

        return DB::transaction(function () use ($item, $quantity, $unitCost, $type, $source, $postJournal, $counterAccount, $notes) {
            $locked = $this->lock($item);

            $previousQty = $locked->quantity_on_hand;
            $newQty = $previousQty + $quantity;

            // القيمة تُجمَّع بالتكلفة الفعلية، والمتوسط يُشتق منها —
            // فيبقى مجموع القيم مطابقاً تماماً لرصيد حساب المخزون
            $newValue = $locked->total_value + $quantity * $unitCost;
            $newAvg = $newQty > 0 ? (int) round($newValue / $newQty) : $unitCost;

            $movement = $this->writeMovement($locked, [
                'type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $quantity * $unitCost,
                'balance_after' => $newQty,
                'average_cost_after' => $newAvg,
                'source' => $source,
                'notes' => $notes,
            ]);

            InventoryCostLayer::create([
                'inventory_item_id' => $locked->id,
                'movement_id' => $movement->id,
                'quantity_in' => $quantity,
                'quantity_remaining' => $quantity,
                'unit_cost' => $unitCost,
                'received_at' => now(),
            ]);

            $locked->forceFill([
                'quantity_on_hand' => $newQty,
                'average_cost' => $newAvg,
                'total_value' => $newValue,
                'last_movement_at' => now(),
            ])->save();

            if ($postJournal) {
                $amount = Money::of($quantity * $unitCost, $locked->tenant->base_currency);

                $entry = $this->poster->post(
                    JournalDraft::for($locked->tenant, $amount->currency)
                        ->describe($notes ?? 'إدخال بضاعة للمخزون')
                        ->from($movement)
                        ->idempotent("inventory_in:movement:{$movement->id}")
                        ->debit('inventory', $amount)
                        ->credit($counterAccount, $amount)
                );

                $movement->forceFill(['journal_entry_id' => $entry->id])->saveQuietly();
            }

            return $movement;
        });
    }

    /**
     * إخراج بضاعة. يعيد التكلفة الفعلية المحتسَبة لحظة الإخراج —
     * وهي الرقم الذي يُبنى عليه قيد تكلفة البضاعة المباعة.
     */
    public function issue(
        InventoryItem $item,
        int $quantity,
        string $type = 'sale_out',
        ?Model $source = null,
        ?string $notes = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new BusinessRuleException('كمية الإخراج يجب أن تكون موجبة.');
        }

        return DB::transaction(function () use ($item, $quantity, $type, $source, $notes) {
            $locked = $this->lock($item);

            if ($locked->quantity_on_hand < $quantity) {
                throw new BusinessRuleException(sprintf(
                    'الرصيد غير كافٍ: المتاح %d والمطلوب %d.',
                    $locked->quantity_on_hand,
                    $quantity
                ));
            }

            $newQty = $locked->quantity_on_hand - $quantity;

            // المتوسط المرجّح: التكلفة حصة نسبية من القيمة المخزَّنة،
            // وإخراج الرصيد كاملاً يأخذ كل القيمة فلا تبقى كسور معلّقة
            $cost = match (true) {
                $locked->tenant->costing_method === 'fifo' => $this->consumeFifoLayers($locked, $quantity),
                $newQty === 0 => $locked->total_value,
                default => (int) round($locked->total_value * $quantity / max($locked->quantity_on_hand, 1)),
            };

            $movement = $this->writeMovement($locked, [
                'type' => $type,
                'quantity' => -$quantity,
                'unit_cost' => (int) round($cost / $quantity),
                'total_cost' => -$cost,
                'balance_after' => $newQty,
                'average_cost_after' => $locked->average_cost,
                'source' => $source,
                'notes' => $notes,
            ]);

            $newValue = max(0, $locked->total_value - $cost);

            $locked->forceFill([
                'quantity_on_hand' => $newQty,
                'total_value' => $newValue,
                'average_cost' => $newQty > 0 ? (int) round($newValue / $newQty) : $locked->average_cost,
                'last_movement_at' => now(),
            ])->save();

            return $movement;
        });
    }

    /**
     * تسوية جرد: الفرق بين الرصيد الدفتري والمعدود.
     * العجز مصروف، والزيادة تخفيض للمصروف — وكلاهما يُرحَّل.
     */
    public function adjust(
        InventoryItem $item,
        int $countedQuantity,
        ?string $reason = null,
        ?Model $source = null,
        bool $postJournal = true,
    ): ?InventoryMovement {
        return DB::transaction(function () use ($item, $countedQuantity, $reason, $source, $postJournal) {
            $locked = $this->lock($item);
            $difference = $countedQuantity - $locked->quantity_on_hand;

            if ($difference === 0) {
                return null;
            }

            $unitCost = $locked->average_cost;
            $value = abs($difference) * $unitCost;

            $movement = $this->writeMovement($locked, [
                'type' => 'adjustment',
                'quantity' => $difference,
                'unit_cost' => $unitCost,
                'total_cost' => $difference * $unitCost,
                'balance_after' => $countedQuantity,
                'average_cost_after' => $unitCost,
                'source' => $source,
                'notes' => $reason,
            ]);

            if ($difference > 0) {
                InventoryCostLayer::create([
                    'inventory_item_id' => $locked->id,
                    'movement_id' => $movement->id,
                    'quantity_in' => $difference,
                    'quantity_remaining' => $difference,
                    'unit_cost' => $unitCost,
                    'received_at' => now(),
                ]);
            } else {
                $this->consumeFifoLayers($locked, abs($difference));
            }

            $locked->forceFill([
                'quantity_on_hand' => $countedQuantity,
                'total_value' => max(0, $locked->total_value + $difference * $unitCost),
                'last_movement_at' => now(),
            ])->save();

            if ($postJournal && $value > 0) {
                $amount = Money::of($value, $locked->tenant->base_currency);

                $draft = JournalDraft::for($locked->tenant, $amount->currency)
                    ->describe($reason ?? 'تسوية جرد')
                    ->from($movement)
                    ->idempotent("inventory_adjust:movement:{$movement->id}");

                if ($difference > 0) {
                    $draft->debit('inventory', $amount)->credit('operating_expense', $amount);
                } else {
                    $draft->debit('operating_expense', $amount)->credit('inventory', $amount);
                }

                $entry = $this->poster->post($draft);
                $movement->forceFill(['journal_entry_id' => $entry->id])->saveQuietly();
            }

            return $movement;
        });
    }

    /** نقل بين مستودعين — لا أثر محاسبي لأن الحساب واحد. */
    public function transfer(InventoryItem $from, Warehouse $toWarehouse, int $quantity, ?string $notes = null): array
    {
        if ($from->warehouse_id === $toWarehouse->id) {
            throw new BusinessRuleException('المستودع المصدر والوجهة متطابقان.');
        }

        return DB::transaction(function () use ($from, $toWarehouse, $quantity, $notes) {
            $out = $this->issue($from, $quantity, 'transfer_out', null, $notes);

            $destination = InventoryItem::withoutGlobalScope('tenant')->firstOrCreate(
                [
                    'tenant_id' => $from->tenant_id,
                    'product_id' => $from->product_id,
                    'variant_id' => $from->variant_id,
                    'warehouse_id' => $toWarehouse->id,
                ],
                ['quantity_on_hand' => 0, 'average_cost' => 0]
            );

            $unitCost = $quantity > 0 ? (int) round(abs($out->total_cost) / $quantity) : 0;

            $in = $this->receive($destination, $quantity, $unitCost, 'transfer_in', null, false, 'inventory', $notes);

            return ['out' => $out, 'in' => $in];
        });
    }

    /** استهلاك طبقات FIFO وإرجاع التكلفة الإجمالية. */
    private function consumeFifoLayers(InventoryItem $item, int $quantity): int
    {
        $remaining = $quantity;
        $cost = 0;

        $layers = InventoryCostLayer::where('inventory_item_id', $item->id)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $layer->quantity_remaining);
            $cost += $take * $layer->unit_cost;
            $remaining -= $take;

            $layer->forceFill(['quantity_remaining' => $layer->quantity_remaining - $take])->save();
        }

        // طبقات ناقصة (مخزون افتتاحي بلا طبقات) — نُكمل بالمتوسط
        if ($remaining > 0) {
            $cost += $remaining * $item->average_cost;
        }

        return $cost;
    }

    private function lock(InventoryItem $item): InventoryItem
    {
        return InventoryItem::withoutGlobalScope('tenant')
            ->whereKey($item->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function writeMovement(InventoryItem $item, array $attributes): InventoryMovement
    {
        $source = $attributes['source'] ?? null;

        return InventoryMovement::create([
            'tenant_id' => $item->tenant_id,
            'inventory_item_id' => $item->id,
            'type' => $attributes['type'],
            'quantity' => $attributes['quantity'],
            'unit_cost' => $attributes['unit_cost'],
            'total_cost' => $attributes['total_cost'],
            'balance_after' => $attributes['balance_after'],
            'average_cost_after' => $attributes['average_cost_after'],
            'source_type' => $source ? $source::class : null,
            'source_id' => $source?->getKey(),
            'notes' => $attributes['notes'] ?? null,
        ]);
    }
}
