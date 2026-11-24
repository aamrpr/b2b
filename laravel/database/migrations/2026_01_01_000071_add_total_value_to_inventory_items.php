<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قيمة المخزون تُخزَّن كمجموع تكلفة فعلي، والمتوسط يُشتق منها للعرض.
 *
 * بدون ذلك: المتوسط عدد صحيح مقرَّب، و(الكمية × المتوسط) لا تساوي
 * مجموع التكاليف الحقيقي، فينحرف تقييم المخزون عن رصيد حسابه في الأستاذ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->bigInteger('total_value')->default(0)->after('average_cost');
        });

        // الأرصدة القائمة: القيمة الحالية هي أفضل تقدير متاح
        DB::statement('UPDATE inventory_items SET total_value = quantity_on_hand * average_cost');
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('total_value');
        });
    }
};
