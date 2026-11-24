<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** شركات الشحن: عامة تديرها المنصة، أو خاصة يضيفها المستورد */
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->string('code', 20);
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('tracking_url_template')->nullable();  // https://…/track/{number}
            $table->json('coverage')->nullable();                  // المحافظات المخدومة
            $table->boolean('supports_cod')->default(false);       // الدفع عند الاستلام
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['is_active', 'sort_order']);
        });

        /** تسعيرة الشحن حسب المنطقة والوزن — لا تكامل API مع شركات محلية */
        Schema::create('carrier_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->string('from_city')->nullable();
            $table->string('to_city')->nullable();
            $table->decimal('min_weight_kg', 10, 3)->default(0);
            $table->decimal('max_weight_kg', 10, 3)->nullable();
            $table->unsignedBigInteger('base_price');
            $table->unsignedBigInteger('price_per_kg')->default(0);
            $table->char('currency', 3)->default('SYP');
            $table->unsignedSmallInteger('transit_days')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['carrier_id', 'from_city', 'to_city']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();   // المستورد الشاحن
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shipment_no', 30);
            $table->string('tracking_number')->nullable();
            $table->string('tracking_token', 32)->unique();   // للتتبّع العام بلا تسجيل دخول
            $table->string('incoterm', 10)->nullable();
            $table->json('origin_address')->nullable();
            $table->json('destination_address')->nullable();
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->unsignedSmallInteger('packages_count')->default(1);
            $table->unsignedBigInteger('shipping_cost')->default(0);   // ما يدفعه المستورد للشركة
            $table->char('currency', 3)->default('SYP');
            $table->boolean('is_cod')->default(false);
            $table->unsignedBigInteger('cod_amount')->default(0);
            $table->enum('status', [
                'draft', 'ready', 'picked_up', 'in_transit',
                'out_for_delivery', 'delivered', 'failed', 'returned', 'cancelled',
            ])->default('draft');
            $table->date('estimated_delivery')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('received_by')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'shipment_no']);
            $table->index(['order_id', 'status']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        /** أصناف الشحنة — يسمح بالشحن الجزئي */
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['shipment_id', 'order_item_id'], 'uq_shipment_item');
        });

        /** سلسلة أحداث التتبّع — لا تُعدَّل، تُضاف فقط */
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30);
            $table->string('location')->nullable();
            $table->json('description')->nullable();   // {"ar": "...", "en": "..."}
            $table->timestamp('occurred_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->string('source', 20)->default('manual');   // manual | carrier_api
            $table->timestamps();

            $table->index(['shipment_id', 'occurred_at']);
        });

        Schema::create('shipment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'bill_of_lading', 'packing_list', 'commercial_invoice',
                'certificate_of_origin', 'delivery_note', 'other',
            ])->default('other');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();

            $table->index(['shipment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_documents');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('carrier_rates');
        Schema::dropIfExists('carriers');
    }
};
