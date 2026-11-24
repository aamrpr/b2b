<?php

use App\Support\Mail\EmailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * يضيف قوالب الأحداث التي لم تكن في الدفعة الأولى،
 * ولا يمسّ نصاً عدّله السوبر أدمن.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (EmailTemplateDefaults::all() as $row) {
            $exists = DB::table('email_templates')->where('key', $row['key'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('email_templates')->insert([
                'key' => $row['key'],
                'enabled' => true,
                'subject' => json_encode($row['subject'], JSON_UNESCAPED_UNICODE),
                'body' => json_encode($row['body'], JSON_UNESCAPED_UNICODE),
                'sort_order' => $row['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $original = ['login', 'register', 'password', 'rfq'];

        DB::table('email_templates')->whereNotIn('key', $original)->delete();
    }
};
