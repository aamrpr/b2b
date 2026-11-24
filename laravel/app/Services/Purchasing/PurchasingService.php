<?php

namespace App\Services\Purchasing;

use App\Exceptions\BusinessRuleException;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\InvoiceItem;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Purchasing\GoodsReceiptItem;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Purchasing\Vendor;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Accounting\DocumentSequencer;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Services\Inventory\InventoryService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * دورة الشراء بمطابقة ثلاثية: أمر شراء ⟷ استلام ⟷ فاتورة مورّد.
 *
 * الاستلام والفوترة حدثان منفصلان زمنياً، فالبضاعة تدخل المخزون عند
 * وصولها مقابل التزام "مستلمة غير مفوترة"، وتُسقطه الفاتورة لاحقاً.
 * هذا يمنع تأخير المخزون حتى الفاتورة، ويمنع تسجيل الالتزام مرتين.
 */
class PurchasingService
{
    public function __construct(
        private readonly DocumentSequencer $sequencer,
        private readonly JournalPoster $poster,
        private readonly InventoryService $inventory,
    ) {}

    public function createOrder(Tenant $tenant, User $actor, Vendor $vendor, array $data, array $lines): PurchaseOrder
    {
        if ($lines === []) {
            throw new BusinessRuleException('أمر الشراء يحتاج صنفاً واحداً على الأقل.');
        }

        return DB::transaction(function () use ($tenant, $actor, $vendor, $data, $lines) {
            $order = new PurchaseOrder([
                'vendor_id' => $vendor->id,
                'warehouse_id' => $data['warehouse_id'] ?? $this->inventory->defaultWarehouse($tenant)->id,
                'reference_no' => $this->sequencer->next($tenant->id, 'purchase_order', 'PUR'),
                'order_date' => $data['order_date'] ?? today(),
                'expected_date' => $data['expected_date'] ?? null,
                'currency' => $data['currency'] ?? $vendor->default_currency,
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'incoterm' => $data['incoterm'] ?? null,
                'shipping_amount' => (int) ($data['shipping_amount'] ?? 0),
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
            $order->tenant_id = $tenant->id;
            $order->save();

            $subtotal = 0;
            $tax = 0;

            foreach ($lines as $line) {
                if ((int) $line['quantity'] <= 0) {
                    throw new BusinessRuleException('كمية السطر يجب أن تكون موجبة.');
                }

                $lineTotal = (int) $line['unit_cost'] * (int) $line['quantity'];
                $lineTax = (int) ($line['tax_amount'] ?? 0);

                $subtotal += $lineTotal;
                $tax += $lineTax;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $line['product_id'] ?? null,
                    'variant_id' => $line['variant_id'] ?? null,
                    'description' => $line['description'],
                    'quantity' => (int) $line['quantity'],
                    'unit' => $line['unit'] ?? 'piece',
                    'unit_cost' => (int) $line['unit_cost'],
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineTotal + $lineTax,
                ]);
            }

            $order->forceFill([
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $subtotal + $tax + $order->shipping_amount,
            ])->save();

            return $order->load('items', 'vendor');
        });
    }

    public function send(PurchaseOrder $order): PurchaseOrder
    {
        $this->assertTransition($order, 'sent');

        $order->update(['status' => 'sent']);

        return $order->fresh();
    }

    /**
     * استلام بضاعة.
     * تدخل المخزون بتكلفتها، ويُقيَّد مقابلها التزام مستلمة غير مفوترة.
     *
     * @param  array<int, array{purchase_order_item_id: int, quantity: int, condition_note?: string}>  $lines
     */
    public function receive(PurchaseOrder $order, User $actor, array $lines, array $data = []): GoodsReceipt
    {
        if (! in_array($order->status, ['sent', 'partially_received'], true)) {
            throw new BusinessRuleException('الاستلام يكون على أمر شراء مُرسَل.');
        }

        $order->loadMissing('items');
        $pending = $order->pendingQuantities();
        $items = $order->items->keyBy('id');

        $lines = collect($lines)->filter(fn ($row) => ($row['quantity'] ?? 0) > 0)->values();

        if ($lines->isEmpty()) {
            throw new BusinessRuleException('حدّد الكميات المستلمة.');
        }

        foreach ($lines as $row) {
            $item = $items[$row['purchase_order_item_id']] ?? null;

            if (! $item) {
                throw new BusinessRuleException('سطر لا ينتمي إلى أمر الشراء هذا.');
            }

            $available = $pending[$item->id] ?? 0;

            if ((int) $row['quantity'] > $available) {
                throw new BusinessRuleException(
                    "الكمية المستلمة من {$item->description} تتجاوز المتبقي ({$available})."
                );
            }
        }

        return DB::transaction(function () use ($order, $actor, $lines, $data, $items) {
            $warehouse = isset($data['warehouse_id'])
                ? Warehouse::withoutGlobalScope('tenant')->findOrFail($data['warehouse_id'])
                : $order->warehouse;

            $receipt = new GoodsReceipt([
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'reference_no' => $this->sequencer->next($order->tenant_id, 'goods_receipt', 'GRN'),
                'received_date' => $data['received_date'] ?? today(),
                'vendor_note_no' => $data['vendor_note_no'] ?? null,
                'currency' => $order->currency,
                'notes' => $data['notes'] ?? null,
                'received_by' => $actor->id,
            ]);
            $receipt->tenant_id = $order->tenant_id;
            $receipt->save();

            $total = 0;

            foreach ($lines as $row) {
                /** @var PurchaseOrderItem $item */
                $item = $items[$row['purchase_order_item_id']];
                $quantity = (int) $row['quantity'];
                $cost = $item->unit_cost * $quantity;
                $total += $cost;

                $movementId = null;

                // الأصناف المرتبطة بمنتج تدخل المخزون؛ غيرها (خدمات، شحن) لا
                if ($item->product_id) {
                    $movement = $this->inventory->receive(
                        $this->inventory->itemFor($item->product, $warehouse, $item->variant_id),
                        $quantity,
                        $item->unit_cost,
                        'purchase_in',
                        $receipt,
                        false,                      // القيد يُرحَّل مجمَّعاً للاستلام كله
                        'goods_received_not_invoiced',
                        "استلام {$receipt->reference_no}",
                    );

                    $movementId = $movement->id;
                }

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'unit_cost' => $item->unit_cost,
                    'total_cost' => $cost,
                    'inventory_movement_id' => $movementId,
                    'condition_note' => $row['condition_note'] ?? null,
                ]);

                $item->increment('received_quantity', $quantity);
            }

            $receipt->forceFill(['total_cost' => $total])->save();

            $this->postReceipt($receipt, Money::of($total, $order->currency));
            $this->refreshOrderStatus($order);

            return $receipt->load('items');
        });
    }

    /**
     * فاتورة المورّد.
     * تُسقط التزام "مستلمة غير مفوترة" وتُنشئ ذمة دائنة، وتحتسب
     * ضريبة المشتريات القابلة للخصم.
     */
    public function bill(PurchaseOrder $order, User $actor, array $lines, array $data = []): Invoice
    {
        $order->loadMissing('items');
        $uninvoiced = $order->uninvoicedQuantities();
        $items = $order->items->keyBy('id');

        $lines = collect($lines)->filter(fn ($row) => ($row['quantity'] ?? 0) > 0)->values();

        if ($lines->isEmpty()) {
            // بلا تحديد: تُفوتَر كل الكميات المستلمة ولم تُفوتَر
            $lines = collect($uninvoiced)
                ->filter(fn ($quantity) => $quantity > 0)
                ->map(fn ($quantity, $id) => ['purchase_order_item_id' => $id, 'quantity' => $quantity])
                ->values();
        }

        if ($lines->isEmpty()) {
            throw new BusinessRuleException('لا توجد كميات مستلمة بانتظار الفوترة.');
        }

        foreach ($lines as $row) {
            $item = $items[$row['purchase_order_item_id']] ?? null;

            if (! $item) {
                throw new BusinessRuleException('سطر لا ينتمي إلى أمر الشراء هذا.');
            }

            // المطابقة الثلاثية: لا تُفوتَر كمية لم تُستلم
            $available = $uninvoiced[$item->id] ?? 0;

            if ((int) $row['quantity'] > $available) {
                throw new BusinessRuleException(sprintf(
                    'لا تُفوتَر كمية لم تُستلم: %s — المستلم غير المفوتَر %d والمطلوب فوترته %d.',
                    $item->description,
                    $available,
                    (int) $row['quantity'],
                ));
            }
        }

        return DB::transaction(function () use ($order, $lines, $data, $items) {
            $vendor = $order->vendor;
            $net = 0;
            $tax = 0;

            $invoice = new Invoice([
                'invoice_no' => $this->sequencer->next($order->tenant_id, 'purchase_invoice', 'BILL'),
                'type' => 'purchase',
                'contact_type' => Vendor::class,
                'contact_id' => $vendor->id,
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $order->id,
                'issue_date' => $data['issue_date'] ?? today(),
                'due_date' => $data['due_date']
                    ?? today()->addDays($vendor->payment_terms_days),
                'currency' => $order->currency,
                'exchange_rate' => $order->exchange_rate,
                'status' => 'issued',
                'notes' => $data['vendor_invoice_no'] ?? null,
            ]);
            $invoice->tenant_id = $order->tenant_id;
            $invoice->save();

            foreach ($lines as $row) {
                /** @var PurchaseOrderItem $item */
                $item = $items[$row['purchase_order_item_id']];
                $quantity = (int) $row['quantity'];
                $lineNet = $item->unit_cost * $quantity;

                // الضريبة بنسبة الكمية المفوترة من كمية السطر الأصلية
                $lineTax = $item->quantity > 0
                    ? (int) round($item->tax_amount * $quantity / $item->quantity)
                    : 0;

                $net += $lineNet;
                $tax += $lineTax;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $quantity,
                    'unit_price' => $item->unit_cost,
                    'tax_code_id' => $item->tax_code_id,
                    'tax_amount' => $lineTax,
                    'line_total' => $lineNet + $lineTax,
                ]);

                $item->increment('invoiced_quantity', $quantity);
            }

            $invoice->forceFill([
                'subtotal' => $net,
                'tax_amount' => $tax,
                'total' => $net + $tax,
                'balance_due' => $net + $tax,
            ])->save();

            $this->postBill($order, $invoice, Money::of($net, $order->currency), Money::of($tax, $order->currency));

            return $invoice->load('items');
        });
    }

    /** قيد الاستلام: المخزون مدين / مستلمة غير مفوترة دائن. */
    private function postReceipt(GoodsReceipt $receipt, Money $amount): void
    {
        if ($amount->isZero()) {
            return;
        }

        $entry = $this->poster->post(
            JournalDraft::for($receipt->tenant, $amount->currency)
                ->on($receipt->received_date)
                ->describe("استلام بضاعة {$receipt->reference_no}")
                ->from($receipt)
                ->idempotent("goods_receipt:receipt:{$receipt->id}")
                ->debit('inventory', $amount)
                ->credit('goods_received_not_invoiced', $amount)
        );

        $receipt->forceFill(['journal_entry_id' => $entry->id])->save();
    }

    /** قيد الفاتورة: إسقاط الالتزام المؤقت وإنشاء الذمة الدائنة. */
    private function postBill(PurchaseOrder $order, Invoice $invoice, Money $net, Money $tax): void
    {
        $draft = JournalDraft::for($order->tenant, $net->currency)
            ->on($invoice->issue_date)
            ->describe("فاتورة مورّد {$invoice->invoice_no}")
            ->from($invoice)
            ->idempotent("purchase_invoice:invoice:{$invoice->id}")
            ->debit('goods_received_not_invoiced', $net);

        if (! $tax->isZero()) {
            $draft->debit('input_vat', $tax);
        }

        $draft->credit('accounts_payable', $net->add($tax), null, $order->vendor);

        $entry = $this->poster->post($draft);

        $invoice->forceFill(['journal_entry_id' => $entry->id])->save();
    }

    private function refreshOrderStatus(PurchaseOrder $order): void
    {
        $order->load('items');

        $order->update([
            'status' => $order->isFullyReceived() ? 'received' : 'partially_received',
        ]);
    }

    private function assertTransition(PurchaseOrder $order, string $status): void
    {
        if (! $order->canMoveTo($status)) {
            throw new BusinessRuleException(
                "لا يمكن نقل أمر الشراء من '{$order->status}' إلى '{$status}'."
            );
        }
    }
}
