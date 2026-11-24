<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountBalance;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * محرك ترحيل القيود.
 *
 * ضمانات هذا المحرك:
 *  1. لا يُرحَّل قيد غير متوازن.
 *  2. لا يُرحَّل قيد في فترة مقفلة.
 *  3. نفس الحدث لا ينتج قيدين (idempotency).
 *  4. القيد وأسطره وأرصدته تُكتب في معاملة واحدة — كلها أو لا شيء.
 *  5. القيد المُرحَّل لا يُعدَّل — التصحيح بقيد عكسي.
 */
class JournalPoster
{
    public function __construct(
        private readonly PeriodResolver $periods,
        private readonly DocumentSequencer $sequencer,
        private readonly CurrencyConverter $converter,
    ) {}

    public function post(JournalDraft $draft): JournalEntry
    {
        if ($draft->lines() === []) {
            throw new RuntimeException('لا يمكن ترحيل قيد بلا أسطر.');
        }

        if (! $draft->isBalanced()) {
            throw new RuntimeException(sprintf(
                'القيد غير متوازن: مدين %d ≠ دائن %d',
                $draft->totalDebit(),
                $draft->totalCredit()
            ));
        }

        // منع التكرار قبل فتح المعاملة
        if ($key = $draft->idempotencyKey()) {
            $existing = JournalEntry::withoutGlobalScope('tenant')
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($draft) {
            $tenant = $draft->tenant;
            $period = $this->periods->forDate($tenant, $draft->date());

            $this->periods->assertPostable($period, in_array($draft->type(), ['adjusting', 'closing'], true));

            $baseCurrency = $tenant->base_currency;
            $rate = $this->converter->rate($draft->currency, $baseCurrency, $draft->date());

            $entry = new JournalEntry([
                'tenant_id' => $tenant->id,
                'period_id' => $period->id,
                'entry_no' => $this->sequencer->next($tenant->id, 'journal_entry', 'JE'),
                'entry_date' => $draft->date()->toDateString(),
                'type' => $draft->type(),
                'source_type' => $draft->source() ? $draft->source()::class : null,
                'source_id' => $draft->source()?->getKey(),
                'idempotency_key' => $draft->idempotencyKey(),
                'description' => $draft->descriptionText(),
                'currency' => $draft->currency,
                'exchange_rate' => $rate,
                'total_debit' => $draft->totalDebit(),
                'total_credit' => $draft->totalCredit(),
                'status' => 'posted',
                'posted_by' => auth()->id(),
                'posted_at' => now(),
            ]);
            $entry->tenant_id = $tenant->id;
            $entry->saveQuietly();

            $lineNo = 1;

            foreach ($draft->lines() as $line) {
                $account = $this->resolveAccount($tenant->id, $line['accountKey']);

                /** @var Money|null $debit */
                $debit = $line['debit'];
                /** @var Money|null $credit */
                $credit = $line['credit'];

                $baseDebit = $debit ? $this->converter->convertAtRate($debit, $baseCurrency, $rate)->amount : 0;
                $baseCredit = $credit ? $this->converter->convertAtRate($credit, $baseCurrency, $rate)->amount : 0;

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'tenant_id' => $tenant->id,
                    'account_id' => $account->id,
                    'line_no' => $lineNo++,
                    'debit' => $debit?->amount ?? 0,
                    'credit' => $credit?->amount ?? 0,
                    'currency' => $draft->currency,
                    'exchange_rate' => $rate,
                    'base_debit' => $baseDebit,
                    'base_credit' => $baseCredit,
                    'description' => $line['description'],
                    'contact_type' => $line['contact'] ? $line['contact']::class : null,
                    'contact_id' => $line['contact']?->getKey(),
                ]);

                $this->bumpBalance($tenant->id, $account->id, $period->id, $baseDebit, $baseCredit);
            }

            return $entry->load('lines');
        });
    }

    /**
     * عكس قيد مُرحَّل. لا يُحذف الأصل — يُنشأ قيد مقابل ويُربط به.
     */
    public function reverse(JournalEntry $entry, ?string $reason = null): JournalEntry
    {
        if ($entry->status !== 'posted') {
            throw new RuntimeException('لا يمكن عكس قيد غير مُرحَّل.');
        }

        if ($entry->reversed_by_entry_id !== null) {
            throw new RuntimeException('هذا القيد معكوس مسبقاً.');
        }

        return DB::transaction(function () use ($entry, $reason) {
            $tenant = $entry->tenant;
            $draft = JournalDraft::for($tenant, $entry->currency)
                ->on($entry->entry_date)
                ->ofType('reversal')
                ->describe($reason ?? "عكس القيد {$entry->entry_no}")
                ->idempotent("reversal:journal_entry:{$entry->id}");

            foreach ($entry->lines as $line) {
                $account = $line->account;
                $money = Money::of(($line->debit ?: $line->credit), $entry->currency);

                // المدين يصبح دائناً والعكس
                if ($line->debit > 0) {
                    $draft->credit($account->system_key ?? $account->code, $money, $line->description);
                } else {
                    $draft->debit($account->system_key ?? $account->code, $money, $line->description);
                }
            }

            $reversal = $this->post($draft);

            $entry->status = 'reversed';
            $entry->reversed_by_entry_id = $reversal->id;
            $entry->saveQuietly();

            return $reversal;
        });
    }

    private function resolveAccount(int $tenantId, string $key): Account
    {
        $account = Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('system_key', $key)->orWhere('code', $key))
            ->first();

        if (! $account) {
            throw new RuntimeException("الحساب '{$key}' غير موجود في دليل حسابات المستأجر {$tenantId}.");
        }

        if (! $account->is_leaf || ! $account->is_active) {
            throw new RuntimeException("الحساب '{$key}' غير قابل للترحيل عليه (غير طرفي أو غير نشط).");
        }

        return $account;
    }

    private function bumpBalance(int $tenantId, int $accountId, int $periodId, int $debit, int $credit): void
    {
        $balance = AccountBalance::withoutGlobalScope('tenant')->firstOrNew([
            'account_id' => $accountId,
            'period_id' => $periodId,
        ]);

        $balance->tenant_id = $tenantId;
        $balance->period_debit = (int) $balance->period_debit + $debit;
        $balance->period_credit = (int) $balance->period_credit + $credit;
        $balance->save();
    }
}
