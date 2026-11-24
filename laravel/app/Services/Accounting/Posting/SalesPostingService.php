<?php

namespace App\Services\Accounting\Posting;

use App\Models\Accounting\Invoice;
use App\Models\Accounting\Payment;
use App\Models\Orders\Order;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Services\Inventory\OrderStockService;
use App\Support\Money\Money;

/**
 * قيود دورة المبيعات في دفتر المستورد (المورد).
 * مرجع القيود: docs/02-accounting-system.md §3.2 (أ)
 */
class SalesPostingService
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly OrderStockService $stock,
    ) {}

    /**
     * (1) إصدار فاتورة مبيعات
     *     من ح/ العملاء              بإجمالي الفاتورة
     *         إلى ح/ إيراد المبيعات  بالصافي
     *         إلى ح/ ضريبة المبيعات  بالضريبة
     */
    public function postInvoiceIssued(Invoice $invoice): void
    {
        $tenant = $invoice->tenant;
        $currency = $invoice->currency;

        $net = Money::of((int) $invoice->subtotal - (int) $invoice->discount_amount, $currency);
        $tax = Money::of((int) $invoice->tax_amount, $currency);
        $shipping = Money::of((int) $invoice->shipping_amount, $currency);
        $total = Money::of((int) $invoice->total, $currency);

        $draft = JournalDraft::for($tenant, $currency)
            ->on($invoice->issue_date)
            ->describe("فاتورة مبيعات {$invoice->invoice_no}")
            ->from($invoice)
            ->idempotent("invoice_issued:invoice:{$invoice->id}")
            ->debit('accounts_receivable', $total, "فاتورة {$invoice->invoice_no}", $invoice->contact)
            ->credit('sales_revenue', $net);

        if (! $shipping->isZero()) {
            $draft->credit('shipping_revenue', $shipping);
        }

        if (! $tax->isZero()) {
            $draft->credit('output_vat', $tax);
        }

        $entry = $this->poster->post($draft);

        $invoice->journal_entry_id = $entry->id;
        $invoice->save();
    }

    /**
     * (2) تكلفة البضاعة المباعة
     *     من ح/ تكلفة البضاعة المباعة
     *         إلى ح/ المخزون
     */
    public function postCostOfGoodsSold(Order $order): void
    {
        // المستورد الذي يمسك مخزوناً: التكلفة تأتي من الإخراج الفعلي
        // بطريقة التكلفة المعتمدة لديه. غيره: التكلفة اليدوية على السطر.
        $cost = $this->stock->issueForOrder($order)
            ?? array_sum($order->items->map(fn ($i) => $i->totalCost())->all());

        if ($cost <= 0) {
            return;   // لا تكلفة مسجَّلة لهذه الأصناف
        }

        $tenant = $order->supplier;
        $money = Money::of($cost, $order->currency);

        $this->poster->post(
            JournalDraft::for($tenant, $order->currency)
                ->on($order->delivered_at ?? now())
                ->describe("تكلفة البضاعة المباعة — الطلب {$order->order_no}")
                ->from($order)
                ->idempotent("cogs:order:{$order->id}")
                ->debit('cogs', $money)
                ->credit('inventory', $money)
        );
    }

    /**
     * (4) تحصيل الفاتورة
     *     في وضع المحفظة: من ح/ المحفظة إلى ح/ العملاء
     *     في وضع التحصيل اليدوي: من ح/ الصندوق أو البنك إلى ح/ العملاء
     */
    public function postPaymentReceived(Payment $payment): void
    {
        $tenant = $payment->tenant;
        $amount = Money::of($payment->amount, $payment->currency);

        $debitAccount = match ($payment->method) {
            'wallet', 'gateway' => 'platform_wallet',
            'bank', 'cheque' => 'bank',
            default => 'cash',
        };

        $entry = $this->poster->post(
            JournalDraft::for($tenant, $payment->currency)
                ->on($payment->payment_date)
                ->describe("تحصيل {$payment->payment_no}")
                ->from($payment)
                ->idempotent("payment_received:payment:{$payment->id}")
                ->debit($debitAccount, $amount)
                ->credit('accounts_receivable', $amount, null, $payment->contact)
        );

        $payment->journal_entry_id = $entry->id;
        $payment->save();
    }

    /**
     * (7) مردود مبيعات / إشعار دائن
     *     من ح/ مردودات المبيعات + ضريبة المبيعات
     *         إلى ح/ العملاء
     */
    public function postCreditNote(Invoice $invoice, Money $net, Money $tax): void
    {
        $tenant = $invoice->tenant;
        $total = $net->add($tax);

        $draft = JournalDraft::for($tenant, $invoice->currency)
            ->on(now())
            ->describe("إشعار دائن على الفاتورة {$invoice->invoice_no}")
            ->from($invoice)
            ->idempotent("credit_note:invoice:{$invoice->id}:".$total->amount)
            ->debit('sales_returns', $net);

        if (! $tax->isZero()) {
            $draft->debit('output_vat', $tax);
        }

        $draft->credit('accounts_receivable', $total, null, $invoice->contact);

        $this->poster->post($draft);
    }
}
