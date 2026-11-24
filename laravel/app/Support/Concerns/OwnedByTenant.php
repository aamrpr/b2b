<?php

namespace App\Support\Concerns;

use App\Models\Tenancy\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ملكية مستأجر بلا عزل إجباري.
 * للبيانات المنشورة علناً في السوق (المنتجات، طلبات عروض الأسعار):
 * المشتري يجب أن يرى منتجات كل المستوردين، لذا لا Global Scope هنا.
 * يُستخدم BelongsToTenant بدلاً منه للبيانات الخاصة (المحاسبة، الفواتير).
 */
trait OwnedByTenant
{
    public static function bootOwnedByTenant(): void
    {
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

    /** نطاق "بياناتي" — يُستخدم صراحةً في لوحة المستورد. */
    public function scopeOwned(Builder $query, ?int $tenantId = null): Builder
    {
        return $query->where(
            $query->getModel()->getTable().'.tenant_id',
            $tenantId ?? app(TenantContext::class)->id()
        );
    }
}
