<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Invoice;
use App\Models\Tenancy\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * القوائم المالية. كلها تُشتق من أسطر القيود المرحَّلة فقط —
 * لا يوجد مصدر آخر للأرقام، وهذا ما يجعلها قابلة للتدقيق.
 */
class FinancialReportService
{
    /** حركات الحسابات بعملة الدفتر خلال فترة. */
    private function movements(Tenant $tenant, ?Carbon $from, Carbon $to): Collection
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('jl.tenant_id', $tenant->id)
            ->where('je.status', 'posted')
            ->when($from, fn ($q) => $q->whereDate('je.entry_date', '>=', $from))
            ->whereDate('je.entry_date', '<=', $to)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance', 'a.system_key')
            ->select([
                'a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance', 'a.system_key',
                DB::raw('COALESCE(SUM(jl.base_debit), 0) AS debit'),
                DB::raw('COALESCE(SUM(jl.base_credit), 0) AS credit'),
            ])
            ->orderBy('a.code')
            ->get();
    }

    /**
     * المبلغ بإشارته الصحيحة داخل قسمه في القائمة.
     *
     * الحساب المقابِل (مردودات المبيعات، مخصص الديون، المسحوبات) طبيعته
     * معاكسة لنوعه، فرصيده يجب أن يُطرح من قسمه لا أن يُضاف إليه —
     * وإلا ضخّمت المردودات الإيراد بدل أن تخفّضه.
     */
    private function signedAmount(object $row): int
    {
        $debit = (int) $row->debit;
        $credit = (int) $row->credit;

        $balance = $row->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;
        $natural = in_array($row->type, ['asset', 'expense'], true) ? 'debit' : 'credit';

        return $row->normal_balance === $natural ? $balance : -$balance;
    }

    private function label(object $row): array
    {
        $name = json_decode($row->name, true) ?: [];

        return [
            'ar' => $name['ar'] ?? $row->code,
            'en' => $name['en'] ?? $row->code,
        ];
    }

    /** ميزان المراجعة — يجب أن يتساوى المدين والدائن دائماً. */
    public function trialBalance(Tenant $tenant, Carbon $from, Carbon $to): array
    {
        $rows = $this->movements($tenant, $from, $to);

        $accounts = $rows->map(function ($row) {
            $debit = (int) $row->debit;
            $credit = (int) $row->credit;
            $net = $row->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;

            return [
                'account_id' => $row->id,
                'code' => $row->code,
                'name' => $this->label($row),
                'type' => $row->type,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $net,
                'balance_side' => $row->normal_balance,
            ];
        })->values();

        $totalDebit = (int) $rows->sum('debit');
        $totalCredit = (int) $rows->sum('credit');

        return [
            'currency' => $tenant->base_currency,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'accounts' => $accounts,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'difference' => $totalDebit - $totalCredit,
                'is_balanced' => $totalDebit === $totalCredit,
            ],
        ];
    }

    /** قائمة الدخل: إيرادات − تكلفة المبيعات = مجمل الربح − مصروفات = صافي الربح. */
    public function incomeStatement(Tenant $tenant, Carbon $from, Carbon $to): array
    {
        $rows = $this->movements($tenant, $from, $to)
            ->filter(fn ($r) => in_array($r->type, ['revenue', 'expense'], true));

        $revenue = [];
        $cogs = [];
        $expenses = [];

        foreach ($rows as $row) {
            $amount = $this->signedAmount($row);

            $line = ['code' => $row->code, 'name' => $this->label($row), 'amount' => $amount];

            if ($row->type === 'revenue') {
                $revenue[] = $line;
            } elseif ($row->system_key === 'cogs') {
                // تكلفة المبيعات تُحدَّد بمفتاح الحساب لا ببادئة الكود،
                // وإلا دخلت عمولة المنصة (5150) في مجمل الربح خطأً
                $cogs[] = $line;
            } else {
                $expenses[] = $line;
            }
        }

        $totalRevenue = array_sum(array_column($revenue, 'amount'));
        $totalCogs = array_sum(array_column($cogs, 'amount'));
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $grossProfit = $totalRevenue - $totalCogs;

        return [
            'currency' => $tenant->base_currency,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue' => ['lines' => $revenue, 'total' => $totalRevenue],
            'cost_of_sales' => ['lines' => $cogs, 'total' => $totalCogs],
            'gross_profit' => $grossProfit,
            'gross_margin_pct' => $totalRevenue > 0 ? round($grossProfit / $totalRevenue * 100, 2) : 0.0,
            'operating_expenses' => ['lines' => $expenses, 'total' => $totalExpenses],
            'net_profit' => $grossProfit - $totalExpenses,
        ];
    }

    /** المركز المالي: الأصول = الالتزامات + حقوق الملكية + صافي ربح الفترة. */
    public function balanceSheet(Tenant $tenant, Carbon $asOf): array
    {
        $rows = $this->movements($tenant, null, $asOf);

        $assets = [];
        $liabilities = [];
        $equity = [];
        $periodResult = 0;

        foreach ($rows as $row) {
            $amount = $this->signedAmount($row);
            $line = ['code' => $row->code, 'name' => $this->label($row), 'amount' => $amount];

            match ($row->type) {
                'asset' => $assets[] = $line,
                'liability' => $liabilities[] = $line,
                'equity' => $equity[] = $line,
                'revenue' => $periodResult += $amount,
                'expense' => $periodResult -= $amount,
            };
        }

        $totalAssets = array_sum(array_column($assets, 'amount'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount'));
        $totalEquity = array_sum(array_column($equity, 'amount')) + $periodResult;

        return [
            'currency' => $tenant->base_currency,
            'as_of' => $asOf->toDateString(),
            'assets' => ['lines' => $assets, 'total' => $totalAssets],
            'liabilities' => ['lines' => $liabilities, 'total' => $totalLiabilities],
            'equity' => [
                'lines' => $equity,
                'current_period_result' => $periodResult,
                'total' => $totalEquity,
            ],
            'check' => [
                'assets' => $totalAssets,
                'liabilities_plus_equity' => $totalLiabilities + $totalEquity,
                'difference' => $totalAssets - ($totalLiabilities + $totalEquity),
                'is_balanced' => $totalAssets === ($totalLiabilities + $totalEquity),
            ],
        ];
    }

    /** أعمار الذمم المدينة — متابعة التحصيل. */
    public function receivablesAging(Tenant $tenant): array
    {
        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        $details = [];

        $invoices = Invoice::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('type', 'sales')
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance_due', '>', 0)
            ->get();

        foreach ($invoices as $invoice) {
            $bucket = $invoice->agingBucket();
            $buckets[$bucket] += $invoice->balance_due;

            $details[] = [
                'invoice_no' => $invoice->invoice_no,
                'due_date' => $invoice->due_date->toDateString(),
                'balance_due' => $invoice->balance_due,
                'age_days' => $invoice->ageInDays(),
                'bucket' => $bucket,
            ];
        }

        return [
            'currency' => $tenant->base_currency,
            'buckets' => $buckets,
            'total' => array_sum($buckets),
            'invoices' => $details,
        ];
    }
}
