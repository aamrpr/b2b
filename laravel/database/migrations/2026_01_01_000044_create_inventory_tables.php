<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->string('code', 20);
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('quantity_on_hand')->default(0);
            $table->bigInteger('quantity_reserved')->default(0);
            $table->unsignedBigInteger('average_cost')->default(0); // بالوحدة الصغرى لعملة الدفتر
            $table->unsignedBigInteger('reorder_point')->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'variant_id', 'warehouse_id'], 'uq_inv_item');
        });

        /** المصدر الوحيد للحقيقة لرصيد المخزون وتكلفته */
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'purchase_in', 'sale_out', 'return_in', 'return_out',
                'adjustment', 'transfer_in', 'transfer_out', 'opening',
            ]);
            $table->bigInteger('quantity');                 // موجب داخل / سالب خارج
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->bigInteger('total_cost')->default(0);
            $table->bigInteger('balance_after')->default(0);
            $table->unsignedBigInteger('average_cost_after')->default(0);
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['inventory_item_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movement_id')->constrained('inventory_movements')->cascadeOnDelete();
            $table->bigInteger('quantity_in');
            $table->bigInteger('quantity_remaining');
            $table->unsignedBigInteger('unit_cost');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['inventory_item_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_layers');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('warehouses');
    }
};
