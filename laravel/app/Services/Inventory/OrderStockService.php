<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\Orders\Order;
use App\Models\Orders\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * الجسر بين أمر الشراء والمخزون.
 *
 * المستورد الذي يمسك مخزوناً تُخرَج بضاعته فعلياً عند التسليم،
 * والتكلفة الناتجة هي التي يُبنى عليها قيد تكلفة البضاعة المباعة.
 * المستورد الذي لا يمسك مخزوناً تُترك له التكلفة اليدوية على السطر.
 */
class OrderStockService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /** هل لأي صنف في الطلب سطر مخزون لدى المستورد؟ */
    public function isTracked(Order $order): bool
    {
        $productIds = $order->items->pluck('product_id')->filter();

        if ($productIds->isEmpty()) {
            return false;
        }

        return InventoryItem::withoutGlobalScope('tenant')
            ->where('tenant_id', $order->supplier_tenant_id)
            ->whereIn('product_id', $productIds)
            ->exists();
    }

    /** نقص الرصيد قبل التسليم — يُفحص مسبقاً حتى لا يفشل التسليم في منتصفه. */
    public function shortages(Order $order): array
    {
        $shortages = [];

        foreach ($order->items as $line) {
            $item = $this->itemFor($order, $line);

            if (! $item) {
                continue;
            }

            if ($item->quantity_on_hand < $line->quantity) {
                $shortages[] = [
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'required' => $line->quantity,
                    'available' => $item->quantity_on_hand,
                ];
            }
        }

        return $shortages;
    }

    /**
     * إخراج بضاعة الطلب وإرجاع التكلفة الفعلية.
     * يعيد null إذا لم يكن أي صنف متتبَّعاً — فيعود النظام للتكلفة اليدوية.
     */
    public function issueForOrder(Order $order): ?int
    {
        if (! $this->isTracked($order)) {
            return null;
        }

        // الإخراج مرة واحدة فقط لكل طلب
        $existing = InventoryMovement::withoutGlobalScope('tenant')
            ->where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->where('type', 'sale_out')
            ->get();

        if ($existing->isNotEmpty()) {
            return (int) abs($existing->sum('total_cost'));
        }

        $shortages = $this->shortages($order);

        if ($shortages !== []) {
            throw new BusinessRuleException(sprintf(
                'الرصيد غير كافٍ لتسليم الطلب %s: %s',
                $order->order_no,
                collect($shortages)
                    ->map(fn ($s) => "{$s['description']} (متاح {$s['available']} من {$s['required']})")
                    ->implode('، ')
            ));
        }

        return DB::transaction(function () use ($order) {
            $total = 0;

            foreach ($order->items as $line) {
                $item = $this->itemFor($order, $line);

                if (! $item) {
                    // صنف غير متتبَّع ضمن طلب متتبَّع — نأخذ تكلفته اليدوية
                    $total += $line->totalCost();

                    continue;
                }

                $movement = $this->inventory->issue(
                    $item,
                    $line->quantity,
                    'sale_out',
                    $order,
                    "تسليم الطلب {$order->order_no}"
                );

                $cost = (int) abs($movement->total_cost);
                $total += $cost;

                // تثبيت التكلفة الفعلية على السطر — بها تُقرأ ربحية الصفقة لاحقاً
                $line->forceFill([
                    'unit_cost' => $line->quantity > 0 ? (int) round($cost / $line->quantity) : 0,
                ])->save();
            }

            return $total;
        });
    }

    /** إرجاع بضاعة إلى المخزون عند المردود. */
    public function returnToStock(Order $order, OrderItem $line, int $quantity): ?InventoryMovement
    {
        $item = $this->itemFor($order, $line);

        if (! $item) {
            return null;
        }

        return $this->inventory->receive(
            $item,
            $quantity,
            $line->unit_cost,
            'return_in',
            $order,
            false,
            'inventory',
            "مردود على الطلب {$order->order_no}"
        );
    }

    private function itemFor(Order $order, OrderItem $line): ?InventoryItem
    {
        if (! $line->product_id) {
            return null;
        }

        return InventoryItem::withoutGlobalScope('tenant')
            ->where('tenant_id', $order->supplier_tenant_id)
            ->where('product_id', $line->product_id)
            ->where('variant_id', $line->variant_id)
            ->orderByDesc('quantity_on_hand')
            ->first();
    }
}
