<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime', 127)->nullable()->after('attachment_name');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE messages MODIFY COLUMN type ENUM('text', 'file', 'image', 'audio', 'video', 'system') NOT NULL DEFAULT 'text'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("UPDATE messages SET type = 'file' WHERE type IN ('audio', 'video')");
            DB::statement("ALTER TABLE messages MODIFY COLUMN type ENUM('text', 'file', 'image', 'system') NOT NULL DEFAULT 'text'");
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['attachment_name', 'attachment_mime']);
        });
    }
};
