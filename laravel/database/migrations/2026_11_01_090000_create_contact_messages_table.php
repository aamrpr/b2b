<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * رسائل التواصل من صفحة «عن المنصة».
     *
     * المحادثات في المنصة بين مستأجرَين مسجَّلين، وهذه رسالة من خارجها:
     * زائرٌ لم يسجّل بعد، أو مستوردٌ يسأل المنصة نفسها. فجدولٌ بسيط
     * يحفظها ويُعلم إدارتها، ولا يُقحمها في جدول المحادثات فيختلط
     * التجاريّ بالخدميّ.
     */
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('subject', 191);
            $table->text('message');

            // يُملآن حين يكون المرسِل مسجّلاً: سياقٌ يختصر على الإدارة السؤال
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('status', ['new', 'read', 'archived'])->default('new');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
