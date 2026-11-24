<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();
            $table->foreignId('buyer_tenant_id')->constrained('tenants');
            $table->foreignId('supplier_tenant_id')->constrained('tenants');
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->char('currency', 3)->default('SYP');
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->unsignedBigInteger('base_total')->default(0); // بعملة دفتر المورد
            $table->decimal('commission_rate', 6, 3)->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->string('incoterm', 10)->nullable();
            $table->json('shipping_address')->nullable();
            $table->enum('status', [
                'draft', 'awaiting_approval', 'confirmed', 'in_production',
                'shipped', 'delivered', 'closed', 'cancelled',
            ])->default('draft');
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'paid', 'refunded'])->default('unpaid');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_tenant_id', 'status', 'created_at']);
            $table->index(['buyer_tenant_id', 'status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->string('unit', 20)->default('piece');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('unit_cost')->default(0);  // لاحتساب تكلفة البضاعة المباعة
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->foreignId('tax_code_id')->nullable();
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
