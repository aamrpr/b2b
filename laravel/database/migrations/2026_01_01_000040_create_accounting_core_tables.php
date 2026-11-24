<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نواة المحاسبة: دليل الحسابات، السنوات والفترات، القيود وأسطرها، الأرصدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('code', 20);
            $table->json('name');                       // {"ar":"العملاء","en":"Accounts Receivable"}
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->string('subtype', 40)->nullable();
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->string('system_key', 50)->nullable(); // مفتاح ثابت للربط البرمجي: ar, ap, sales, cogs...
            $table->boolean('is_leaf')->default(true);
            $table->boolean('is_system')->default(false);
            $table->char('currency', 3)->nullable();     // حساب بعملة محددة (اختياري)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'system_key']);
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'soft_closed', 'closed'])->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'start_date', 'end_date']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('accounting_periods');
            $table->string('entry_no', 30);
            $table->date('entry_date');
            $table->enum('type', ['auto', 'manual', 'adjusting', 'closing', 'opening', 'reversal'])->default('auto');
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->text('description')->nullable();
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->unsignedBigInteger('total_debit');
            $table->unsignedBigInteger('total_credit');
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'entry_no']);
            $table->index(['tenant_id', 'entry_date', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('account_id')->constrained();
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->unsignedBigInteger('base_debit')->default(0);  // بعملة الدفتر
            $table->unsignedBigInteger('base_credit')->default(0);
            $table->string('description')->nullable();
            $table->foreignId('cost_center_id')->nullable();
            $table->string('contact_type', 100)->nullable();   // Tenant (عميل أو مورد)
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'journal_entry_id']);
            $table->index(['tenant_id', 'account_id']);
            $table->index(['contact_type', 'contact_id']);
        });

        Schema::create('account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('accounting_periods')->cascadeOnDelete();
            $table->unsignedBigInteger('opening_debit')->default(0);
            $table->unsignedBigInteger('opening_credit')->default(0);
            $table->unsignedBigInteger('period_debit')->default(0);
            $table->unsignedBigInteger('period_credit')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'period_id']);
            $table->index(['tenant_id', 'period_id']);
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('code', 20);
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // قيود النزاهة المالية — MySQL 8 يفرض CHECK فعلياً
        DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT chk_je_balanced CHECK (total_debit = total_credit)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_jl_debit_xor_credit CHECK ((debit = 0 AND credit > 0) OR (debit > 0 AND credit = 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('account_balances');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('accounts');
    }
};
