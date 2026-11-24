<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained();
            $table->json('name');                     // {"ar":"...","en":"..."}
            $table->json('description')->nullable();
            $table->string('slug')->unique();
            $table->string('sku')->nullable();
            $table->string('brand')->nullable();
            $table->string('hs_code', 20)->nullable();
            $table->char('country_of_origin', 2)->nullable();
            $table->string('unit_of_measure', 20)->default('piece');
            $table->unsignedInteger('min_order_quantity')->default(1);
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->boolean('price_visible')->default(true);   // false = "اطلب عرض سعر"
            $table->unsignedBigInteger('base_price')->default(0); // بالوحدة الصغرى
            $table->char('currency', 3)->default('SYP');
            $table->unsignedBigInteger('stock_quantity')->default(0);
            $table->enum('status', ['draft', 'active', 'out_of_stock', 'archived'])->default('draft');
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('rfq_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['category_id', 'status']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->json('attributes');               // {"color":{"ar":"أحمر","en":"Red"},"size":"XL"}
            $table->unsignedBigInteger('price');
            $table->char('currency', 3)->default('SYP');
            $table->unsignedBigInteger('stock_quantity')->default(0);
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /** التسعير التدرّجي حسب الكمية — جوهر تجارة الجملة */
        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('min_qty');
            $table->unsignedInteger('max_qty')->nullable();
            $table->unsignedBigInteger('unit_price');
            $table->char('currency', 3)->default('SYP');
            $table->timestamps();

            $table->index(['product_id', 'min_qty']);
        });

        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->enum('type', ['image', 'video', 'document'])->default('image');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_price_tiers');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
