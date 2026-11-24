<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** مورّدو المستورد: جهات خارجية لا مستأجرون في المنصة */
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30);
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('tax_number')->nullable();
            $table->char('default_currency', 3)->default('SYP');
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('warehouse_id')->nullable()->constrained();
            $table->string('reference_no', 30);
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->string('incoterm', 10)->nullable();
            $table->enum('status', [
                'draft', 'sent', 'partially_received', 'received', 'closed', 'cancelled',
            ])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'reference_no']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedInteger('invoiced_quantity')->default(0);
            $table->string('unit', 20)->default('piece');
            $table->unsignedBigInteger('unit_cost');
            $table->foreignId('tax_code_id')->nullable()->constrained();
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->timestamps();
        });

        /** استلام بضاعة: يدخل المخزون ويُنشئ التزام "مستلمة غير مفوترة" */
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('reference_no', 30);
            $table->date('received_date');
            $table->string('vendor_note_no')->nullable();
            $table->unsignedBigInteger('total_cost')->default(0);
            $table->char('currency', 3);
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('received_by')->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'reference_no']);
            $table->index(['purchase_order_id']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('total_cost');
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->string('condition_note')->nullable();
            $table->timestamps();
        });

        // ربط فاتورة المورّد بأمر الشراء
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('order_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->after('purchase_order_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('vendor_id');
            $table->dropConstrainedForeignKey('purchase_order_id');
        });

        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('vendors');
    }
};
