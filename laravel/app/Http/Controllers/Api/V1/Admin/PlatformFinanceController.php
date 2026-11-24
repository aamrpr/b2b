<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Commission;
use App\Models\Tenancy\Tenant;
use App\Services\Accounting\Posting\CommissionPostingService;
use App\Services\Admin\AuditLogger;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * المركز المالي للمنصة: متابعة العمولات المستحقة وتحصيلها.
 * في وضع التحصيل اليدوي (سوق بلا بوابات دفع) هذه هي الشاشة التي
 * تُدار منها دورة النقد كلها.
 */
class PlatformFinanceController extends Controller
{
    public function __construct(
        private readonly CommissionPostingService $commissions,
        private readonly AuditLogger $audit,
    ) {}

    public function commissions(Request $request): JsonResponse
    {
        return response()->json(
            DataTable::make($request, Commission::query()->with(['supplier', 'order']))
                ->sortable([
                    'id' => 'id',
                    'amount' => 'amount',
                    'base_amount' => 'base_amount',
                    'rate' => 'rate',
                    'status' => 'status',
                    'accrued_at' => 'accrued_at',
                    'settled_at' => 'settled_at',
                ])
                ->filter('status', fn ($query, $value) => $query->where('status', $value))
                ->filter('supplier', fn ($query, $value) => $query->whereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$value}%")))
                ->filter('order_no', fn ($query, $value) => $query->whereHas('order', fn ($o) => $o->where('order_no', 'like', "%{$value}%")))
                ->filter('amount', fn ($query, $value) => ColumnMatch::money(
                    $query,
                    '(commissions.amount - commissions.reversed_amount)',
                    $value,
                ))
                ->filter('accrued_at', fn ($query, $value) => ColumnMatch::date($query, 'accrued_at', $value))
                ->defaultOrder('id')
                ->transform(fn (Commission $commission) => [
                    'id' => $commission->id,
                    'order_no' => $commission->order?->order_no,
                    'supplier' => ['id' => $commission->supplier_tenant_id, 'name' => $commission->supplier?->name],
                    'base_amount' => $commission->base_amount,
                    'rate' => $commission->rate,
                    'amount' => $commission->netAmount(),
                    'gross_amount' => $commission->amount,
                    'reversed_amount' => $commission->reversed_amount,
                    'currency' => $commission->currency,
                    'status' => $commission->status,
                    'accrued_at' => $commission->accrued_at?->toDateString(),
                    'settled_at' => $commission->settled_at?->toDateString(),
                    'age_days' => $commission->accrued_at && $commission->status !== 'settled'
                        ? (int) $commission->accrued_at->diffInDays(now())
                        : 0,
                ])
                ->toArray()
        );
    }

    /** أعمار العمولات غير المحصَّلة — أهم تقرير في وضع التحصيل اليدوي. */
    public function commissionAging(): JsonResponse
    {
        $rows = Commission::query()
            ->whereIn('status', ['accrued', 'invoiced'])
            ->with('supplier')
            ->get();

        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        $bySupplier = [];

        foreach ($rows as $commission) {
            $age = $commission->accrued_at ? (int) $commission->accrued_at->diffInDays(now()) : 0;

            $bucket = match (true) {
                $age <= 0 => 'current',
                $age <= 30 => '1_30',
                $age <= 60 => '31_60',
                $age <= 90 => '61_90',
                default => 'over_90',
            };

            $buckets[$bucket] += $commission->netAmount();

            $id = $commission->supplier_tenant_id;
            $bySupplier[$id] ??= [
                'supplier_id' => $id,
                'name' => $commission->supplier?->name,
                'currency' => $commission->currency,
                'total' => 0,
                'oldest_days' => 0,
                'count' => 0,
            ];

            $bySupplier[$id]['total'] += $commission->netAmount();
            $bySupplier[$id]['count']++;
            $bySupplier[$id]['oldest_days'] = max($bySupplier[$id]['oldest_days'], $age);
        }

        usort($bySupplier, fn ($a, $b) => $b['total'] <=> $a['total']);

        return response()->json([
            'buckets' => $buckets,
            'total' => array_sum($buckets),
            'by_supplier' => array_values($bySupplier),
        ]);
    }

    /** تسجيل تحصيل عمولة — يولّد القيدين في دفتري المنصة والمستورد. */
    public function settle(Request $request, Commission $commission): JsonResponse
    {
        abort_if($commission->status === 'settled', 422, __('هذه العمولة محصَّلة مسبقاً.'));

        $net = $commission->netAmount();

        $this->commissions->settle($commission);

        $this->audit->record('commission.settled', $commission,
            ['status' => 'accrued'],
            ['status' => 'settled', 'amount' => $net],
        );

        return response()->json([
            'message' => __('تم تسجيل التحصيل وترحيل القيدين.'),
            'commission' => $commission->fresh(),
        ]);
    }

    /** تحصيل جماعي لكل عمولات مستورد. */
    public function settleSupplier(Request $request, Tenant $tenant): JsonResponse
    {
        $pending = Commission::where('supplier_tenant_id', $tenant->id)
            ->whereIn('status', ['accrued', 'invoiced'])
            ->get();

        abort_if($pending->isEmpty(), 422, __('لا توجد عمولات مستحقة على هذا المستورد.'));

        $total = 0;

        DB::transaction(function () use ($pending, &$total) {
            foreach ($pending as $commission) {
                // الصافي يُقرأ قبل التحصيل: هو المبلغ المُرحَّل،
                // وهو وحده ما يصحّ إعلانه وتسجيله في سجل التدقيق
                $total += $commission->netAmount();
                $this->commissions->settle($commission);
            }
        });

        $this->audit->record('commission.settled_bulk', $tenant, [],
            ['count' => $pending->count(), 'total' => $total],
        );

        return response()->json([
            'message' => __('تم تحصيل :count عمولة.', ['count' => $pending->count()]),
            'count' => $pending->count(),
            'total' => $total,
        ]);
    }
}
