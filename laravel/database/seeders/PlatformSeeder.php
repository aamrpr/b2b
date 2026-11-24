<?php

namespace Database\Seeders;

use App\Models\Accounting\CommissionRule;
use App\Models\Commerce\SubscriptionPlan;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $platform = Tenant::updateOrCreate(
            ['type' => Tenant::TYPE_PLATFORM],
            [
                'name' => 'منصة المستوردين والمصدّرين',
                'slug' => 'platform',
                'base_currency' => config('b2b.platform_currency'),
                'default_locale' => 'ar',
                'country_code' => 'SY',
                'status' => 'active',
                'accounting_enabled' => true,
            ]
        );

        app(TenantProvisioner::class)->provision($platform);

        User::updateOrCreate(
            ['email' => 'admin@b2b.local'],
            [
                'tenant_id' => $platform->id,
                'name' => 'مدير المنصة',
                'password' => 'password',
                'locale' => 'ar',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        /* خطط الاشتراك — النموذج الأنسب لسوق بلا بوابات دفع */
        $plans = [
            ['free', 'مجاني', 'Free', 0, 0, 10, 1, false],
            ['basic', 'أساسي', 'Basic', 2500000, 25000000, 100, 3, false],
            ['pro', 'احترافي', 'Professional', 6500000, 65000000, 1000, 10, true],
            ['enterprise', 'مؤسسي', 'Enterprise', 15000000, 150000000, null, 50, true],
        ];

        foreach ($plans as $i => [$slug, $ar, $en, $monthly, $yearly, $products, $users, $accounting]) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => ['ar' => $ar, 'en' => $en],
                    'price_monthly' => $monthly,
                    'price_yearly' => $yearly,
                    'currency' => 'SYP',
                    'product_limit' => $products,
                    'user_limit' => $users,
                    'accounting_enabled' => $accounting,
                    'is_active' => true,
                    'sort_order' => $i,
                ]
            );
        }

        CommissionRule::updateOrCreate(
            ['scope' => 'global', 'scope_id' => null],
            [
                'type' => 'percentage',
                'value' => config('b2b.accounting.default_commission_rate'),
                'accrual_trigger' => config('b2b.accounting.commission_trigger'),
                'priority' => 0,
                'is_active' => true,
            ]
        );
    }
}
