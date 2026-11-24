<?php

namespace App\Services\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\Accounting\CreditNote;
use App\Models\Accounting\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * تطبيق إشعارات الائتمان على الفواتير.
 *
 * الإشعار عند إصداره يُسقط ذمة العميل بالكامل في الدفتر، فالتطبيق هنا
 * توزيع للرصيد بين الفواتير لا قيد جديد — وإلا خُصم المبلغ مرتين.
 */
class CreditNoteService
{
    /** يطبّق ما أمكن من الإشعار على فاتورة ويعيد المبلغ المطبَّق فعلاً. */
    public function applyToInvoice(CreditNote $note, Invoice $invoice, ?int $amount = null): int
    {
        if ($note->status !== 'issued') {
            throw new BusinessRuleException('لا يُطبَّق إلا إشعار صادر.');
        }

        if ($invoice->tenant_id !== $note->tenant_id) {
            throw new BusinessRuleException('الفاتورة والإشعار من مستأجرين مختلفين.');
        }

        if ($invoice->contact_id !== null && $note->invoice?->contact_id !== null
            && $invoice->contact_id !== $note->invoice->contact_id) {
            throw new BusinessRuleException('لا يُطبَّق إشعار عميل على فاتورة عميل آخر.');
        }

        $available = $note->unappliedAmount();

        if ($available <= 0) {
            throw new BusinessRuleException('لا رصيد متبقٍّ في هذا الإشعار.');
        }

        // لا يُطبَّق أكثر من الرصيد المتاح ولا أكثر مما تستحقه الفاتورة
        $applied = min($amount ?? $available, $available, $invoice->balance_due);

        if ($applied <= 0) {
            return 0;
        }

        DB::transaction(function () use ($note, $invoice, $applied) {
            $note->forceFill(['applied_amount' => $note->applied_amount + $applied])->save();

            $invoice->balance_due = max(0, $invoice->balance_due - $applied);
            $invoice->status = $invoice->derivedStatus();
            $invoice->save();
        });

        return $applied;
    }

    /** الرصيد غير المطبَّق من كل إشعارات عميل — يُعرض عند التحصيل. */
    public function availableCreditFor(int $tenantId, string $contactType, int $contactId): int
    {
        return (int) CreditNote::withoutGlobalScope('tenant')
            ->where('credit_notes.tenant_id', $tenantId)
            ->where('credit_notes.status', 'issued')
            ->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            ->where('invoices.contact_type', $contactType)
            ->where('invoices.contact_id', $contactId)
            ->selectRaw('COALESCE(SUM(credit_notes.total - credit_notes.applied_amount), 0) AS available')
            ->value('available');
    }
}
