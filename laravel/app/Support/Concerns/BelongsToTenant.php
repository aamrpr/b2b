<?php

namespace App\Support\Concerns;

use App\Models\Tenancy\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عزل إلزامي على مستوى المستأجر.
 * كل استعلام يُقيَّد تلقائياً بالمستأجر الحالي ما لم يُطلب تجاوزه صراحة.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $tenantId);
            }
        });

        static::creating(function ($model) {
            if ($model->tenant_id === null) {
                $model->tenant_id = app(TenantContext::class)->id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** تجاوز العزل — للمهام الإدارية والتقارير على مستوى المنصة فقط. */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope('tenant');
    }
}
