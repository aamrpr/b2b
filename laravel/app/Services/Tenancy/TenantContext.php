<?php

namespace App\Services\Tenancy;

use App\Models\Tenancy\Tenant;

/**
 * المستأجر الحالي للطلب. يُضبط من المستخدم المصادَق عليه،
 * ويمكن تجاوزه مؤقتاً عند الحاجة لعمل قيد في دفتر المنصة.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $disabled = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->disabled ? null : $this->tenant;
    }

    public function id(): ?int
    {
        return $this->disabled ? null : $this->tenant?->id;
    }

    public function currency(): string
    {
        return $this->tenant?->base_currency ?? config('b2b.platform_currency');
    }

    public function isPlatform(): bool
    {
        return $this->tenant?->type === 'platform';
    }

    /** تنفيذ مغلق ضمن سياق مستأجر آخر ثم استعادة السياق الأصلي. */
    public function runAs(?Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $previousDisabled = $this->disabled;

        $this->tenant = $tenant;
        $this->disabled = false;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
            $this->disabled = $previousDisabled;
        }
    }

    /** تنفيذ بلا عزل — للتقارير الإدارية على مستوى المنصة. */
    public function runWithoutScope(callable $callback): mixed
    {
        $previous = $this->disabled;
        $this->disabled = true;

        try {
            return $callback();
        } finally {
            $this->disabled = $previous;
        }
    }
}
