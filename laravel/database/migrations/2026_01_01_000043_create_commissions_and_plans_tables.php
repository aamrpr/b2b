<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['global', 'category', 'plan', 'supplier']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->enum('type', ['percentage', 'fixed', 'tiered'])->default('percentage');
            $table->decimal('value', 10, 3)->default(0);
            $table->json('tiers')->nullable();
            $table->unsignedBigInteger('min_amount')->default(0);
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->enum('accrual_trigger', ['confirmed', 'shipped', 'delivered'])->default('delivered');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['scope', 'scope_id', 'is_active']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_tenant_id')->constrained('tenants');
            $table->unsignedBigInteger('base_amount');
            $table->decimal('rate', 6, 3)->default(0);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->enum('status', ['accrued', 'invoiced', 'settled', 'reversed'])->default('accrued');
            $table->timestamp('accrued_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('platform_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('supplier_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['supplier_tenant_id', 'status']);
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('slug')->unique();
            $table->json('description')->nullable();
            $table->unsignedBigInteger('price_monthly')->default(0);
            $table->unsignedBigInteger('price_yearly')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->json('features')->nullable();
            $table->unsignedInteger('product_limit')->nullable();
            $table->unsignedInteger('user_limit')->default(1);
            $table->unsignedInteger('rfq_limit_monthly')->nullable();
            $table->decimal('commission_discount', 5, 2)->default(0);
            $table->boolean('accounting_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('renews_at')->nullable();
            $table->enum('status', ['trial', 'active', 'past_due', 'cancelled', 'expired'])->default('trial');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_rules');
    }
};
