<?php

namespace App\Support\Mail;

/**
 * النصوص الأولى لرسائل العميل.
 *
 * تُزرع مع الجدول حتى يجدها السوبر أدمن جاهزة للتحرير.
 * العناصر بين الأقواس تُستبدل عند الإرسال، والإطار والشعار ثابتان.
 */
final class EmailTemplateDefaults
{
    /** @return list<array{key: string, sort_order: int, subject: array{ar: string, en: string}, body: array{ar: string, en: string}}> */
    public static function all(): array
    {
        return [
            self::row('login', 1, [
                'ar' => 'تم تسجيل الدخول إلى حسابك',
                'en' => 'You signed in to your account',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>تم تسجيل الدخول إلى حسابك في {{app}} بالبريد {{email}} في {{time}}.</p><p>إذا لم تكن أنت من دخل، غيّر كلمة المرور فوراً.</p>',
                'en' => '<p>Hello {{name}},</p><p>Your account on {{app}} ({{email}}) was just signed in at {{time}}.</p><p>If this was not you, change your password right away.</p>',
            ]),
            self::row('register', 2, [
                'ar' => 'تم إنشاء حسابك',
                'en' => 'Your account is ready',
            ], [
                'ar' => '<p>أهلاً {{name}}،</p><p>أُنشئ حسابك في {{app}} بنجاح، وبريد الدخول هو {{email}}.</p><p>يمكنك الآن الدخول وإكمال بيانات منشأتك.</p>',
                'en' => '<p>Welcome {{name}},</p><p>Your {{app}} account is ready. You sign in with {{email}}.</p><p>You can sign in now and finish your company profile.</p>',
            ]),
            self::row('password', 3, [
                'ar' => 'تم تغيير كلمة المرور',
                'en' => 'Your password was changed',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>تم تغيير كلمة مرور حسابك {{email}} في {{time}}.</p><p>إذا لم تطلب هذا التغيير، تواصل مع إدارة المنصة.</p>',
                'en' => '<p>Hello {{name}},</p><p>The password for {{email}} was changed at {{time}}.</p><p>If you did not ask for this, contact the platform team.</p>',
            ]),
            self::row('account', 4, [
                'ar' => 'تغيّرت حالة حسابك',
                'en' => 'Your account status changed',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>حالة حسابك في {{app}} الآن: <strong>{{status}}</strong>.</p><p>{{reason}}</p>',
                'en' => '<p>Hello {{name}},</p><p>Your {{app}} account is now: <strong>{{status}}</strong>.</p><p>{{reason}}</p>',
            ]),
            self::row('verification', 5, [
                'ar' => 'نتيجة مراجعة حسابك',
                'en' => 'Your account review',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>نتيجة مراجعة حسابك في {{app}}: <strong>{{result}}</strong>.</p><p>{{reason}}</p>',
                'en' => '<p>Hello {{name}},</p><p>The review of your {{app}} account: <strong>{{result}}</strong>.</p><p>{{reason}}</p>',
            ]),
            self::row('contact', 6, [
                'ar' => 'استلمنا رسالتك',
                'en' => 'We received your message',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>وصلتنا رسالتك بعنوان <strong>{{subject}}</strong>، وسنرد على {{email}}.</p>',
                'en' => '<p>Hello {{name}},</p><p>We received your message, <strong>{{subject}}</strong>, and will reply to {{email}}.</p>',
            ]),
            self::row('rfq', 7, [
                'ar' => 'استلمنا طلب السعر {{reference}}',
                'en' => 'We received price request {{reference}}',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>استلمنا طلب السعر <strong>{{reference}}</strong>: {{title}}.</p><p>الصنف: {{category}}. سنُعلمك عندما تصل العروض.</p>',
                'en' => '<p>Hello {{name}},</p><p>We received your price request <strong>{{reference}}</strong>: {{title}}.</p><p>Category: {{category}}. We will tell you when offers arrive.</p>',
            ]),
            self::row('rfqinbox', 8, [
                'ar' => 'طلب سعر جديد في أصنافك',
                'en' => 'A new price request in your categories',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>نُشر طلب سعر في أصنافك: <strong>{{title}}</strong>.</p><p>الرقم {{reference}} · الصنف {{category}}.</p>',
                'en' => '<p>Hello {{name}},</p><p>A price request was published in your categories: <strong>{{title}}</strong>.</p><p>Number {{reference}} · category {{category}}.</p>',
            ]),
            self::row('quotation', 9, [
                'ar' => 'عرض سعر جديد من {{supplier}}',
                'en' => 'A new quotation from {{supplier}}',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>وصل عرض من <strong>{{supplier}}</strong> برقم {{reference}}.</p><p>الإجمالي {{amount}}، والتسليم خلال {{days}} يوم.</p>',
                'en' => '<p>Hello {{name}},</p><p>A quotation arrived from <strong>{{supplier}}</strong>, number {{reference}}.</p><p>Total {{amount}}, delivery in {{days}} days.</p>',
            ]),
            self::row('accepted', 10, [
                'ar' => 'تم قبول عرضك',
                'en' => 'Your quotation was accepted',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>قُبل عرضك وصار أمر شراء <strong>{{order_no}}</strong> بقيمة {{amount}}.</p>',
                'en' => '<p>Hello {{name}},</p><p>Your quotation was accepted and became purchase order <strong>{{order_no}}</strong> for {{amount}}.</p>',
            ]),
            self::row('rejected', 11, [
                'ar' => 'لم يُقبل عرضك {{reference}}',
                'en' => 'Your quotation {{reference}} was not accepted',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>اختار المشتري عرضاً آخر على الطلب <strong>{{title}}</strong>، ولم يُقبل عرضك {{reference}}.</p>',
                'en' => '<p>Hello {{name}},</p><p>The buyer chose another offer for <strong>{{title}}</strong>. Your quotation {{reference}} was not accepted.</p>',
            ]),
            self::row('order', 12, [
                'ar' => 'تحديث على الطلب {{order_no}}',
                'en' => 'Update on order {{order_no}}',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>الطلب <strong>{{order_no}}</strong> حالته الآن: {{status}}.</p><p>القيمة {{amount}}.</p>',
                'en' => '<p>Hello {{name}},</p><p>Order <strong>{{order_no}}</strong> is now: {{status}}.</p><p>Amount {{amount}}.</p>',
            ]),
            self::row('shipment', 13, [
                'ar' => 'تحديث على الشحنة {{shipment_no}}',
                'en' => 'Update on shipment {{shipment_no}}',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>الشحنة <strong>{{shipment_no}}</strong>: {{status}}.</p><p>رقم التتبّع: {{tracking}}</p>',
                'en' => '<p>Hello {{name}},</p><p>Shipment <strong>{{shipment_no}}</strong>: {{status}}.</p><p>Tracking number: {{tracking}}</p>',
            ]),
            self::row('returns', 14, [
                'ar' => 'تحديث على المرتجع {{reference}}',
                'en' => 'Update on return {{reference}}',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>المرتجع <strong>{{reference}}</strong>: {{status}}.</p><p>القيمة {{amount}}.</p>',
                'en' => '<p>Hello {{name}},</p><p>Return <strong>{{reference}}</strong>: {{status}}.</p><p>Amount {{amount}}.</p>',
            ]),
            self::row('dispute', 15, [
                'ar' => 'تحديث على النزاع {{reference}}',
                'en' => 'Update on dispute {{reference}}',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>النزاع <strong>{{reference}}</strong>: {{status}}.</p><p>المطالبة {{amount}}.</p>',
                'en' => '<p>Hello {{name}},</p><p>Dispute <strong>{{reference}}</strong>: {{status}}.</p><p>Claim {{amount}}.</p>',
            ]),
            self::row('message', 16, [
                'ar' => 'رسالة جديدة من {{sender}}',
                'en' => 'A new message from {{sender}}',
            ], [
                'ar' => '<p>مرحباً {{name}}،</p><p>وصلتك رسالة من <strong>{{sender}}</strong>:</p><p>{{excerpt}}</p>',
                'en' => '<p>Hello {{name}},</p><p>You have a message from <strong>{{sender}}</strong>:</p><p>{{excerpt}}</p>',
            ]),
        ];
    }

    /** @return list<string> */
    public static function placeholders(string $key): array
    {
        $extra = match ($key) {
            'rfq', 'rfqinbox' => ['title', 'reference', 'category'],
            'quotation' => ['supplier', 'reference', 'amount', 'days'],
            'accepted' => ['order_no', 'amount'],
            'rejected' => ['title', 'reference'],
            'order' => ['order_no', 'status', 'amount'],
            'shipment' => ['shipment_no', 'status', 'tracking'],
            'returns', 'dispute' => ['reference', 'status', 'amount'],
            'message' => ['sender', 'excerpt'],
            'verification' => ['result', 'reason'],
            'account' => ['status', 'reason'],
            'contact' => ['subject'],
            default => [],
        };

        return array_values(array_unique([...['name', 'email', 'app', 'time'], ...$extra]));
    }

    /** @param  array{ar: string, en: string}  $subject */
    /** @param  array{ar: string, en: string}  $body */
    private static function row(string $key, int $sort, array $subject, array $body): array
    {
        return [
            'key' => $key,
            'sort_order' => $sort,
            'subject' => $subject,
            'body' => $body,
        ];
    }
}
