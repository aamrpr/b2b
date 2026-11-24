<?php

use App\Support\Mail\EmailTemplateDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * رسائل العميل التي يحرّرها السوبر أدمن: الدخول، الحساب الجديد،
 * كلمة المرور، وطلب السعر. النص بالعربية والإنجليزية، والإرسال
 * يتبع لغة حساب المستلم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->boolean('enabled')->default(true);
            $table->json('subject');
            $table->json('body');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        foreach (EmailTemplateDefaults::all() as $row) {
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
        Schema::dropIfExists('email_templates');
    }
};
