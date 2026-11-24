<?php

namespace App\Services\Tenancy;

use App\Models\Commerce\Wallet;
use App\Models\Tenancy\Tenant;
use App\Services\Accounting\ChartOfAccountsFactory;
use App\Services\Accounting\PeriodResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تجهيز مستأجر جديد: دليل حسابات، سنة مالية وفتراتها، محفظة.
 * يُستدعى مرة واحدة عند اعتماد التسجيل.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly ChartOfAccountsFactory $coa,
        private readonly PeriodResolver $periods,
    ) {}

    public function provision(Tenant $tenant): Tenant
    {
        // القيم الافتراضية من قاعدة البيانات لا تُحمَّل على الموديل بعد create()
        $tenant->refresh();

        return DB::transaction(function () use ($tenant) {
            if ($tenant->accounting_enabled) {
                $this->coa->createFor($tenant);
                $this->periods->createYearFor($tenant, Carbon::today());
            }

            $walletAccountKey = $tenant->isPlatform() ? 'gateway_balance' : 'platform_wallet';

            $account = $tenant->accounts()
                ->withoutGlobalScope('tenant')
                ->where('system_key', $walletAccountKey)
                ->first();

            Wallet::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenant->id, 'currency' => $tenant->base_currency],
                ['balance' => 0, 'pending_balance' => 0, 'account_id' => $account?->id]
            );

            return $tenant->refresh();
        });
    }

    public function createSupplier(array $data): Tenant
    {
        $tenant = Tenant::create([
            'type' => Tenant::TYPE_SUPPLIER,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
            'base_currency' => $data['base_currency'] ?? config('b2b.platform_currency'),
            'default_locale' => $data['locale'] ?? config('app.locale'),
            'country_code' => $data['country_code'] ?? 'SY',
            'city' => $data['city'] ?? null,
            'status' => 'pending',
            'accounting_enabled' => true,
        ]);

        return $this->provision($tenant);
    }

    public function createBuyer(array $data): Tenant
    {
        $tenant = Tenant::create([
            'type' => Tenant::TYPE_BUYER,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
            'base_currency' => $data['base_currency'] ?? config('b2b.platform_currency'),
            'default_locale' => $data['locale'] ?? config('app.locale'),
            'country_code' => $data['country_code'] ?? 'SY',
            'city' => $data['city'] ?? null,
            'status' => 'active',
            'accounting_enabled' => false,
        ]);

        return $this->provision($tenant);
    }
}
