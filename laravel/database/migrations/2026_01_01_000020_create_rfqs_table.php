<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلب عرض السعر: تاجر الجملة ينشره، فيصل إلى المستوردين المسجّلين في الفئة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('reference_no')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained();
            $table->unsignedBigInteger('target_price')->nullable();
            $table->char('currency', 3)->default('SYP');
            $table->string('delivery_city')->nullable();
            $table->char('delivery_country', 2)->default('SY');
            $table->date('delivery_deadline')->nullable();
            $table->timestamp('expires_at');
            $table->enum('visibility', ['public', 'targeted'])->default('public');
            $table->boolean('hide_buyer_identity')->default(false);
            $table->enum('status', ['draft', 'open', 'quoted', 'awarded', 'closed', 'expired'])->default('draft');
            $table->unsignedSmallInteger('notified_count')->default(0);
            $table->unsignedSmallInteger('viewed_count')->default(0);
            $table->unsignedSmallInteger('quotations_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'status', 'expires_at']);
            $table->index(['buyer_tenant_id', 'status']);
        });

        Schema::create('rfq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->string('unit', 20)->default('piece');
            $table->json('specifications')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });

        /** سطر لكل مستورد وصله الطلب — موجّه أو بثّ آلي بالفئة */
        Schema::create('rfq_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('source', ['targeted', 'broadcast'])->default('broadcast');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'supplier_tenant_id']);
            $table->index(['supplier_tenant_id', 'responded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_invitations');
        Schema::dropIfExists('rfq_items');
        Schema::dropIfExists('rfqs');
    }
};
