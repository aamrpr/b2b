<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('buyer_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('quotation_no')->unique();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('parent_quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->char('currency', 3)->default('SYP');
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->string('incoterm', 10)->nullable();   // EXW/FOB/CIF/DDP
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('valid_until');
            $table->enum('status', ['draft', 'sent', 'countered', 'accepted', 'rejected', 'expired'])->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['rfq_id', 'status']);
            $table->index(['supplier_tenant_id', 'status']);
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->string('unit', 20)->default('piece');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->foreignId('tax_code_id')->nullable();
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
