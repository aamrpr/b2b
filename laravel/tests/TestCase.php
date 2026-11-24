<?php

namespace Tests;

use App\Models\Tenancy\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function platform(): Tenant
    {
        $platform = Tenant::firstOrCreate(
            ['type' => Tenant::TYPE_PLATFORM],
            [
                'name' => 'Platform',
                'slug' => 'platform',
                'base_currency' => 'SYP',
                'country_code' => 'SY',
                'status' => 'active',
                'accounting_enabled' => true,
            ]
        );

        if ($platform->accounts()->withoutGlobalScope('tenant')->doesntExist()) {
            app(TenantProvisioner::class)->provision($platform);
        }

        return $platform->refresh();
    }

    protected function makeSupplier(string $name = 'Importer'): Tenant
    {
        $tenant = app(TenantProvisioner::class)->createSupplier([
            'name' => $name.' '.Str::random(4),
            'base_currency' => 'SYP',
        ]);

        $tenant->update(['status' => 'active']);

        return $tenant->refresh();
    }

    protected function actingAsTenant(Tenant $tenant): void
    {
        app(TenantContext::class)->set($tenant);
    }
}
