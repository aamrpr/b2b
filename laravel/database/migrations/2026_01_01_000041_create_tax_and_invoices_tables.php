<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete(); // null = كود عام
            $table->string('code', 20);
            $table->json('name');
            $table->decimal('rate', 6, 3);                       // 15.000
            $table->enum('type', ['inclusive', 'exclusive'])->default('exclusive');
            $table->char('country_code', 2)->nullable();
            $table->foreignId('sales_account_id')->nullable()->constrained('accounts');
            $table->foreignId('purchase_account_id')->nullable()->constrained('accounts');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_no', 30);
            $table->enum('type', ['sales', 'purchase', 'subscription', 'commission'])->default('sales');
            $table->string('contact_type', 100)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->date('issue_date');
            $table->date('due_date');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('balance_due')->default(0);
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->enum('status', ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'cancelled'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('uuid')->nullable();
            $table->text('qr_payload')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('hash', 64)->nullable();
            $table->string('pdf_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'invoice_no']);
            $table->index(['tenant_id', 'status', 'due_date']);
            $table->index(['contact_type', 'contact_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 14, 3)->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained();
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts');
            $table->timestamps();
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained();
            $table->string('note_no', 30);
            $table->date('issue_date');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->text('reason')->nullable();
            $table->enum('status', ['draft', 'issued', 'cancelled'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'note_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('tax_codes');
    }
};
