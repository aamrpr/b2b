<?php

namespace App\Services\Admin;

use App\Models\Accounting\Account;
use App\Models\Accounting\Commission;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Payment;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Commerce\Subscription;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\Warehouse;
use App\Models\Orders\Order;
use App\Models\Returns\Dispute;
use App\Models\Returns\ReturnRequest;
use App\Models\Rfq\Quotation;
use App\Models\Rfq\Rfq;
use App\Models\Shipping\Shipment;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Accounting\CurrencyConverter;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * مؤشرات المنصة.
 *
 * الإيراد يُقرأ من دفتر المنصة لا من جداول العمليات — فالدفتر هو
 * المصدر الوحيد للحقيقة المالية، وأي رقم هنا يجب أن يطابق القوائم.
 */
class PlatformMetricsService
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    public function overview(Carbon $from, Carbon $to): array
    {
        $platform = Tenant::platform();
        $currency = $platform->base_currency;

        return [
            'currency' => $currency,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'gmv' => $this->gmv($from, $to, $currency),
            'orders' => $this->orderCounts($from, $to),
            'commission' => $this->commissionTotals($platform, $from, $to, $currency),
            'subscriptions' => $this->subscriptionTotals($currency),
            'suppliers' => $this->supplierCounts(),
            'buyers' => Tenant::where('type', Tenant::TYPE_BUYER)->count(),
            'rfq' => $this->rfqFunnel($from, $to),
        ];
    }

    /**
     * إجمالي قيمة البضاعة المتداولة.
     * الطلبات قد تكون بعملات مختلفة، فتُجمَّع حسب العملة ثم تُحوَّل —
     * أدقّ من جمع أرقام غير متجانسة، وأرخص من تحويل كل سطر.
     */
    public function gmv(Carbon $from, Carbon $to, string $currency): array
    {
        $groups = Order::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('currency')
            ->selectRaw('currency, SUM(total) AS total, COUNT(*) AS orders')
            ->get();

        $converted = 0;
        $breakdown = [];

        foreach ($groups as $group) {
            $amount = Money::of((int) $group->total, $group->currency);
            $inBase = $this->converter->convert($amount, $currency, $to);

            $converted += $inBase->amount;
            $breakdown[] = [
                'currency' => $group->currency,
                'amount' => (int) $group->total,
                'converted' => $inBase->amount,
                'orders' => (int) $group->orders,
            ];
        }

        return ['total' => $converted, 'currency' => $currency, 'by_currency' => $breakdown];
    }

    private function orderCounts(Carbon $from, Carbon $to): array
    {
        $rows = Order::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS count')
            ->pluck('count', 'status');

        return [
            'total' => (int) $rows->sum(),
            'by_status' => $rows->map(fn ($c) => (int) $c),
        ];
    }

    /**
     * ملخّص المنصة كلّها في نداء واحد.
     *
     * لوحة المؤشّرات تُجيب «كيف تسير المنصة؟» بالمال والاتجاه، وهذا
     * يُجيب «ماذا فيها؟» بالعدد: كم مستورداً وكم منتجاً وكم طلباً في كل
     * حالة. وهما سؤالان مختلفان، فصفحتان لا صفحة.
     *
     * والعدّ هنا عدّ المنصة لا عدّ مستأجرٍ فيها، فما كان معزولاً بنطاق
     * المستأجر يُقرأ بتجاوزه صراحةً — وإلّا عدّت المنصةُ دفترَها وحده.
     */
    public function summary(): array
    {
        $orders = $this->statusCounts(Order::query());
        $shipments = $this->statusCounts(Shipment::withoutTenantScope());
        $returns = $this->statusCounts(ReturnRequest::query());
        $rfqs = $this->statusCounts(Rfq::query());
        $quotations = $this->statusCounts(Quotation::query());
        $products = $this->statusCounts(Product::query());
        $invoices = $this->statusCounts(Invoice::withoutTenantScope());
        $suppliers = $this->supplierCounts();

        // «المكتملة» هي ما سُلّم أو أُقفل: ما بعده لا عودة إلى دورة الشراء
        $completedOrders = ($orders['delivered'] ?? 0) + ($orders['closed'] ?? 0);

        return [
            'generated_at' => now()->toIso8601String(),

            'highlights' => [
                'suppliers' => $suppliers['total'],
                'buyers' => Tenant::where('type', Tenant::TYPE_BUYER)->count(),
                'products' => array_sum($products),
                'categories' => Category::count(),
                'completed_orders' => $completedOrders,
                'gmv_orders' => array_sum($orders),
            ],

            /*
             * بيانات الرسوم صريحة من الخادم لا مستخرَجة من الأقسام في
             * الواجهة: القسم ترتيبُ عرضٍ قد يتبدّل، والرسم له معناه —
             * قمعٌ يقرأ من الطلب إلى التسليم، وتوزّعٌ يقرأ تكوين الحسابات.
             */
            'charts' => [
                'funnel' => [
                    ['key' => 'rfqs', 'value' => array_sum($rfqs)],
                    ['key' => 'quotations', 'value' => array_sum($quotations)],
                    ['key' => 'orders', 'value' => array_sum($orders)],
                    ['key' => 'completed', 'value' => $completedOrders],
                ],
                'accounts' => [
                    ['key' => 'verified', 'value' => $suppliers['verified']],
                    ['key' => 'pending_review', 'value' => $suppliers['pending_review']],
                    ['key' => 'buyers', 'value' => Tenant::where('type', Tenant::TYPE_BUYER)->count()],
                ],
                'orders_by_status' => collect($orders)
                    ->map(fn ($value, $key) => ['key' => $key, 'value' => $value])
                    ->values()
                    ->all(),
            ],

            'sections' => [
                [
                    'key' => 'tenants',
                    'items' => [
                        ['key' => 'suppliers', 'value' => $suppliers['total']],
                        ['key' => 'verified', 'value' => $suppliers['verified']],
                        ['key' => 'pending_review', 'value' => $suppliers['pending_review'], 'tone' => 'warning'],
                        ['key' => 'buyers', 'value' => Tenant::where('type', Tenant::TYPE_BUYER)->count()],
                        ['key' => 'users', 'value' => User::count()],
                    ],
                ],
                [
                    'key' => 'catalog',
                    'items' => [
                        ['key' => 'products', 'value' => array_sum($products)],
                        ['key' => 'active', 'value' => $products['active'] ?? 0],
                        ['key' => 'draft', 'value' => $products['draft'] ?? 0],
                        ['key' => 'categories', 'value' => Category::count()],
                        ['key' => 'subcategories', 'value' => Category::whereNotNull('parent_id')->count()],
                        ['key' => 'images', 'value' => ProductMedia::count()],
                    ],
                ],
                [
                    'key' => 'demand',
                    'items' => [
                        ['key' => 'rfqs', 'value' => array_sum($rfqs)],
                        ['key' => 'open', 'value' => $rfqs['open'] ?? 0],
                        ['key' => 'awarded', 'value' => $rfqs['awarded'] ?? 0],
                        ['key' => 'quotations', 'value' => array_sum($quotations)],
                        ['key' => 'accepted', 'value' => $quotations['accepted'] ?? 0],
                    ],
                ],
                [
                    'key' => 'orders',
                    'items' => [
                        ['key' => 'orders', 'value' => array_sum($orders)],
                        ['key' => 'confirmed', 'value' => $orders['confirmed'] ?? 0],
                        ['key' => 'shipped', 'value' => $orders['shipped'] ?? 0],
                        ['key' => 'completed', 'value' => $completedOrders, 'tone' => 'success'],
                        ['key' => 'cancelled', 'value' => $orders['cancelled'] ?? 0],
                    ],
                ],
                [
                    'key' => 'logistics',
                    'items' => [
                        ['key' => 'shipments', 'value' => array_sum($shipments)],
                        ['key' => 'in_transit', 'value' => $shipments['in_transit'] ?? 0],
                        ['key' => 'delivered', 'value' => $shipments['delivered'] ?? 0],
                        ['key' => 'returns', 'value' => array_sum($returns)],
                        ['key' => 'disputes', 'value' => Dispute::count()],
                        ['key' => 'open_disputes', 'value' => Dispute::whereIn('status', ['open', 'under_review', 'awaiting_party'])->count(), 'tone' => 'warning'],
                    ],
                ],
                [
                    'key' => 'finance',
                    'items' => [
                        ['key' => 'invoices', 'value' => array_sum($invoices)],
                        ['key' => 'payments', 'value' => Payment::withoutTenantScope()->count()],
                        ['key' => 'commissions', 'value' => Commission::count()],
                        ['key' => 'settled', 'value' => Commission::where('status', 'settled')->count()],
                        ['key' => 'subscriptions', 'value' => Subscription::withoutTenantScope()->whereIn('status', ['trial', 'active'])->count()],
                    ],
                ],
                [
                    'key' => 'inventory',
                    'items' => [
                        ['key' => 'warehouses', 'value' => Warehouse::withoutTenantScope()->count()],
                        ['key' => 'items', 'value' => InventoryItem::withoutTenantScope()->count()],
                        ['key' => 'low_stock', 'value' => InventoryItem::withoutTenantScope()->whereColumn('quantity_on_hand', '<=', 'reorder_point')->where('reorder_point', '>', 0)->count(), 'tone' => 'warning'],
                    ],
                ],
                [
                    'key' => 'engagement',
                    'items' => [
                        ['key' => 'conversations', 'value' => Conversation::count()],
                        ['key' => 'messages', 'value' => Message::count()],
                    ],
                ],
            ],
        ];
    }

    /** عدد الصفوف لكل حالة في استعلام واحد لا استعلام لكل حالة. */
    private function statusCounts(Builder $query, string $column = 'status'): array
    {
        return $query
            ->groupBy($column)
            ->selectRaw("{$column} AS status_key, COUNT(*) AS rows_count")
            ->pluck('rows_count', 'status_key')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** الإيراد من دفتر المنصة + المستحق غير المحصَّل. */
    private function commissionTotals(Tenant $platform, Carbon $from, Carbon $to, string $currency): array
    {
        $revenue = $this->accountMovement($platform, 'commission_revenue', $from, $to);
        $receivable = $this->accountBalance($platform, 'commission_receivable');

        // الأعمدة الزمنية تُقارن بنهاية اليوم لا ببدايته،
        // وإلا سقطت عمليات اليوم نفسه من التقرير
        $window = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        // الصافي بعد عكس المرتجعات — وإلا خالف هذا الرقم الإيراد المقروء
        // من الدفتر والمستحق المعروض بجانبه على الشاشة نفسها
        $accrued = (int) Commission::whereBetween('accrued_at', $window)
            ->selectRaw('COALESCE(SUM(amount - reversed_amount), 0) AS net')
            ->value('net');
        $settled = (int) Commission::where('status', 'settled')
            ->whereBetween('settled_at', $window)
            ->selectRaw('COALESCE(SUM(amount - reversed_amount), 0) AS net')
            ->value('net');

        return [
            'revenue' => $revenue,          // من الدفتر — الرقم المعتمد
            'receivable' => $receivable,    // رصيد ذمم الموردين
            'accrued' => $accrued,
            'settled' => $settled,
            'currency' => $currency,
        ];
    }

    private function subscriptionTotals(string $currency): array
    {
        $active = Subscription::withoutGlobalScope('tenant')
            ->whereIn('status', ['trial', 'active'])
            ->where('ends_at', '>', now())
            ->get();

        // الإيراد الشهري المتكرر: الاشتراك السنوي يُقسَّم على 12
        $mrr = $active->sum(fn ($s) => $s->billing_cycle === 'yearly'
            ? (int) round($s->amount / 12)
            : $s->amount);

        return [
            'active' => $active->count(),
            'mrr' => (int) $mrr,
            'arr' => (int) $mrr * 12,
            'currency' => $currency,
        ];
    }

    private function supplierCounts(): array
    {
        $byVerification = DB::table('supplier_profiles')
            ->groupBy('verification_status')
            ->selectRaw('verification_status, COUNT(*) AS count')
            ->pluck('count', 'verification_status');

        return [
            'total' => Tenant::where('type', Tenant::TYPE_SUPPLIER)->count(),
            'active' => Tenant::where('type', Tenant::TYPE_SUPPLIER)->where('status', 'active')->count(),
            'pending_review' => (int) ($byVerification['pending'] ?? 0) + (int) ($byVerification['under_review'] ?? 0),
            'verified' => (int) ($byVerification['verified'] ?? 0),
        ];
    }

    /** قمع طلبات الأسعار: نُشر ⟵ وصله عرض ⟵ تحوّل إلى أمر شراء. */
    private function rfqFunnel(Carbon $from, Carbon $to): array
    {
        $window = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $published = Rfq::whereBetween('created_at', $window)->whereNot('status', 'draft')->count();
        $quoted = Rfq::whereBetween('created_at', $window)->where('quotations_count', '>', 0)->count();
        $awarded = Rfq::whereBetween('created_at', $window)->where('status', 'awarded')->count();

        return [
            'published' => $published,
            'quoted' => $quoted,
            'awarded' => $awarded,
            'quote_rate' => $published > 0 ? round($quoted / $published * 100, 1) : 0.0,
            'conversion_rate' => $published > 0 ? round($awarded / $published * 100, 1) : 0.0,
        ];
    }

    /** سلسلة شهرية للرسوم البيانية. */
    public function monthlySeries(int $months = 12): array
    {
        $start = now()->copy()->subMonths($months - 1)->startOfMonth();
        $platform = Tenant::platform();

        $orders = Order::query()
            ->where('created_at', '>=', $start)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS orders, SUM(total) AS gmv")
            ->pluck('gmv', 'month');

        $orderCounts = Order::query()
            ->where('created_at', '>=', $start)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->groupByRaw("DATE_FORMAT(created_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS c")
            ->pluck('c', 'month');

        $commissions = Commission::query()
            ->where('accrued_at', '>=', $start)
            ->groupByRaw("DATE_FORMAT(accrued_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(accrued_at, '%Y-%m') AS month, SUM(amount) AS total")
            ->pluck('total', 'month');

        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i)->format('Y-m');

            $series[] = [
                'month' => $month,
                'gmv' => (int) ($orders[$month] ?? 0),
                'orders' => (int) ($orderCounts[$month] ?? 0),
                'commission' => (int) ($commissions[$month] ?? 0),
            ];
        }

        return ['currency' => $platform->base_currency, 'series' => $series];
    }

    /** أعلى المستوردين بقيمة المبيعات. */
    public function topSuppliers(Carbon $from, Carbon $to, int $limit = 10): array
    {
        return Order::query()
            ->whereNotIn('orders.status', ['draft', 'cancelled'])
            ->whereBetween('orders.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->join('tenants', 'tenants.id', '=', 'orders.supplier_tenant_id')
            ->groupBy('tenants.id', 'tenants.name', 'tenants.slug', 'orders.currency')
            ->selectRaw('tenants.id, tenants.name, tenants.slug, orders.currency, COUNT(*) AS orders, SUM(orders.total) AS gmv, SUM(orders.commission_amount) AS commission')
            ->orderByDesc('gmv')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'currency' => $row->currency,
                'orders' => (int) $row->orders,
                'gmv' => (int) $row->gmv,
                'commission' => (int) $row->commission,
            ])
            ->all();
    }

    private function accountMovement(Tenant $tenant, string $systemKey, Carbon $from, Carbon $to): int
    {
        $account = $this->account($tenant, $systemKey);

        if (! $account) {
            return 0;
        }

        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $account->id)
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(jl.base_debit),0) AS d, COALESCE(SUM(jl.base_credit),0) AS c')
            ->first();

        return $account->isDebitNatured()
            ? (int) $row->d - (int) $row->c
            : (int) $row->c - (int) $row->d;
    }

    private function accountBalance(Tenant $tenant, string $systemKey): int
    {
        return $this->account($tenant, $systemKey)?->balance() ?? 0;
    }

    private function account(Tenant $tenant, string $systemKey): ?Account
    {
        return Account::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('system_key', $systemKey)
            ->first();
    }
}
