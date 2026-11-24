<?php

namespace App\Services\Rfq;

use App\Models\Catalog\SupplierCategory;
use App\Models\Rfq\Rfq;
use App\Models\Rfq\RfqInvitation;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Notifications\RfqBroadcastNotification;
use Illuminate\Support\Facades\DB;

/**
 * بثّ طلب عرض السعر إلى المستوردين المسجّلين في فئة الطلب.
 *
 * هذا هو المسار (ب) في السيناريو: تاجر الجملة ينشر طلباً لمنتج معيّن،
 * فيصل إشعار لكل مستورد مسجّل في تلك الفئة، فيرسلون عروض أسعارهم.
 */
class RfqBroadcaster
{
    /**
     * @param  array<int>  $targetedSupplierIds  موردون اختارهم المشتري بالاسم
     * @return int عدد المستوردين الذين وصلهم الطلب
     */
    public function dispatch(Rfq $rfq, array $targetedSupplierIds = []): int
    {
        $supplierIds = $rfq->visibility === 'targeted' && $targetedSupplierIds !== []
            ? $this->filterEligible($targetedSupplierIds)
            : $this->findByCategory($rfq);

        if ($supplierIds === []) {
            return 0;
        }

        $source = $rfq->visibility === 'targeted' ? 'targeted' : 'broadcast';
        $now = now();

        DB::transaction(function () use ($rfq, $supplierIds, $source, $now) {
            $rows = array_map(fn ($id) => [
                'rfq_id' => $rfq->id,
                'supplier_tenant_id' => $id,
                'source' => $source,
                'notified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $supplierIds);

            // insertOrIgnore يمنع الازدواج عند إعادة البثّ
            DB::table('rfq_invitations')->insertOrIgnore($rows);

            $rfq->forceFill([
                'status' => $rfq->status === 'draft' ? 'open' : $rfq->status,
                'notified_count' => RfqInvitation::where('rfq_id', $rfq->id)->count(),
            ])->save();
        });

        $this->notifySuppliers($rfq, $supplierIds);

        return count($supplierIds);
    }

    /**
     * المستوردون المؤهّلون لاستقبال الطلب:
     * مسجّلون في الفئة (أو إحدى فروعها)، مفعّلون، موثّقون، ولم يتجاوزوا السقف اليومي.
     */
    private function findByCategory(Rfq $rfq): array
    {
        $categoryIds = $rfq->category->descendantIds();

        $candidates = SupplierCategory::query()
            ->whereIn('category_id', $categoryIds)
            ->where('receives_rfq', true)
            ->join('tenants', 'tenants.id', '=', 'supplier_categories.tenant_id')
            ->where('tenants.type', Tenant::TYPE_SUPPLIER)
            ->where('tenants.status', 'active')
            ->whereNull('tenants.deleted_at')
            ->when($rfq->delivery_country, fn ($q, $c) => $q->where('tenants.country_code', $c))
            ->distinct()
            ->pluck('supplier_categories.tenant_id')
            ->all();

        return $this->applyDailyCap($candidates);
    }

    private function filterEligible(array $supplierIds): array
    {
        return Tenant::query()
            ->whereIn('id', $supplierIds)
            ->where('type', Tenant::TYPE_SUPPLIER)
            ->where('status', 'active')
            ->pluck('id')
            ->all();
    }

    /** سقف يومي لعدد الطلبات لكل مستورد — يمنع إغراق المستوردين النشطين. */
    private function applyDailyCap(array $supplierIds): array
    {
        $cap = config('b2b.rfq.daily_broadcast_cap');

        if ($cap <= 0 || $supplierIds === []) {
            return $supplierIds;
        }

        $saturated = RfqInvitation::query()
            ->whereIn('supplier_tenant_id', $supplierIds)
            ->whereDate('notified_at', today())
            ->groupBy('supplier_tenant_id')
            ->havingRaw('COUNT(*) >= ?', [$cap])
            ->pluck('supplier_tenant_id')
            ->all();

        return array_values(array_diff($supplierIds, $saturated));
    }

    /** إشعار داخل التطبيق لكل مستخدمي المستوردين الذين وصلهم الطلب. */
    private function notifySuppliers(Rfq $rfq, array $supplierIds): void
    {
        $users = User::whereIn('tenant_id', $supplierIds)
            ->where('status', 'active')
            ->get();

        $notification = new RfqBroadcastNotification($rfq);

        foreach ($users as $user) {
            $user->notify($notification);
        }
    }

    /** إحصاءات حيّة للمشتري: وصل إلى N · فتحه M · قدّم عرضاً K */
    public function stats(Rfq $rfq): array
    {
        $row = RfqInvitation::query()
            ->where('rfq_id', $rfq->id)
            ->selectRaw('COUNT(*) AS notified')
            ->selectRaw('COUNT(viewed_at) AS viewed')
            ->selectRaw('COUNT(responded_at) AS responded')
            ->first();

        return [
            'notified' => (int) $row->notified,
            'viewed' => (int) $row->viewed,
            'responded' => (int) $row->responded,
        ];
    }
}
