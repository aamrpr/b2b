<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\FiscalYear;
use App\Models\Tenancy\Tenant;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * إيجاد الفترة المحاسبية لتاريخ معيّن، وإنشاء السنة المالية وفتراتها عند الحاجة.
 */
class PeriodResolver
{
    public function forDate(Tenant $tenant, Carbon $date): AccountingPeriod
    {
        $period = AccountingPeriod::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        return $period ?? $this->createYearFor($tenant, $date);
    }

    public function assertPostable(AccountingPeriod $period, bool $adjusting = false): void
    {
        if (! $period->acceptsPosting($adjusting)) {
            throw new RuntimeException(
                "الفترة المحاسبية {$period->name} مقفلة — لا يمكن الترحيل فيها."
            );
        }
    }

    /** ينشئ السنة المالية التي تحتوي التاريخ، مع 12 فترة شهرية. */
    public function createYearFor(Tenant $tenant, Carbon $date): AccountingPeriod
    {
        $startMonth = $tenant->fiscal_year_start_month ?: 1;

        $start = Carbon::create($date->year, $startMonth, 1)->startOfDay();
        if ($date->lt($start)) {
            $start->subYear();
        }
        $end = $start->copy()->addYear()->subDay()->endOfDay();

        $year = FiscalYear::withoutGlobalScope('tenant')->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $start->format('Y')],
            ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'status' => 'open']
        );

        for ($i = 0; $i < 12; $i++) {
            $pStart = $start->copy()->addMonths($i)->startOfMonth();
            $pEnd = $pStart->copy()->endOfMonth();

            AccountingPeriod::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenant->id, 'fiscal_year_id' => $year->id, 'name' => $pStart->format('Y-m')],
                ['start_date' => $pStart->toDateString(), 'end_date' => $pEnd->toDateString(), 'status' => 'open']
            );
        }

        $period = AccountingPeriod::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if (! $period) {
            throw new RuntimeException('تعذّر إنشاء الفترة المحاسبية للتاريخ '.$date->toDateString());
        }

        return $period;
    }
}
