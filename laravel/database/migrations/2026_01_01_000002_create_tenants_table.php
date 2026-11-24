<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المستأجر: المنصة نفسها، أو مستورد (مورد)، أو تاجر جملة (مشتري).
 * كل بيانات النظام معزولة بـ tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['platform', 'supplier', 'buyer']);
            $table->string('name');
            $table->string('slug')->unique();
            $table->char('base_currency', 3)->default('SYP');   // عملة الدفتر — لا تتغير بعد أول قيد
            $table->string('default_locale', 5)->default('ar');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->enum('costing_method', ['wac', 'fifo'])->default('wac');
            $table->char('country_code', 2)->default('SY');
            $table->string('city')->nullable();
            $table->enum('status', ['pending', 'active', 'suspended', 'rejected'])->default('pending');
            $table->boolean('accounting_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
