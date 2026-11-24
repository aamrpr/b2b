<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** المحادثات بين المستأجرين، وإشعارات المستخدم. */
final class Messaging
{
    private const EVENTS = ['rfq.broadcast', 'quotation.received', 'quotation.accepted', 'order.status', 'message.received'];

    public static function paths(): array
    {
        return [
            '/conversations' => [
                'get' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'محادثاتي',
                    'description' => <<<'MD'
                        محادثات شركتك، آخرُها رسالةً أوّلاً. ومع كلٍّ الطرف الآخر،
                        وآخر رسالة، وعدّاد غير المقروء.

                        والمحادثة **للمستأجر لا للمستخدم**: من راسله زميلك يراه
                        كلّ من في الشركة — فالمراسلة التجارية سجلّ شركةٍ لا بريد
                        شخص.

                        و`counterpart` و`unread_count` و`is_mine` تُحسَب كلّها من
                        هوية القارئ، فلا تبني الواجهة شيئاً من ذلك بنفسها.
                        MD,
                    'x-roles' => [Spec::AUTHED],
                    'parameters' => Spec::pagination(30),
                    'responses' => [
                        '200' => Spec::ok(Spec::page(Spec::ref('Conversation')), 'صفحة من محادثاتك.'),
                    ],
                ],
                'post' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'فتح محادثة',
                    'description' => <<<'MD'
                        يفتحها زرّ «مراسلة» في صفحة المستورد أو صفحة المنتج.

                        و**لا تتكرّر**: إن كانت بينكما محادثةٌ في السياق نفسه
                        أُعيدت هي لا واحدةٌ جديدة — فلا ينقسم سجلّ المراسلة على
                        نسخٍ متشابهة.

                        و`body` اختياريّ: من فتحها ليكتب لاحقاً حصل عليها فارغة.
                        MD,
                    'x-roles' => [Spec::AUTHED],
                    'requestBody' => Spec::body([
                        'tenant_id' => ['type' => 'integer', 'description' => 'مستأجر الطرف الآخر.'],
                        'context_type' => ['type' => 'string', 'enum' => ['rfq', 'order'], 'nullable' => true, 'description' => 'ما نشأت عنه المحادثة.'],
                        'context_id' => ['type' => 'integer', 'nullable' => true],
                        'subject' => ['type' => 'string', 'maxLength' => 191, 'nullable' => true],
                        'body' => ['type' => 'string', 'maxLength' => 5000, 'nullable' => true, 'description' => 'أوّل رسالة، إن أردت إرسالها فوراً.'],
                    ], ['tenant_id']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['conversation' => Spec::ref('Conversation')]), 'فُتحت المحادثة — أو أُعيدت القائمة.'),
                    ],
                ],
            ],

            '/conversations/unread-count' => [
                'get' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'عدّاد المحادثات غير المقروءة',
                    'description' => 'رقمٌ واحد لشارة العمود الجانبي. مسارٌ مختصر يُستدعى كثيراً، فلا يحمل المحادثات معه. ويعدّ **المحادثات** لا الرسائل: الشارة تقول «هناك من ينتظر ردّك» لا «وصلتك سبع رسائل».',
                    'x-roles' => [Spec::AUTHED],
                    'x-no-forbidden' => true,
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'conversations' => ['type' => 'integer', 'description' => 'عدد المحادثات التي فيها غير مقروء.'],
                        ]), 'العدّاد.'),
                    ],
                ],
            ],

            '/conversations/{conversation}' => [
                'get' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'محادثة واحدة',
                    'description' => 'ترويسة المحادثة: الطرف الآخر وسياقها وعدّادها. والرسائل في مسارٍ آخر كي تُصفَّح بلا إعادة تحميل الترويسة.',
                    'x-roles' => [Spec::AUTHED],
                    'parameters' => [Spec::path('conversation', 'معرّف المحادثة.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['conversation' => Spec::ref('Conversation')]), 'المحادثة.'),
                    ],
                ],
            ],

            '/conversations/{conversation}/messages' => [
                'get' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'رسائل محادثة',
                    'description' => 'الرسائل مصفَّحة، أحدثها أوّلاً — فالقادم إلى محادثةٍ طويلة يريد آخرها لا أوّلها. و`is_mine` محسوبةٌ من هوية القارئ.',
                    'x-roles' => [Spec::AUTHED],
                    'parameters' => [
                        Spec::path('conversation', 'معرّف المحادثة.'),
                        ...Spec::pagination(50),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::resourcePage(Spec::ref('Message')), 'صفحة من الرسائل.'),
                    ],
                ],
                'post' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'إرسال رسالة',
                    'description' => <<<'MD'
                        نصٌّ أو مرفقٌ أو كلاهما — و**أحدهما إلزاميّ**: رسالةٌ لا
                        نصّ فيها ولا ملف سطرٌ فارغ في السجلّ.

                        والمرفق أحد أربعة: صورة حتى ٨ ميغابايت، أو صوت حتى ١٥
                        (ومنها التسجيل من الميكروفون)، أو فيديو حتى ٥٠، أو PDF
                        ومستند حتى ١٥. و`kind` يقول أيّها قُصد، فيُعرض بالمشغّل
                        المناسب لا كرابطٍ عام.

                        والرسالة **تُبَثّ لحظياً** إلى الطرف الآخر، ويُنبَّه عليها
                        بحدث `message.received`.
                        MD,
                    'x-roles' => [Spec::AUTHED],
                    'parameters' => [Spec::path('conversation', 'معرّف المحادثة.')],
                    'requestBody' => Spec::form([
                        'body' => ['type' => 'string', 'maxLength' => 5000, 'description' => 'إلزاميّ إن لم يكن مرفق.'],
                        'kind' => ['type' => 'string', 'enum' => ['image', 'audio', 'video', 'file'], 'description' => 'نوع المرفق المقصود. الصوت المسجّل يُرسل audio حتى لو كانت حاويته webm.'],
                        'attachment' => Spec::file('صورة (JPG وPNG وWEBP وGIF حتى 8192 كيلوبايت)، أو صوت (WEBM وOGG وMP3 وM4A وWAV حتى 15360)، أو فيديو (MP4 وWEBM وMOV حتى 51200)، أو ملف (PDF وXLSX وXLS وCSV وDOCX حتى 15360).'),
                        'thumbnail' => Spec::file('صورة مصغّرة يصنعها المتصفح (JPG أو PNG أو WEBP حتى 2048 كيلوبايت) تُرفق مع الصورة أو الفيديو أو PDF.'),
                    ]),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['message' => Spec::ref('Message')]), 'أُرسلت الرسالة.'),
                    ],
                ],
            ],

            '/conversations/{conversation}/read' => [
                'post' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'تعليم محادثة مقروءة',
                    'description' => <<<'MD'
                        تستدعيها الواجهة عند فتح المحادثة، فيصفر عدّادها عند قارئها.
                        والقراءة **لكلّ مستأجرٍ على حدة**: قراءتك لا تمحو شارة الطرف الآخر.

                        وإن تقدّم مؤشر القراءة بُثّ `conversation.read` للطرف الآخر،
                        فتتحوّل علامة رسائله من «أُرسلت» إلى «شُوهدت». والتكرار عند
                        آخر رسالة لا يُعاد بثّه.
                        MD,
                    'x-roles' => [Spec::AUTHED],
                    'parameters' => [Spec::path('conversation', 'معرّف المحادثة.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['ok' => ['type' => 'boolean']]), 'عُلّمت المحادثة مقروءة.'),
                    ],
                ],
            ],

            '/conversations/{conversation}/typing' => [
                'post' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'إشعار الكتابة',
                    'description' => 'تبثّ الواجهة `typing: true` أثناء الكتابة و`false` عند التوقف. لا يُحفظ شيء؛ الحدث `conversation.typing` يصل الطرف الآخر لحظياً ثم يختفي.',
                    'x-roles' => [Spec::AUTHED],
                    'parameters' => [Spec::path('conversation', 'معرّف المحادثة.')],
                    'requestBody' => Spec::body([
                        'typing' => ['type' => 'boolean', 'description' => 'يكتب الآن، أو توقّف.'],
                    ], ['typing']),
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['ok' => ['type' => 'boolean']]), 'بُثّت الحالة.'),
                    ],
                ],
            ],

            '/notifications' => [
                'get' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'إشعاراتي',
                    'description' => 'إشعارات **المستخدم** هذا — لا الشركة: هو من يُنبَّه. ولكلٍّ عنوانه ونصّه و`url` التي تنقل إليها النقرة داخل الواجهة. ويعود `unread_count` معها فلا يُستدعى العدّاد مرّةً أخرى.',
                    'x-roles' => [Spec::AUTHED],
                    'x-no-forbidden' => true,
                    'parameters' => [
                        Spec::queryBool('unread_only', 'غير المقروء وحده.'),
                        ...Spec::pagination(20, 50),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::page(Spec::ref('Notification'), [
                            'unread_count' => ['type' => 'integer'],
                        ]), 'صفحة من الإشعارات.'),
                    ],
                ],
            ],

            '/notifications/unread-count' => [
                'get' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'عدّاد الإشعارات غير المقروءة',
                    'description' => 'رقمٌ واحد لشارة الجرس. مسارٌ مختصر لأنّه يُستدعى في كلّ صفحة.',
                    'x-roles' => [Spec::AUTHED],
                    'x-no-forbidden' => true,
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['count' => ['type' => 'integer']]), 'العدّاد.'),
                    ],
                ],
            ],

            '/notifications/read-all' => [
                'post' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'تعليم الكل مقروءاً',
                    'description' => 'يصفّر الشارة في فعلٍ واحد. والإشعارات تبقى في القائمة؛ القراءة ليست حذفاً.',
                    'x-roles' => [Spec::AUTHED],
                    'x-no-forbidden' => true,
                    'responses' => [
                        '200' => Spec::message('عُلّمت كلّها مقروءة.'),
                    ],
                ],
            ],

            '/notifications/preferences' => [
                'get' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'تفضيلات الإشعارات',
                    'description' => <<<'MD'
                        لكلّ حدثٍ ثلاث قنوات: داخل التطبيق، وبريد، ودفع.
                        **والافتراضي مفتوح** — من لم يغيّر شيئاً يُنبَّه، فلا
                        يفوته ما يخصّه لأنّه لم يزر صفحة الإعدادات.

                        والأحداث خمسة: بثّ طلب سعر، ووصول عرض، وقبول عرض، وتغيّر
                        حالة طلب، ووصول رسالة.

                        > **ملاحظة:** القناة الداخلية هي العاملة اليوم؛ البريد
                        > والدفع محفوظان ولم يُوصَلا بمُرسِلٍ بعد.
                        MD,
                    'x-roles' => [Spec::AUTHED],
                    'x-no-forbidden' => true,
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('preferences', Spec::object([
                            'event' => ['type' => 'string', 'enum' => self::EVENTS],
                            'in_app' => ['type' => 'boolean'],
                            'email' => ['type' => 'boolean'],
                            'push' => ['type' => 'boolean'],
                        ])), 'التفضيلات، ومعها الافتراضيّ لما لم يُحفَظ.'),
                    ],
                ],
                'put' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'حفظ تفضيل حدث',
                    'description' => 'يحفظ **حدثاً واحداً** في الطلب: الحقل الذي بدّله المستخدم وحده، فلا تُعاد التفضيلات كلّها عند كلّ نقرةٍ على مفتاح.',
                    'x-roles' => [Spec::AUTHED],
                    'x-no-forbidden' => true,
                    'requestBody' => Spec::body([
                        'event' => ['type' => 'string', 'maxLength' => 60, 'enum' => self::EVENTS],
                        'in_app' => ['type' => 'boolean', 'nullable' => true],
                        'email' => ['type' => 'boolean', 'nullable' => true],
                        'push' => ['type' => 'boolean', 'nullable' => true],
                    ], ['event']),
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['preference' => ['type' => 'object']]), 'التفضيل بعد الحفظ.'),
                    ],
                ],
            ],

            '/notifications/{id}/read' => [
                'post' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'تعليم إشعار مقروءاً',
                    'description' => 'عند النقر على الإشعار قبل الانتقال إلى `url`. والمعرّف **UUID** لا رقم — إشعارات Laravel تُرقَّم بذلك.',
                    'x-roles' => [Spec::AUTHED],
                    'parameters' => [Spec::path('id', 'معرّف الإشعار — UUID.', 'string')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['notification' => Spec::ref('Notification')]), 'الإشعار بعد التعليم.'),
                    ],
                ],
            ],

            '/notifications/{id}' => [
                'delete' => [
                    'tags' => ['المحادثات والإشعارات'],
                    'summary' => 'حذف إشعار',
                    'description' => 'حذفٌ نهائيّ من قائمة صاحبه. وهو لا يُلغي الحدث: الطلب الذي نُبِّه عليه باقٍ، والمحذوف هو التنبيه وحده.',
                    'x-roles' => [Spec::AUTHED],
                    'parameters' => [Spec::path('id', 'معرّف الإشعار — UUID.', 'string')],
                    'responses' => [
                        '200' => Spec::message('حُذف الإشعار.'),
                    ],
                ],
            ],
        ];
    }
}
