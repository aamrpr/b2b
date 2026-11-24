<?php

namespace App\Services\Accounting\Posting;

use App\Models\Accounting\Commission;
use App\Models\Accounting\CommissionRule;
use App\Models\Orders\Order;
use App\Models\Tenancy\Tenant;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * العمولة: حدث واحد يولّد قيدين في دفترين مختلفين.
 * مرجع القيود: docs/02-accounting-system.md §3.2 (أ-3) و(ج-13)
 */
class CommissionPostingService
{
    public function __construct(private readonly JournalPoster $poster) {}

    /** اختيار القاعدة الأخص: مورد ← خطة ← فئة ← عام */
    public function resolveRule(Order $order): ?CommissionRule
    {
        return CommissionRule::query()
            ->where('is_active', true)
            ->where(function ($q) use ($order) {
                $q->where(fn ($s) => $s->where('scope', 'supplier')->where('scope_id', $order->supplier_tenant_id))
                    ->orWhere(fn ($s) => $s->where('scope', 'category')
                        ->whereIn('scope_id', $order->items->pluck('product.category_id')->filter()->unique()))
                    ->orWhere('scope', 'global');
            })
            ->orderByRaw("FIELD(scope, 'supplier', 'plan', 'category', 'global')")
            ->orderByDesc('priority')
            ->first();
    }

    public function accrue(Order $order): ?Commission
    {
        if ($order->commission()->exists()) {
            return $order->commission;
        }

        $rule = $this->resolveRule($order);
        $base = $order->netMoney();

        $amount = $rule
            ? $rule->calculate($base)
            : $base->percentage(config('b2b.accounting.default_commission_rate'));

        if ($amount->isZero()) {
            return null;
        }

        return DB::transaction(function () use ($order, $base, $amount, $rule) {
            $platform = Tenant::platform();
            $supplier = $order->supplier;

            // قيد المنصة: من ح/ ذمم الموردين إلى ح/ إيراد العمولات
            $platformEntry = $this->poster->post(
                JournalDraft::for($platform, $amount->currency)
                    ->on($order->delivered_at ?? now())
                    ->describe("عمولة على الطلب {$order->order_no}")
                    ->from($order)
                    ->idempotent("commission_accrued:platform:order:{$order->id}")
                    ->debit('commission_receivable', $amount, "عمولة {$order->order_no}", $supplier)
                    ->credit('commission_revenue', $amount)
            );

            // قيد المورد: من ح/ عمولة المنصة إلى ح/ مستحق للمنصة
            $supplierEntry = $this->poster->post(
                JournalDraft::for($supplier, $amount->currency)
                    ->on($order->delivered_at ?? now())
                    ->describe("عمولة المنصة على الطلب {$order->order_no}")
                    ->from($order)
                    ->idempotent("commission_accrued:supplier:order:{$order->id}")
                    ->debit('platform_commission', $amount)
                    ->credit('due_to_platform', $amount, null, $platform)
            );

            $commission = Commission::create([
                'order_id' => $order->id,
                'supplier_tenant_id' => $supplier->id,
                'base_amount' => $base->amount,
                'rate' => $rule?->effectiveRate($base) ?? config('b2b.accounting.default_commission_rate'),
                'amount' => $amount->amount,
                'currency' => $amount->currency,
                'status' => 'accrued',
                'accrued_at' => now(),
                'platform_journal_entry_id' => $platformEntry->id,
                'supplier_journal_entry_id' => $supplierEntry->id,
            ]);

            $order->forceFill([
                'commission_rate' => $commission->rate,
                'commission_amount' => $amount->amount,
            ])->save();

            return $commission;
        });
    }

    /**
     * عكس جزء من العمولة عند المرتجع.
     *
     * العمولة تُحتسب على صافي البيع، فالمرتجع يُسقط حصّته منها —
     * وإلا بقيت المنصة تحتفظ بعمولة على بضاعة عادت إلى المستورد.
     * القيدان عكسيان تماماً لقيدَي الاستحقاق ويُرحَّلان في الدفترين.
     */
    public function reversePortion(Order $order, Money $refundedNet, ?string $reference = null): ?Money
    {
        $commission = $order->commission;

        if (! $commission || $commission->status === 'reversed' || $refundedNet->isZero()) {
            return null;
        }

        $base = $commission->base_amount;

        if ($base <= 0) {
            return null;
        }

        // النسبة تُحسب من العمولة الأصلية لا من رصيدها بعد عكس سابق،
        // وإلا نقص كل عكس تالٍ عن حصّته. والسقف هو ما تبقّى غير معكوس.
        $portion = Money::of(
            min(
                (int) round($commission->amount * $refundedNet->amount / $base),
                $commission->netAmount()
            ),
            $commission->currency
        );

        if ($portion->isZero()) {
            return null;
        }

        $platform = Tenant::platform();
        $supplier = $order->supplier;
        $label = $reference ?? "مرتجع على الطلب {$order->order_no}";
        $key = $reference ?? "order:{$order->id}:{$refundedNet->amount}";

        DB::transaction(function () use ($platform, $supplier, $portion, $label, $key, $commission, $order) {
            // المنصة: عكس إيراد العمولة وإسقاط الذمة
            $this->poster->post(
                JournalDraft::for($platform, $portion->currency)
                    ->describe("عكس عمولة — {$label}")
                    ->from($order)
                    ->idempotent("commission_reversed:platform:{$key}")
                    ->debit('commission_revenue', $portion)
                    ->credit('commission_receivable', $portion, null, $supplier)
            );

            // المستورد: عكس المصروف وإسقاط المستحق
            $this->poster->post(
                JournalDraft::for($supplier, $portion->currency)
                    ->describe("عكس عمولة المنصة — {$label}")
                    ->from($order)
                    ->idempotent("commission_reversed:supplier:{$key}")
                    ->debit('due_to_platform', $portion, null, $platform)
                    ->credit('platform_commission', $portion)
            );

            $reversed = $commission->reversed_amount + $portion->amount;

            $commission->update([
                'reversed_amount' => $reversed,
                'status' => $reversed >= $commission->amount ? 'reversed' : $commission->status,
            ]);

            $order->forceFill([
                'commission_amount' => max(0, $order->commission_amount - $portion->amount),
            ])->save();
        });

        return $portion;
    }

    /**
     * تسوية العمولة. في وضع المحفظة تُخصم من رصيد المورد،
     * وفي وضع التحصيل اليدوي يسدّدها المورد نقداً.
     */
    public function settle(Commission $commission): void
    {
        if ($commission->status === 'settled') {
            return;
        }

        $amount = $commission->amountMoney();
        $platform = Tenant::platform();
        $supplier = $commission->supplier;
        $manual = config('b2b.accounting.collection_mode') === 'manual';

        DB::transaction(function () use ($commission, $amount, $platform, $supplier, $manual) {
            // المنصة: من ح/ النقدية أو مستحقات الموردين  إلى ح/ ذمم الموردين
            $this->poster->post(
                JournalDraft::for($platform, $amount->currency)
                    ->describe("تحصيل عمولة #{$commission->id}")
                    ->from($commission)
                    ->idempotent("commission_settled:platform:commission:{$commission->id}")
                    ->debit($manual ? 'cash' : 'payable_to_suppliers', $amount)
                    ->credit('commission_receivable', $amount, null, $supplier)
            );

            // المورد: من ح/ مستحق للمنصة  إلى ح/ النقدية أو المحفظة
            $this->poster->post(
                JournalDraft::for($supplier, $amount->currency)
                    ->describe("سداد عمولة #{$commission->id}")
                    ->from($commission)
                    ->idempotent("commission_settled:supplier:commission:{$commission->id}")
                    ->debit('due_to_platform', $amount, null, $platform)
                    ->credit($manual ? 'cash' : 'platform_wallet', $amount)
            );

            $commission->update(['status' => 'settled', 'settled_at' => now()]);
        });
    }
}
