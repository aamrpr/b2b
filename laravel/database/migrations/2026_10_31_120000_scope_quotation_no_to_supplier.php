<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رقم عرض السعر كان فريداً على مستوى المنصة كلها، بينما المُرقِّم
 * يعدّ لكل مستورد على حدة — فأول مستورد يأخذ QT-2026-00001، ثم
 * يسقط كل مستورد تالٍ على القيد نفسه ولا يستطيع إرسال عرضه أبداً.
 *
 * عرض السعر مستند المستورد الخاص كالفاتورة، وكل الجداول النظيرة
 * (invoices، payments، shipments، credit_notes) تحمل قيداً مركّباً
 * مع tenant_id. هذا الجدول وحده شذّ عنها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique('quotations_quotation_no_unique');
            $table->unique(['supplier_tenant_id', 'quotation_no']);
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique('quotations_supplier_tenant_id_quotation_no_unique');
            $table->unique('quotation_no');
        });
    }
};
