<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * إنشاء دليل حسابات معياري لمستأجر جديد من قالب.
 * الحسابات النظامية (is_system) محمية من الحذف ومرتبطة بمحرك القيود عبر system_key.
 */
class ChartOfAccountsFactory
{
    /** دليل حسابات المستورد (المورد) */
    public const SUPPLIER_TEMPLATE = [
        ['1000', 'asset', 'الأصول', 'Assets', null, false],
        ['1110', 'asset', 'الصندوق', 'Cash on Hand', 'cash', true],
        ['1120', 'asset', 'البنك', 'Bank', 'bank', true],
        ['1140', 'asset', 'رصيد المحفظة لدى المنصة', 'Platform Wallet', 'platform_wallet', true],
        ['1210', 'asset', 'العملاء (ذمم مدينة)', 'Accounts Receivable', 'accounts_receivable', true],
        ['1230', 'asset', 'ضريبة المشتريات القابلة للخصم', 'Input VAT', 'input_vat', true],
        ['1250', 'asset', 'مخصص الديون المشكوك فيها', 'Allowance for Doubtful Debts', 'doubtful_allowance', true],
        ['1310', 'asset', 'مخزون البضاعة', 'Inventory', 'inventory', true],
        ['1320', 'asset', 'بضاعة في الطريق', 'Goods in Transit', 'goods_in_transit', true],
        ['1410', 'asset', 'دفعات مقدمة لموردين', 'Prepayments to Vendors', 'vendor_prepayments', true],
        ['2000', 'liability', 'الالتزامات', 'Liabilities', null, false],
        ['2110', 'liability', 'مستحق للمنصة', 'Due to Platform', 'due_to_platform', true],
        ['2115', 'liability', 'الموردون (ذمم دائنة)', 'Accounts Payable', 'accounts_payable', true],
        ['2120', 'liability', 'دفعات مقدمة من عملاء', 'Customer Advances', 'customer_advances', true],
        ['2130', 'liability', 'بضاعة مستلمة غير مفوترة', 'Goods Received Not Invoiced', 'goods_received_not_invoiced', true],
        ['2210', 'liability', 'ضريبة المبيعات المستحقة', 'Output VAT', 'output_vat', true],
        ['2300', 'liability', 'مصروفات مستحقة', 'Accrued Expenses', 'accrued_expenses', true],
        ['3000', 'equity', 'حقوق الملكية', 'Equity', null, false],
        ['3100', 'equity', 'رأس المال', 'Capital', 'capital', true],
        ['3200', 'equity', 'الأرباح المحتجزة', 'Retained Earnings', 'retained_earnings', true],
        ['3300', 'equity', 'المسحوبات', 'Drawings', 'drawings', true],
        ['3900', 'equity', 'ملخص الدخل', 'Income Summary', 'income_summary', true],
        ['4000', 'revenue', 'الإيرادات', 'Revenue', null, false],
        ['4100', 'revenue', 'إيراد المبيعات', 'Sales Revenue', 'sales_revenue', true],
        ['4150', 'revenue', 'مردودات المبيعات', 'Sales Returns', 'sales_returns', true],
        ['4200', 'revenue', 'إيراد الشحن المحصّل', 'Shipping Revenue', 'shipping_revenue', true],
        ['4900', 'revenue', 'أرباح فروق العملة', 'FX Gain', 'fx_gain', true],
        ['5000', 'expense', 'المصروفات', 'Expenses', null, false],
        ['5100', 'expense', 'تكلفة البضاعة المباعة', 'Cost of Goods Sold', 'cogs', true],
        ['5150', 'expense', 'عمولة المنصة', 'Platform Commission', 'platform_commission', true],
        ['5200', 'expense', 'مصاريف شحن وتغليف', 'Shipping Expense', 'shipping_expense', true],
        ['5300', 'expense', 'رسوم اشتراك المنصة', 'Platform Subscription', 'subscription_expense', true],
        ['5400', 'expense', 'مصاريف تشغيلية', 'Operating Expenses', 'operating_expense', true],
        ['5500', 'expense', 'خصم مسموح به', 'Discounts Allowed', 'discount_allowed', true],
        ['5600', 'expense', 'ديون معدومة', 'Bad Debts', 'bad_debts', true],
        ['5900', 'expense', 'خسائر فروق العملة', 'FX Loss', 'fx_loss', true],
    ];

    /** دليل حسابات المنصة */
    public const PLATFORM_TEMPLATE = [
        ['1000', 'asset', 'الأصول', 'Assets', null, false],
        ['1110', 'asset', 'الصندوق', 'Cash on Hand', 'cash', true],
        ['1120', 'asset', 'البنك', 'Bank', 'bank', true],
        ['1130', 'asset', 'أرصدة بوابات الدفع', 'Payment Gateway Balance', 'gateway_balance', true],
        ['1210', 'asset', 'ذمم الموردين (عمولات مستحقة)', 'Commission Receivable', 'commission_receivable', true],
        ['1220', 'asset', 'ذمم الاشتراكات', 'Subscription Receivable', 'subscription_receivable', true],
        ['2000', 'liability', 'الالتزامات', 'Liabilities', null, false],
        ['2110', 'liability', 'مستحقات الموردين', 'Payable to Suppliers', 'payable_to_suppliers', true],
        ['2120', 'liability', 'أموال محتجزة لصالح الأطراف', 'Escrow Liability', 'escrow', true],
        ['2210', 'liability', 'ضريبة مستحقة', 'Output VAT', 'output_vat', true],
        ['2310', 'liability', 'إيرادات مقبوضة مقدماً', 'Deferred Revenue', 'deferred_revenue', true],
        ['3000', 'equity', 'حقوق الملكية', 'Equity', null, false],
        ['3100', 'equity', 'رأس المال', 'Capital', 'capital', true],
        ['3200', 'equity', 'الأرباح المحتجزة', 'Retained Earnings', 'retained_earnings', true],
        ['3900', 'equity', 'ملخص الدخل', 'Income Summary', 'income_summary', true],
        ['4000', 'revenue', 'الإيرادات', 'Revenue', null, false],
        ['4100', 'revenue', 'إيراد العمولات', 'Commission Revenue', 'commission_revenue', true],
        ['4200', 'revenue', 'إيراد الاشتراكات', 'Subscription Revenue', 'subscription_revenue', true],
        ['4300', 'revenue', 'إيراد الخدمات المميزة', 'Premium Services Revenue', 'premium_revenue', true],
        ['4900', 'revenue', 'أرباح فروق العملة', 'FX Gain', 'fx_gain', true],
        ['5000', 'expense', 'المصروفات', 'Expenses', null, false],
        ['5200', 'expense', 'رسوم بوابات الدفع', 'Gateway Fees', 'gateway_fees', true],
        ['5300', 'expense', 'رواتب وأجور', 'Salaries', 'salaries', true],
        ['5400', 'expense', 'استضافة وبنية تحتية', 'Infrastructure', 'infrastructure', true],
        ['5500', 'expense', 'تسويق وإعلان', 'Marketing', 'marketing', true],
        ['5600', 'expense', 'ديون معدومة', 'Bad Debts', 'bad_debts', true],
        ['5900', 'expense', 'خسائر فروق العملة', 'FX Loss', 'fx_loss', true],
    ];

    /**
     * حسابات مقابِلة: طبيعتها معاكسة لنوعها.
     * مردودات المبيعات (إيراد لكن مدين)، مخصص الديون (أصل لكن دائن)، المسحوبات (ملكية لكن مدين).
     */
    private const CONTRA_ACCOUNTS = ['sales_returns', 'doubtful_allowance', 'drawings'];

    private function normalBalanceFor(string $type, ?string $systemKey): string
    {
        $natural = in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';

        if ($systemKey !== null && in_array($systemKey, self::CONTRA_ACCOUNTS, true)) {
            return $natural === 'debit' ? 'credit' : 'debit';
        }

        return $natural;
    }

    public function createFor(Tenant $tenant): void
    {
        $template = $tenant->isPlatform() ? self::PLATFORM_TEMPLATE : self::SUPPLIER_TEMPLATE;

        DB::transaction(function () use ($tenant, $template) {
            $parents = [];

            foreach ($template as [$code, $type, $nameAr, $nameEn, $systemKey, $isLeaf]) {
                $parentCode = substr($code, 0, 1).'000';
                $parentId = ($code === $parentCode) ? null : ($parents[$parentCode] ?? null);

                $account = Account::withoutGlobalScope('tenant')->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $code],
                    [
                        'parent_id' => $parentId,
                        'name' => ['ar' => $nameAr, 'en' => $nameEn],
                        'type' => $type,
                        'normal_balance' => $this->normalBalanceFor($type, $systemKey),
                        'system_key' => $systemKey,
                        'is_leaf' => $isLeaf,
                        'is_system' => true,
                        'is_active' => true,
                    ]
                );

                if (! $isLeaf) {
                    $parents[$code] = $account->id;
                }
            }
        });
    }
}
