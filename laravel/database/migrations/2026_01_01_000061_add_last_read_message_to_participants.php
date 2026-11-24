<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تتبّع القراءة بمعرّف الرسالة لا بوقتها.
 * طوابع MySQL بدقّة الثانية، فرسالتان في الثانية نفسها كانتا تُحتسبان مقروءتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('last_read_message_id')->nullable()->after('last_read_at');
            $table->index(['tenant_id', 'last_read_message_id'], 'idx_participant_read');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropIndex('idx_participant_read');
            $table->dropColumn('last_read_message_id');
        });
    }
};
