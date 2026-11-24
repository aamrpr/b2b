<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العملات — أساس دعم تعدد العملات.
 * decimal_places يحدد الوحدة الصغرى التي تُخزَّن بها كل المبالغ (BIGINT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();          // ISO 4217: SYP, USD, TRY, EUR
            $table->json('name');                         // {"ar":"ليرة سورية","en":"Syrian Pound"}
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->decimal('rate', 24, 10);
            $table->date('rate_date');
            $table->string('source', 50)->default('manual'); // manual | api
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->unique(['from_currency', 'to_currency', 'rate_date'], 'uq_rate');
            $table->index(['from_currency', 'to_currency', 'rate_date'], 'idx_rate_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
