<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('payment_no', 30);
            $table->enum('direction', ['in', 'out']);
            $table->string('contact_type', 100)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->enum('method', ['cash', 'bank', 'gateway', 'wallet', 'hawala', 'cheque'])->default('cash');
            $table->string('gateway', 50)->nullable();
            $table->string('gateway_reference')->nullable();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->date('payment_date');
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'payment_no']);
            $table->index(['tenant_id', 'status', 'payment_date']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->unique(['payment_id', 'invoice_id']);
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('pending_balance')->default(0);
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->timestamps();

            $table->unique(['tenant_id', 'currency']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['credit', 'debit']);
            $table->unsignedBigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('account_name');
            $table->text('account_number_encrypted')->nullable();
            $table->text('iban_encrypted')->nullable();
            $table->string('swift', 20)->nullable();
            $table->char('currency', 3);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('payout_no', 30)->unique();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->char('currency', 3);
            $table->foreignId('bank_account_id')->nullable()->constrained();
            $table->enum('method', ['bank', 'cash', 'hawala'])->default('bank');
            $table->enum('status', ['requested', 'approved', 'processing', 'completed', 'failed', 'rejected'])->default('requested');
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('platform_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('supplier_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
