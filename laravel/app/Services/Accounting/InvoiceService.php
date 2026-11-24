<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Invoice;
use App\Models\Orders\Order;
use App\Services\Accounting\Posting\SalesPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * توليد فاتورة المبيعات من أمر الشراء وترحيل قيدها.
 * الفاتورة هي المستند المصدر للقيد — وبها يكتمل الاعتراف بالإيراد.
 */
class InvoiceService
{
    public function __construct(
        private readonly DocumentSequencer $sequencer,
        private readonly SalesPostingService $posting,
    ) {}

    public function fromOrder(Order $order): Invoice
    {
        $existing = Invoice::withoutGlobalScope('tenant')
            ->where('order_id', $order->id)
            ->where('type', 'sales')
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order) {
            $supplier = $order->supplier;
            $issueDate = $order->delivered_at ?? now();

            $invoice = new Invoice([
                'invoice_no' => $this->sequencer->next($supplier->id, 'invoice', 'INV'),
                'type' => 'sales',
                'contact_type' => $order->buyer::class,
                'contact_id' => $order->buyer_tenant_id,
                'order_id' => $order->id,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->copy()->addDays($order->payment_terms_days)->toDateString(),
                // التحويل الصريح يحمي من قيم null إذا لم يُحمَّل الطلب من قاعدة البيانات
                'subtotal' => (int) $order->subtotal,
                'discount_amount' => (int) $order->discount_amount,
                'tax_amount' => (int) $order->tax_amount,
                'shipping_amount' => (int) $order->shipping_amount,
                'total' => (int) $order->total,
                'paid_amount' => 0,
                'balance_due' => (int) $order->total,
                'currency' => $order->currency,
                'exchange_rate' => (float) ($order->exchange_rate ?: 1),
                'status' => 'issued',
                'uuid' => (string) Str::uuid(),
            ]);
            $invoice->tenant_id = $supplier->id;
            $invoice->save();

            foreach ($order->items as $item) {
                $invoice->items()->create([
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => (int) $item->unit_price,
                    'discount_amount' => (int) $item->discount_amount,
                    'tax_code_id' => $item->tax_code_id,
                    'tax_amount' => (int) $item->tax_amount,
                    'line_total' => (int) $item->line_total,
                ]);
            }

            $invoice->setRelation('tenant', $supplier);
            $invoice->setRelation('contact', $order->buyer);

            $this->posting->postInvoiceIssued($invoice);

            return $invoice->refresh();
        });
    }
}
