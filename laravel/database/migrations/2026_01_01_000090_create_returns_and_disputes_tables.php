<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** طلب مرتجع يفتحه تاجر الجملة على طلب مُسلَّم */
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_tenant_id')->constrained('tenants');
            $table->foreignId('supplier_tenant_id')->constrained('tenants');
            $table->string('reference_no', 30)->unique();
            $table->enum('type', ['refund', 'replacement'])->default('refund');
            $table->string('reason', 50);        // damaged | wrong_item | not_as_described | quantity_short | expired | other
            $table->text('description')->nullable();
            $table->enum('status', [
                'requested', 'approved', 'rejected', 'in_transit',
                'received', 'completed', 'cancelled',
            ])->default('requested');
            $table->unsignedBigInteger('requested_amount')->default(0);
            $table->unsignedBigInteger('approved_amount')->default(0);
            $table->char('currency', 3);
            $table->boolean('restock')->default(true);   // هل تعود البضاعة للمخزون
            $table->text('decision_note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_tenant_id', 'status']);
            $table->index(['buyer_tenant_id', 'status']);
        });

        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('approved_quantity')->default(0);
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->string('condition_note')->nullable();
            $table->timestamps();

            $table->unique(['return_request_id', 'order_item_id'], 'uq_return_item');
        });

        Schema::create('return_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->foreignId('return_request_id')->nullable()->after('invoice_id')
                ->constrained()->nullOnDelete();
            $table->unsignedBigInteger('applied_amount')->default(0)->after('total');
        });

        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 14, 3)->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->timestamps();
        });

        /** نزاع بين الطرفين تتوسّط فيه المنصة */
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('buyer_tenant_id')->constrained('tenants');
            $table->foreignId('supplier_tenant_id')->constrained('tenants');
            $table->foreignId('opened_by_tenant_id')->constrained('tenants');
            $table->string('reference_no', 30)->unique();
            $table->string('reason', 50);     // not_delivered | damaged | quality | payment | return_refused | other
            $table->text('description');
            $table->unsignedBigInteger('claimed_amount')->default(0);
            $table->char('currency', 3);
            $table->enum('status', ['open', 'under_review', 'awaiting_party', 'resolved', 'closed'])->default('open');
            $table->enum('outcome', ['buyer_favored', 'supplier_favored', 'partial', 'withdrawn'])->nullable();
            $table->unsignedBigInteger('resolution_amount')->default(0);
            $table->text('resolution_note')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users');   // وسيط المنصة
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('due_at')->nullable();      // مهلة ردّ الطرف الآخر
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['supplier_tenant_id', 'status']);
            $table->index(['buyer_tenant_id', 'status']);
        });

        /** سجل النزاع: رسائل الطرفين وأدلتهما وقرارات الوسيط — يُضاف إليه ولا يُعدَّل */
        Schema::create('dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained();   // null = المنصة
            $table->foreignId('user_id')->nullable()->constrained();
            $table->enum('type', ['message', 'evidence', 'status_change', 'decision'])->default('message');
            $table->text('body')->nullable();
            $table->string('file_path')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_internal')->default(false);   // ملاحظة وسيط لا يراها الطرفان
            $table->timestamps();

            $table->index(['dispute_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_events');
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('credit_note_items');

        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('return_request_id');
            $table->dropColumn('applied_amount');
        });

        Schema::dropIfExists('return_photos');
        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');
    }
};
