<?php

namespace App\Services\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\Accounting\CreditNote;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Payment;
use App\Models\Accounting\PaymentAllocation;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Accounting\DocumentSequencer;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * التحصيل والصرف.
 *
 * المبدأ: الدفعة تُقبَض أولاً ثم تُوزَّع. ما وُزِّع يُسقط ذمة العميل،
 * وما لم يُوزَّع يبقى **دفعة مقدمة** — التزاماً على المستورد لا إيراداً.
 * الخلط بينهما يُظهر المنصة وكأنها حصّلت ديوناً لم تُحصَّل.
 */
class PaymentService
{
    /** حساب النقدية حسب وسيلة الدفع. */
    private const CASH_ACCOUNTS = [
        'cash' => 'cash',
        'bank' => 'bank',
        'cheque' => 'bank',
        'hawala' => 'cash',
        'wallet' => 'platform_wallet',
        'gateway' => 'platform_wallet',
    ];

    public function __construct(
        private readonly DocumentSequencer $sequencer,
        private readonly JournalPoster $poster,
    ) {}

    /**
     * قبض من عميل، مع توزيع اختياري على فواتير.
     *
     * @param  array<int, array{invoice_id: int, amount: int}>  $allocations
     */
    public function receive(Tenant $tenant, User $actor, array $data, array $allocations = []): Payment
    {
        $amount = (int) $data['amount'];

        if ($amount <= 0) {
            throw new BusinessRuleException('مبلغ الدفعة يجب أن يكون موجباً.');
        }

        $fee = (int) ($data['fee_amount'] ?? 0);

        if ($fee >= $amount) {
            throw new BusinessRuleException('الرسوم لا يمكن أن تبلغ قيمة الدفعة أو تتجاوزها.');
        }

        $invoices = $this->validateAllocations($tenant, $allocations, $amount, 'sales');

        // الدفعة الموزَّعة على فواتير عميل تخصّ ذلك العميل حتى لو لم يُذكر —
        // وإلا سقطت من كشف حسابه وبقي رصيده الجاري مضخَّماً
        $data['contact'] = $this->resolveContact($data['contact'] ?? null, $invoices);

        return DB::transaction(function () use ($tenant, $actor, $data, $allocations, $invoices, $amount, $fee) {
            $payment = $this->createPayment($tenant, $actor, $data, 'in');

            $allocated = 0;

            foreach ($allocations as $row) {
                $invoice = $invoices[$row['invoice_id']];
                $this->attach($payment, $invoice, (int) $row['amount']);
                $allocated += (int) $row['amount'];
            }

            $this->postReceipt($payment, $amount, $allocated, $fee, $data['contact'] ?? null);

            return $payment->load('allocations');
        });
    }

    /** صرف لمورّد، مع توزيع اختياري على فواتير شراء. */
    public function pay(Tenant $tenant, User $actor, array $data, array $allocations = []): Payment
    {
        $amount = (int) $data['amount'];

        if ($amount <= 0) {
            throw new BusinessRuleException('مبلغ الدفعة يجب أن يكون موجباً.');
        }

        $invoices = $this->validateAllocations($tenant, $allocations, $amount, 'purchase');

        $data['contact'] = $this->resolveContact($data['contact'] ?? null, $invoices);

        return DB::transaction(function () use ($tenant, $actor, $data, $allocations, $invoices, $amount) {
            $payment = $this->createPayment($tenant, $actor, $data, 'out');

            foreach ($allocations as $row) {
                $this->attach($payment, $invoices[$row['invoice_id']], (int) $row['amount']);
            }

            $money = Money::of($amount, $payment->currency);

            $entry = $this->poster->post(
                JournalDraft::for($tenant, $payment->currency)
                    ->on($payment->payment_date)
                    ->describe("صرف {$payment->payment_no}")
                    ->from($payment)
                    ->idempotent("payment_out:payment:{$payment->id}")
                    ->debit('accounts_payable', $money, null, $data['contact'] ?? null)
                    ->credit($this->cashAccount($payment->method), $money)
            );

            $payment->forceFill(['journal_entry_id' => $entry->id])->save();

            return $payment->load('allocations');
        });
    }

    /**
     * توزيع لاحق لدفعة مقبوضة سابقاً:
     * تتحوّل من دفعة مقدمة إلى سداد يُسقط الذمة.
     */
    public function allocate(Payment $payment, array $allocations): Payment
    {
        if ($payment->direction !== 'in') {
            throw new BusinessRuleException('التوزيع اللاحق للمقبوضات فقط.');
        }

        $unallocated = $payment->unallocatedAmount();
        $total = array_sum(array_column($allocations, 'amount'));

        if ($total > $unallocated) {
            throw new BusinessRuleException(sprintf(
                'المبلغ الموزَّع (%s) يتجاوز غير الموزَّع من الدفعة (%s).',
                $this->display($total),
                $this->display($unallocated),
            ));
        }

        $invoices = $this->validateAllocations($payment->tenant, $allocations, $unallocated, 'sales');

        return DB::transaction(function () use ($payment, $allocations, $invoices, $total) {
            foreach ($allocations as $row) {
                $this->attach($payment, $invoices[$row['invoice_id']], (int) $row['amount']);
            }

            $money = Money::of($total, $payment->currency);

            $this->poster->post(
                JournalDraft::for($payment->tenant, $payment->currency)
                    ->describe("توزيع الدفعة {$payment->payment_no}")
                    ->from($payment)
                    ->idempotent("payment_allocated:payment:{$payment->id}:{$total}")
                    ->debit('customer_advances', $money, null, $payment->contact)
                    ->credit('accounts_receivable', $money, null, $payment->contact)
            );

            return $payment->fresh()->load('allocations');
        });
    }

    /** كشف حساب عميل: الفواتير والمدفوعات والرصيد الجاري. */
    public function statement(Tenant $tenant, Model $contact): array
    {
        $invoices = Invoice::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('contact_type', $contact::class)
            ->where('contact_id', $contact->getKey())
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderBy('issue_date')
            ->get();

        $payments = Payment::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('contact_type', $contact::class)
            ->where('contact_id', $contact->getKey())
            ->where('status', 'completed')
            ->orderBy('payment_date')
            ->get();

        // إشعارات الائتمان بنود دائنة كالمدفوعات: إغفالها يجعل رصيد
        // الكشف يخالف مجموع أرصدة الفواتير متى وُجد مرتجع
        $credits = CreditNote::withoutGlobalScope('tenant')
            ->where('credit_notes.tenant_id', $tenant->id)
            ->where('credit_notes.status', 'issued')
            ->join('invoices', 'invoices.id', '=', 'credit_notes.invoice_id')
            ->where('invoices.contact_type', $contact::class)
            ->where('invoices.contact_id', $contact->getKey())
            ->select('credit_notes.*')
            ->orderBy('credit_notes.issue_date')
            ->get();

        $lines = $invoices->map(fn (Invoice $invoice) => [
            'date' => $invoice->issue_date->toDateString(),
            'type' => 'invoice',
            'reference' => $invoice->invoice_no,
            'debit' => $invoice->total,
            'credit' => 0,
        ])->concat($payments->map(fn (Payment $payment) => [
            'date' => $payment->payment_date->toDateString(),
            'type' => 'payment',
            'reference' => $payment->payment_no,
            'debit' => 0,
            'credit' => $payment->amount,
        ]))->concat($credits->map(fn (CreditNote $note) => [
            'date' => $note->issue_date->toDateString(),
            'type' => 'credit_note',
            'reference' => $note->note_no,
            'debit' => 0,
            'credit' => $note->total,
        ]))->sortBy('date')->values();

        $running = 0;
        $lines = $lines->map(function ($line) use (&$running) {
            $running += $line['debit'] - $line['credit'];

            return [...$line, 'balance' => $running];
        });

        return [
            'currency' => $tenant->base_currency,
            'contact' => ['id' => $contact->getKey(), 'name' => $contact->name ?? null],
            'lines' => $lines->all(),
            'balance' => $running,
            'outstanding' => (int) $invoices->sum('balance_due'),
        ];
    }

    /**
     * الطرف المُعطى صراحةً يفوز؛ وإلا يُشتق من الفواتير.
     * اختلاف الأطراف في دفعة واحدة خطأ: السند يخصّ طرفاً واحداً.
     *
     * @param  array<int, Invoice>  $invoices
     */
    private function resolveContact(?Model $given, array $invoices): ?Model
    {
        if ($given !== null || $invoices === []) {
            return $given;
        }

        $contacts = collect($invoices)
            ->filter(fn (Invoice $invoice) => $invoice->contact_id !== null)
            ->map(fn (Invoice $invoice) => [$invoice->contact_type, $invoice->contact_id])
            ->unique(fn ($pair) => implode(':', $pair))
            ->values();

        if ($contacts->count() > 1) {
            throw new BusinessRuleException('لا يُوزَّع سند واحد على فواتير أطراف مختلفة.');
        }

        if ($contacts->isEmpty()) {
            return null;
        }

        [$type, $id] = $contacts->first();

        return $type::find($id);
    }

    private function createPayment(Tenant $tenant, User $actor, array $data, string $direction): Payment
    {
        /** @var Model|null $contact */
        $contact = $data['contact'] ?? null;

        $payment = new Payment([
            'payment_no' => $this->sequencer->next($tenant->id, 'payment', $direction === 'in' ? 'RCP' : 'PMT'),
            'direction' => $direction,
            'contact_type' => $contact ? $contact::class : null,
            'contact_id' => $contact?->getKey(),
            'method' => $data['method'] ?? 'cash',
            'gateway' => $data['gateway'] ?? null,
            'gateway_reference' => $data['gateway_reference'] ?? null,
            'amount' => (int) $data['amount'],
            'currency' => $data['currency'] ?? $tenant->base_currency,
            'exchange_rate' => $data['exchange_rate'] ?? 1,
            'fee_amount' => (int) ($data['fee_amount'] ?? 0),
            'payment_date' => $data['payment_date'] ?? today(),
            'status' => 'completed',
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
        ]);
        $payment->tenant_id = $tenant->id;
        $payment->save();

        return $payment;
    }

    /**
     * قيد القبض: النقد بعد الرسوم مدين، والرسوم مصروف،
     * والمقابل ذمة العميل بقدر الموزَّع ودفعة مقدمة بالباقي.
     */
    private function postReceipt(Payment $payment, int $amount, int $allocated, int $fee, ?Model $contact): void
    {
        $currency = $payment->currency;

        $draft = JournalDraft::for($payment->tenant, $currency)
            ->on($payment->payment_date)
            ->describe("تحصيل {$payment->payment_no}")
            ->from($payment)
            ->idempotent("payment_in:payment:{$payment->id}")
            ->debit($this->cashAccount($payment->method), Money::of($amount - $fee, $currency));

        if ($fee > 0) {
            $draft->debit('operating_expense', Money::of($fee, $currency), 'رسوم تحصيل');
        }

        if ($allocated > 0) {
            $draft->credit('accounts_receivable', Money::of($allocated, $currency), null, $contact);
        }

        if ($amount > $allocated) {
            $draft->credit('customer_advances', Money::of($amount - $allocated, $currency), null, $contact);
        }

        $entry = $this->poster->post($draft);

        $payment->forceFill(['journal_entry_id' => $entry->id])->save();
    }

    /**
     * @return array<int, Invoice>
     */
    private function validateAllocations(Tenant $tenant, array $allocations, int $ceiling, string $type): array
    {
        if ($allocations === []) {
            return [];
        }

        $total = array_sum(array_column($allocations, 'amount'));

        if ($total > $ceiling) {
            throw new BusinessRuleException(sprintf(
                'مجموع التوزيع (%s) يتجاوز مبلغ الدفعة (%s).',
                $this->display($total),
                $this->display($ceiling),
            ));
        }

        $invoices = Invoice::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', array_column($allocations, 'invoice_id'))
            ->get()
            ->keyBy('id');

        foreach ($allocations as $row) {
            $invoice = $invoices[$row['invoice_id']] ?? null;

            if (! $invoice) {
                throw new BusinessRuleException("الفاتورة {$row['invoice_id']} ليست من فواتير هذا المستأجر.");
            }

            if ($invoice->type !== $type) {
                throw new BusinessRuleException("الفاتورة {$invoice->invoice_no} ليست من نوع {$type}.");
            }

            if ((int) $row['amount'] <= 0) {
                throw new BusinessRuleException('مبلغ التوزيع يجب أن يكون موجباً.');
            }

            if ((int) $row['amount'] > $invoice->balance_due) {
                throw new BusinessRuleException(sprintf(
                    'المبلغ الموزَّع على %s يتجاوز رصيدها المستحق (%s).',
                    $invoice->invoice_no,
                    $this->display($invoice->balance_due),
                ));
            }
        }

        return $invoices->all();
    }

    private function attach(Payment $payment, Invoice $invoice, int $amount): void
    {
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
        ]);

        $invoice->applyPayment($amount);
    }

    /** المبالغ تُخزَّن بالوحدة الصغرى؛ رسائل المستخدم تُظهرها بالكبرى. */
    private function display(int $minor): string
    {
        return number_format($minor / 100, 2);
    }

    private function cashAccount(string $method): string
    {
        return self::CASH_ACCOUNTS[$method] ?? 'cash';
    }
}
