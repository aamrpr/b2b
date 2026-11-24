<?php

namespace App\Services\Orders;

use App\Models\Orders\Order;
use App\Models\Orders\OrderStatusHistory;
use App\Models\User;
use App\Notifications\OrderStatusChangedNotification;
use App\Services\Accounting\InvoiceService;
use App\Services\Accounting\Posting\CommissionPostingService;
use App\Services\Accounting\Posting\SalesPostingService;
use App\Services\Inventory\OrderStockService;
use Illuminate\Support\Facades\DB;

/**
 * نقطة واحدة لتغيير حالة الطلب.
 *
 * التسليم يُطلق سلسلة كاملة — فاتورة، إخراج مخزون، تكلفة، عمولة —
 * ويُستدعى من مسارين: تحديث يدوي من المستورد، أو تسليم شحنة.
 * وجودها في مكان واحد يمنع تفرّع السلوك بين المسارين.
 */
class OrderFulfillmentService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly SalesPostingService $sales,
        private readonly CommissionPostingService $commissions,
        private readonly OrderStockService $stock,
    ) {}

    /** نواقص المخزون التي تمنع التسليم. */
    public function blockingShortages(Order $order): array
    {
        return $this->stock->shortages($order->loadMissing('items'));
    }

    public function transition(Order $order, string $status, ?User $actor = null, ?string $note = null): Order
    {
        $from = $order->status;

        if ($from === $status) {
            return $order;
        }

        DB::transaction(function () use ($order, $status, $actor, $note, $from) {
            $order->status = $status;

            match ($status) {
                'shipped' => $order->shipped_at ??= now(),
                'delivered' => $order->delivered_at ??= now(),
                'closed' => $order->closed_at ??= now(),
                default => null,
            };

            $order->save();

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $status,
                'user_id' => $actor?->id,
                'note' => $note,
                'created_at' => now(),
            ]);
        });

        $this->notifyBuyer($order, $status);

        if ($status === config('b2b.accounting.commission_trigger')) {
            $this->postFinancials($order);
        }

        return $order->refresh();
    }

    /** الفاتورة والتكلفة والعمولة — تُرحَّل مرة واحدة بفضل مفاتيح منع التكرار. */
    public function postFinancials(Order $order): void
    {
        $order->load('items.product', 'supplier', 'buyer');

        $this->invoices->fromOrder($order);
        $this->sales->postCostOfGoodsSold($order);
        $this->commissions->accrue($order);
    }

    private function notifyBuyer(Order $order, string $status): void
    {
        User::where('tenant_id', $order->buyer_tenant_id)
            ->where('status', 'active')
            ->get()
            ->each(fn (User $user) => $user->notify(new OrderStatusChangedNotification($order, $status)));
    }
}
