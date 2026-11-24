<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الجرد الفعلي: يُفتح، تُسجَّل الكميات المعدودة، ثم يُعتمد
 * فتُرحَّل الفروق كحركات تسوية بقيودها المحاسبية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('reference_no', 30);
            $table->date('count_date');
            $table->enum('status', ['draft', 'counting', 'completed', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference_no']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained();
            $table->bigInteger('expected_quantity');     // رصيد الدفاتر لحظة الفتح
            $table->bigInteger('counted_quantity')->nullable();
            $table->bigInteger('difference')->default(0);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->bigInteger('value_difference')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'inventory_item_id'], 'uq_count_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
