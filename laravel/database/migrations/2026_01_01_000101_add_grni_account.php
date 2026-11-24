<?php

use App\Models\Accounting\Account;
use App\Models\Tenancy\Tenant;
use Illuminate\Database\Migrations\Migration;

/**
 * حساب "بضاعة مستلمة غير مفوترة".
 *
 * الاستلام والفوترة حدثان منفصلان زمنياً: البضاعة تصل اليوم والفاتورة
 * بعد أسبوع. بدون هذا الحساب إمّا أن يتأخر إدخال المخزون حتى الفاتورة،
 * أو يُقيَّد الالتزام مرتين. هو الجسر بين الحدثين.
 */
return new class extends Migration
{
    public function up(): void
    {
        $suppliers = Tenant::withTrashed()
            ->whereIn('type', [Tenant::TYPE_SUPPLIER, Tenant::TYPE_PLATFORM])
            ->get();

        foreach ($suppliers as $tenant) {
            $parent = Account::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('code', '2000')
                ->first();

            if (! $parent) {
                continue;   // مستأجر بلا دليل حسابات
            }

            Account::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => '2130'],
                [
                    'parent_id' => $parent->id,
                    'name' => [
                        'ar' => 'بضاعة مستلمة غير مفوترة',
                        'en' => 'Goods Received Not Invoiced',
                    ],
                    'type' => 'liability',
                    'normal_balance' => 'credit',
                    'system_key' => 'goods_received_not_invoiced',
                    'is_leaf' => true,
                    'is_system' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    public function down(): void
    {
        Account::withoutGlobalScope('tenant')
            ->where('system_key', 'goods_received_not_invoiced')
            ->delete();
    }
};
