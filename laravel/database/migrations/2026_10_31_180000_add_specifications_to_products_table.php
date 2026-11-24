<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * خصائص المنتج: قائمة مرتّبة من [التسمية، القيمة] كلتاهما مترجمة.
     *
     * جدولٌ مستقلّ كان سيفرض على كل مستورد قاموس خصائص موحّداً، وخصائص
     * قطع السيارات غير خصائص الأقمشة. والقائمة هنا تحفظ ترتيب المستورد
     * كما أدخله — وهو ترتيب ذو معنى: الأهمّ أولاً.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // [{"label":{"ar":"المادة","en":"Material"},"value":{"ar":"فولاذ","en":"Steel"}}]
            $table->json('specifications')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('specifications');
        });
    }
};
