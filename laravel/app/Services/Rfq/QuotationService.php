<?php

namespace App\Services\Rfq;

use App\Models\Orders\Order;
use App\Models\Orders\OrderItem;
use App\Models\Orders\OrderStatusHistory;
use App\Models\Rfq\Quotation;
use App\Models\Rfq\RfqInvitation;
use App\Models\User;
use App\Notifications\QuotationAcceptedNotification;
use App\Notifications\QuotationReceivedNotification;
use App\Services\Accounting\DocumentSequencer;
use App\Services\Mail\TransactionalMail;
use Illuminate\Support\Facades\DB;

class QuotationService
{
    public function __construct(private readonly DocumentSequencer $sequencer) {}

    public function send(Quotation $quotation): Quotation
    {
        $quotation = DB::transaction(function () use ($quotation) {
            $quotation->update(['status' => 'sent', 'sent_at' => now()]);

            if ($quotation->rfq_id) {
                RfqInvitation::where('rfq_id', $quotation->rfq_id)
                    ->where('supplier_tenant_id', $quotation->supplier_tenant_id)
                    ->update(['responded_at' => now()]);

                $rfq = $quotation->rfq;
                $rfq->forceFill([
                    'status' => 'quoted',
                    'quotations_count' => $rfq->quotations()->whereIn('status', ['sent', 'countered'])->count(),
                ])->save();
            }

            return $quotation;
        });

        $this->notifyTenant(
            $quotation->buyer_tenant_id,
            new QuotationReceivedNotification($quotation->load('supplier'))
        );

        return $quotation;
    }

    /** قبول العرض يحوّله آلياً إلى أمر شراء. */
    public function accept(Quotation $quotation): Order
    {
        if (! $quotation->isActionable()) {
            throw new \RuntimeException('هذا العرض غير قابل للقبول — منتهي الصلاحية أو تم البتّ فيه.');
        }

        $lostIds = [];

        $order = DB::transaction(function () use ($quotation, &$lostIds) {
            $order = Order::create([
                'order_no' => $this->sequencer->next(null, 'order', 'PO'),
                'buyer_tenant_id' => $quotation->buyer_tenant_id,
                'supplier_tenant_id' => $quotation->supplier_tenant_id,
                'quotation_id' => $quotation->id,
                'created_by' => auth()->id() ?? $quotation->created_by,
                'subtotal' => $quotation->subtotal,
                'discount_amount' => $quotation->discount_amount,
                'tax_amount' => $quotation->tax_amount,
                'shipping_amount' => $quotation->shipping_amount,
                'total' => $quotation->total,
                'currency' => $quotation->currency,
                'exchange_rate' => $quotation->exchange_rate,
                'base_total' => (int) round($quotation->total * $quotation->exchange_rate),
                'payment_terms_days' => $quotation->payment_terms_days,
                'incoterm' => $quotation->incoterm,
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            foreach ($quotation->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'discount_amount' => $item->discount_amount,
                    'tax_code_id' => $item->tax_code_id,
                    'tax_amount' => $item->tax_amount,
                    'line_total' => $item->line_total,
                ]);
            }

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => 'confirmed',
                'user_id' => auth()->id(),
                'note' => "مولّد من قبول العرض {$quotation->quotation_no}",
                'created_at' => now(),
            ]);

            $quotation->update(['status' => 'accepted', 'decided_at' => now()]);

            if ($rfq = $quotation->rfq) {
                $lostIds = $rfq->quotations()
                    ->where('id', '!=', $quotation->id)
                    ->whereIn('status', ['sent', 'countered'])
                    ->pluck('id')
                    ->all();

                $rfq->update(['status' => 'awarded']);
                $rfq->quotations()
                    ->whereIn('id', $lostIds)
                    ->update(['status' => 'rejected', 'decided_at' => now()]);
            }

            return $order->load('items');
        });

        $this->notifyTenant(
            $quotation->supplier_tenant_id,
            new QuotationAcceptedNotification($quotation, $order)
        );

        $this->emailOrderConfirmed($order);
        $this->emailLostQuotations($lostIds);

        return $order;
    }

    /** تأكيد للمشتري أن قبوله صار أمر شراء. */
    private function emailOrderConfirmed(Order $order): void
    {
        User::where('tenant_id', $order->buyer_tenant_id)
            ->where('status', 'active')
            ->get()
            ->each(function (User $user) use ($order) {
                $en = $user->locale === 'en';

                app(TransactionalMail::class)->send($user, 'order', [
                    'order_no' => $order->order_no,
                    'status' => $en ? 'Confirmed' : 'مؤكَّد',
                    'amount' => number_format($order->total / 100, 2).' '.$order->currency,
                ], '/orders');
            });
    }

    /** المستوردون الذين رُفض عرضهم لأن المشتري اختار غيره. */
    private function emailLostQuotations(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Quotation::with('rfq')->whereIn('id', $ids)->get()->each(function (Quotation $quote) {
            User::where('tenant_id', $quote->supplier_tenant_id)
                ->where('status', 'active')
                ->get()
                ->each(fn (User $user) => app(TransactionalMail::class)->send($user, 'rejected', [
                    'title' => $quote->rfq?->title ?? '',
                    'reference' => $quote->quotation_no,
                ], '/rfq-inbox'));
        });
    }

    /** إشعار كل مستخدمي مستأجر معيّن. */
    private function notifyTenant(int $tenantId, object $notification): void
    {
        User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get()
            ->each(fn (User $user) => $user->notify($notification));
    }
}
