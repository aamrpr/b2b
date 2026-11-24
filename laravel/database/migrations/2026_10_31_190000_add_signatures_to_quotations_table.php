<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * توقيع الطرفين على عرض السعر.
     *
     * العرض وثيقة تجارية يُبنى عليها أمر شراء، والتوقيع هو ما يحوّل
     * «رأيتُه» إلى «ارتضيتُه». ويُحفظ مع اسم الموقّع ووقته لا الصورة
     * وحدها: الصورة خطّ يد، والاسم والوقت هما ما يُحتجّ به عند الخلاف.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('supplier_signature_path')->nullable()->after('decided_at');
            $table->string('supplier_signed_by')->nullable()->after('supplier_signature_path');
            $table->timestamp('supplier_signed_at')->nullable()->after('supplier_signed_by');

            $table->string('buyer_signature_path')->nullable()->after('supplier_signed_at');
            $table->string('buyer_signed_by')->nullable()->after('buyer_signature_path');
            $table->timestamp('buyer_signed_at')->nullable()->after('buyer_signed_by');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_signature_path',
                'supplier_signed_by',
                'supplier_signed_at',
                'buyer_signature_path',
                'buyer_signed_by',
                'buyer_signed_at',
            ]);
        });
    }
};
