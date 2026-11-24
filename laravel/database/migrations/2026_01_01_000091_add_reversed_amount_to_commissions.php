<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العمولة المعكوسة تُتتبَّع في عمود مستقل.
 *
 * بخصمها من `amount` مباشرةً يصبح المرتجع الثاني يحسب نسبته من رصيد
 * منقوص لا من العمولة الأصلية، فيُعكس أقلّ مما يجب ويتراكم الخطأ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->unsignedBigInteger('reversed_amount')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn('reversed_amount');
        });
    }
};
