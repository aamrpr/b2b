<?php

namespace App\Services\Returns;

use App\Exceptions\BusinessRuleException;
use App\Models\Accounting\CreditNote;
use App\Models\Accounting\CreditNoteItem;
use App\Models\Accounting\Invoice;
use App\Models\Inventory\InventoryItem;
use App\Models\Orders\Order;
use App\Models\Returns\ReturnRequest;
use App\Models\Returns\ReturnRequestItem;
use App\Models\User;
use App\Notifications\ReturnUpdateNotification;
use App\Services\Accounting\DocumentSequencer;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Posting\CommissionPostingService;
use App\Services\Inventory\InventoryService;
use App\Services\Payments\CreditNoteService;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * دورة المرتجع.
 *
 * القاعدة الحاكمة: **لا يُقيَّد شيء إلا على ما استُلم فعلاً**.
 * الطلب نيّة، والاعتماد موافقة، والاستلام هو الحدث المالي —
 * وقبله لا إشعار دائن ولا إرجاع مخزون ولا عكس عمولة.
 */
class ReturnService
{
    public function __construct(
        private readonly DocumentSequencer $sequencer,
        private readonly JournalPoster $poster,
        private readonly CommissionPostingService $commissions,
        private readonly InventoryService $inventory,
    ) {}

    /** الكميات القابلة للإرجاع بعد خصم ما أُرجع سابقاً. */
    public function returnableQuantities(Order $order): array
    {
        $returned = ReturnRequestItem::query()
            ->whereHas('returnRequest', fn ($q) => $q->where('order_id', $order->id)
                ->whereNotIn('status', ['rejected', 'cancelled']))
            ->groupBy('order_item_id')
            ->selectRaw('order_item_id, SUM(GREATEST(approved_quantity, quantity)) AS returned')
            ->pluck('returned', 'order_item_id');

        return $order->items
            ->mapWithKeys(fn ($line) => [
                $line->id => max(0, $line->quantity - (int) ($returned[$line->id] ?? 0)),
            ])
            ->all();
    }

    public function request(Order $order, User $actor, array $data): ReturnRequest
    {
        if ($order->status !== 'delivered' && $order->status !== 'closed') {
            throw new BusinessRuleException('لا يُفتح مرتجع إلا على طلب مُسلَّم.');
        }

        $returnable = $this->returnableQuantities($order->loadMissing('items'));
        $lines = collect($data['items'] ?? [])->filter(fn ($row) => ($row['quantity'] ?? 0) > 0)->values();

        if ($lines->isEmpty()) {
            throw new BusinessRuleException('حدّد الأصناف والكميات المطلوب إرجاعها.');
        }

        foreach ($lines as $row) {
            $available = $returnable[$row['order_item_id']] ?? 0;

            if ($row['quantity'] > $available) {
                throw new BusinessRuleException(
                    "الكمية المطلوب إرجاعها ({$row['quantity']}) تتجاوز القابل للإرجاع ({$available})."
                );
            }
        }

        return DB::transaction(function () use ($order, $actor, $data, $lines) {
            $request = ReturnRequest::create([
                'order_id' => $order->id,
                'buyer_tenant_id' => $order->buyer_tenant_id,
                'supplier_tenant_id' => $order->supplier_tenant_id,
                'reference_no' => $this->sequencer->next(null, 'return_request', 'RET'),
                'type' => $data['type'] ?? 'refund',
                'reason' => $data['reason'],
                'description' => $data['description'] ?? null,
                'currency' => $order->currency,
                'restock' => $data['restock'] ?? true,
                'status' => 'requested',
                'created_by' => $actor->id,
            ]);

            $total = 0;

            foreach ($lines as $row) {
                $line = $order->items->firstWhere('id', $row['order_item_id']);
                $lineTotal = $line->unit_price * $row['quantity'];
                $total += $lineTotal;

                ReturnRequestItem::create([
                    'return_request_id' => $request->id,
                    'order_item_id' => $line->id,
                    'quantity' => $row['quantity'],
                    'unit_price' => $line->unit_price,
                    'unit_cost' => $line->unit_cost,
                    'line_total' => $lineTotal,
                    'condition_note' => $row['condition_note'] ?? null,
                ]);
            }

            $request->update(['requested_amount' => $total]);

            $this->notify($request, 'requested', $request->supplier_tenant_id);

            return $request->load('items');
        });
    }

    /** اعتماد المستورد — قد يعتمد كميات أقل مما طُلب. */
    public function approve(ReturnRequest $request, User $actor, array $approvedQuantities = [], ?string $note = null): ReturnRequest
    {
        $this->assertTransition($request, 'approved');

        return DB::transaction(function () use ($request, $actor, $approvedQuantities, $note) {
            $total = 0;

            foreach ($request->items as $item) {
                $approved = $approvedQuantities[$item->id] ?? $item->quantity;
                $approved = max(0, min($approved, $item->quantity));

                $item->update(['approved_quantity' => $approved]);
                $total += $approved * $item->unit_price;
            }

            $request->update([
                'status' => 'approved',
                'approved_amount' => $total,
                'decision_note' => $note,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            $this->notify($request, 'approved', $request->buyer_tenant_id);

            return $request->fresh()->load('items');
        });
    }

    public function reject(ReturnRequest $request, User $actor, string $reason): ReturnRequest
    {
        $this->assertTransition($request, 'rejected');

        $request->update([
            'status' => 'rejected',
            'decision_note' => $reason,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);

        $this->notify($request, 'rejected', $request->buyer_tenant_id);

        return $request->fresh();
    }

    public function markInTransit(ReturnRequest $request, User $actor): ReturnRequest
    {
        $this->assertTransition($request, 'in_transit');

        $request->update(['status' => 'in_transit']);
        $this->notify($request, 'in_transit', $request->supplier_tenant_id);

        return $request->fresh();
    }

    /**
     * استلام البضاعة المرتجعة. هنا يبدأ الأثر المالي:
     * المخزون يعود بتكلفته، والقيمة المستلمة هي أساس إشعار الائتمان.
     */
    public function receive(ReturnRequest $request, User $actor, array $receivedQuantities = []): ReturnRequest
    {
        $this->assertTransition($request, 'received');

        return DB::transaction(function () use ($request, $actor, $receivedQuantities) {
            foreach ($request->items as $item) {
                $fallback = $item->approved_quantity ?: $item->quantity;
                $received = $receivedQuantities[$item->id] ?? $fallback;
                $received = max(0, min($received, $fallback));

                $item->update(['received_quantity' => $received]);
            }

            $request->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            $fresh = $request->fresh()->load('items.orderItem', 'order.items');

            if ($fresh->restock) {
                $this->restock($fresh, $actor);
            }

            return $fresh;
        });
    }

    /**
     * إقفال المرتجع: إشعار دائن + قيده + عكس التكلفة + عكس حصّة العمولة.
     */
    public function complete(ReturnRequest $request): CreditNote
    {
        $this->assertTransition($request, 'completed');

        $request->loadMissing('items.orderItem', 'order.supplier', 'order.buyer');
        $value = $request->receivedValue();

        if ($value <= 0) {
            throw new BusinessRuleException('لا توجد كميات مستلمة — لا شيء يُقيَّد.');
        }

        return DB::transaction(function () use ($request, $value) {
            $order = $request->order;
            $supplier = $order->supplier;
            $invoice = Invoice::withoutGlobalScope('tenant')
                ->where('order_id', $order->id)
                ->where('type', 'sales')
                ->first();

            // الضريبة بنفس نسبة الفاتورة الأصلية على القيمة المرتجعة
            $taxRatio = $invoice && $invoice->subtotal > 0
                ? $invoice->tax_amount / $invoice->subtotal
                : 0;
            $tax = (int) round($value * $taxRatio);

            $note = new CreditNote([
                'invoice_id' => $invoice?->id,
                'return_request_id' => $request->id,
                'note_no' => $this->sequencer->next($supplier->id, 'credit_note', 'CN'),
                'issue_date' => today(),
                'subtotal' => $value,
                'tax_amount' => $tax,
                'total' => $value + $tax,
                'currency' => $request->currency,
                'exchange_rate' => $order->exchange_rate ?: 1,
                'reason' => $request->description ?? $request->reason,
                'status' => 'issued',
            ]);
            $note->tenant_id = $supplier->id;
            $note->save();

            foreach ($request->items->where('received_quantity', '>', 0) as $item) {
                CreditNoteItem::create([
                    'credit_note_id' => $note->id,
                    'description' => $item->orderItem?->description ?? '—',
                    'quantity' => $item->received_quantity,
                    'unit_price' => $item->unit_price,
                    'tax_amount' => (int) round($item->received_quantity * $item->unit_price * ($tax / max($value, 1))),
                    'line_total' => $item->received_quantity * $item->unit_price,
                ]);
            }

            $entry = $this->postCreditNote($request, $note, Money::of($value, $request->currency), Money::of($tax, $request->currency));
            $note->update(['journal_entry_id' => $entry]);

            $this->reverseCost($request);

            // العمولة تُعكس على الصافي المرتجع لا على الإجمالي بالضريبة
            $this->commissions->reversePortion(
                $order,
                Money::of($value, $request->currency),
                "return:{$request->id}"
            );

            // يُطبَّق على الفاتورة الأصلية بقدر رصيدها، ويبقى الفائض
            // رصيداً مفتوحاً للعميل يُطبَّق على فاتورة لاحقة
            if ($invoice) {
                app(CreditNoteService::class)->applyToInvoice($note, $invoice);
            }

            $request->update(['status' => 'completed', 'completed_at' => now()]);

            $this->notify($request, 'completed', $request->buyer_tenant_id);

            return $note->fresh()->load('items');
        });
    }

    /** إرجاع البضاعة إلى المخزون بتكلفتها الأصلية. */
    private function restock(ReturnRequest $request, User $actor): void
    {
        foreach ($request->items as $item) {
            if ($item->received_quantity <= 0 || ! $item->orderItem?->product_id) {
                continue;
            }

            $inventoryItem = InventoryItem::withoutGlobalScope('tenant')
                ->where('tenant_id', $request->supplier_tenant_id)
                ->where('product_id', $item->orderItem->product_id)
                ->where('variant_id', $item->orderItem->variant_id)
                ->orderByDesc('quantity_on_hand')
                ->first();

            if (! $inventoryItem) {
                continue;
            }

            $this->inventory->receive(
                $inventoryItem,
                $item->received_quantity,
                $item->unit_cost,
                'return_in',
                $request,
                false,
                'inventory',
                "مرتجع {$request->reference_no}",
            );
        }
    }

    /** قيد إشعار الائتمان: مردودات + ضريبة مدين / العملاء دائن. */
    private function postCreditNote(ReturnRequest $request, CreditNote $note, Money $net, Money $tax): int
    {
        $supplier = $request->order->supplier;

        $draft = JournalDraft::for($supplier, $net->currency)
            ->describe("إشعار دائن {$note->note_no} — مرتجع {$request->reference_no}")
            ->from($note)
            ->idempotent("credit_note:return:{$request->id}")
            ->debit('sales_returns', $net);

        if (! $tax->isZero()) {
            $draft->debit('output_vat', $tax);
        }

        $draft->credit('accounts_receivable', $net->add($tax), null, $request->order->buyer);

        return $this->poster->post($draft)->id;
    }

    /** عكس تكلفة البضاعة المباعة بما عاد إلى المخزون. */
    private function reverseCost(ReturnRequest $request): void
    {
        $cost = $request->receivedCost();

        if ($cost <= 0 || ! $request->restock) {
            return;
        }

        $supplier = $request->order->supplier;
        $amount = Money::of($cost, $request->currency);

        $this->poster->post(
            JournalDraft::for($supplier, $amount->currency)
                ->describe("عكس تكلفة مرتجع {$request->reference_no}")
                ->from($request)
                ->idempotent("return_cogs:return:{$request->id}")
                ->debit('inventory', $amount)
                ->credit('cogs', $amount)
        );
    }

    private function assertTransition(ReturnRequest $request, string $status): void
    {
        if (! $request->canMoveTo($status)) {
            throw new BusinessRuleException(
                "لا يمكن نقل المرتجع من '{$request->status}' إلى '{$status}'."
            );
        }
    }

    private function notify(ReturnRequest $request, string $status, int $tenantId): void
    {
        /** @var Collection<int, User> $users */
        $users = User::where('tenant_id', $tenantId)->where('status', 'active')->get();

        $users->each(fn (User $user) => $user->notify(new ReturnUpdateNotification($request, $status)));
    }
}
