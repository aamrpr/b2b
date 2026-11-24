<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأصناف: قطع سيارات، منزليات، أثاث، هدايا وألعاب...
 * شجرة بعمق غير محدود. الاسم مخزَّن JSON لدعم ar/en.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->json('name');                 // {"ar":"قطع سيارات","en":"Auto Parts"}
            $table->json('description')->nullable();
            $table->string('slug')->unique();
            $table->string('icon', 50)->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('suppliers_count')->default(0);
            $table->unsignedInteger('products_count')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'is_active']);
        });

        /**
         * تخصّص المستورد بالأصناف — أساس دليل المستوردين وبثّ طلبات عروض الأسعار.
         */
        Schema::create('supplier_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedTinyInteger('years_experience')->nullable();
            $table->json('brands')->nullable();     // الماركات/الوكالات
            $table->boolean('receives_rfq')->default(true); // يستقبل إشعارات الطلبات في هذه الفئة
            $table->timestamps();

            $table->unique(['tenant_id', 'category_id']);
            $table->index(['category_id', 'receives_rfq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_categories');
        Schema::dropIfExists('categories');
    }
};
