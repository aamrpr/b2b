<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** بيانات لا تخصّ حساباً: اللغات والعملات، والأصناف، ورسالة إلى الإدارة. */
final class Platform
{
    public static function paths(): array
    {
        return [
            '/meta' => [
                'get' => [
                    'tags' => ['المنصة'],
                    'summary' => 'لغات المنصة وعملاتها',
                    'description' => <<<'MD'
                        أوّل طلبٍ تُصدره الواجهة قبل أن تعرف من زائرها: اللغات
                        المدعومة واتجاه كلٍّ منها، والعملات الفعّالة بمنازلها
                        العشرية، واللغة التي حُسمت لهذا الطلب.

                        إضافة لغةٍ أو عملةٍ تظهر هنا بلا تعديل في الواجهة: اللغات
                        من `config/b2b.php` والعملات من جدولها.
                        MD,
                    'security' => [],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'locales' => Spec::arrayOf(Spec::object([
                                'code' => ['type' => 'string', 'example' => 'ar'],
                                'name' => ['type' => 'string', 'example' => 'العربية'],
                                'dir' => ['type' => 'string', 'enum' => ['rtl', 'ltr']],
                            ])),
                            'currencies' => Spec::arrayOf(Spec::object([
                                'code' => ['type' => 'string', 'example' => 'SYP'],
                                'name' => ['type' => 'string'],
                                'symbol' => ['type' => 'string'],
                                'decimals' => ['type' => 'integer', 'description' => 'به تُقسَم الوحدة الصغرى.'],
                            ])),
                            'current_locale' => ['type' => 'string', 'description' => 'ما حُسم من ترويسة Accept-Language.'],
                        ]), 'إعداد المنصة.'),
                    ],
                ],
            ],

            '/categories' => [
                'get' => [
                    'tags' => ['المنصة'],
                    'summary' => 'شجرة الأصناف',
                    'description' => <<<'MD'
                        الأصناف الفعّالة كاملةً، شجرةً: كلّ صنفٍ رئيسيّ وتحته
                        `children`. ومع كلّ صنفٍ عدّاد مستورديه ومنتجاته، فتُرسم
                        بطاقات الصفحة الرئيسية من استدعاءٍ واحد.
                        MD,
                    'security' => [],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'data' => Spec::arrayOf(Spec::ref('Category')),
                        ]), 'الشجرة كاملة.'),
                    ],
                ],
            ],

            '/banners' => [
                'get' => [
                    'tags' => ['المنصة'],
                    'summary' => 'بنرات الدعاية',
                    'description' => 'البنرات **المفعّلة** التي لها صورة، مرتّبة، وبنصّ لغة الطلب. الرابط مسار داخل المنصة. البنر المعطَّل أو بلا صورة لا يظهر.',
                    'security' => [],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'data' => Spec::arrayOf(Spec::object([
                                'id' => ['type' => 'integer'],
                                'eyebrow' => ['type' => 'string', 'nullable' => true],
                                'title' => ['type' => 'string'],
                                'text' => ['type' => 'string', 'nullable' => true],
                                'href' => ['type' => 'string', 'description' => 'مسار داخلي يبدأ بـ /.'],
                                'image_url' => ['type' => 'string'],
                            ], ['id', 'title', 'href', 'image_url'])),
                        ]), 'بنرات الصفحة الرئيسية.'),
                    ],
                ],
            ],

            '/units' => [
                'get' => [
                    'tags' => ['المنصة'],
                    'summary' => 'وحدات القياس',
                    'description' => 'الوحدات كلها، باسمها في لغة الطلب. الرمز هو ما يُخزَّن مع المنتج، و`is_active: false` وحدة عطّلتها الإدارة فلا تُختار لمنتج جديد.',
                    'security' => [],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'data' => Spec::arrayOf(Spec::object([
                                'code' => ['type' => 'string', 'description' => 'رمز ثابت، مثل `piece`.'],
                                'name' => ['type' => 'string', 'description' => 'الاسم في لغة الطلب.'],
                                'is_active' => ['type' => 'boolean'],
                            ], ['code', 'name'])),
                        ]), 'وحدات القياس.'),
                    ],
                ],
            ],

            '/categories/{slug}' => [
                'get' => [
                    'tags' => ['المنصة'],
                    'summary' => 'صنف واحد',
                    'description' => 'الصنف بسبيكته، ومعه أبناؤه. تُستدعى حين يُفتح الصنف من بطاقته.',
                    'security' => [],
                    'parameters' => [
                        Spec::path('slug', 'سبيكة الصنف — `auto-parts` لا الرقم.', 'string'),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::ref('Category')]), 'الصنف.'),
                    ],
                ],
            ],

            '/contact' => [
                'post' => [
                    'tags' => ['المنصة'],
                    'summary' => 'رسالة إلى إدارة المنصة',
                    'description' => <<<'MD'
                        ما يُرسَل من نموذج صفحة «عن المنصة». يعمل للزائر قبل أن
                        يسجّل — فسؤال من لم يقتنع بعد لا ينبغي أن يحتاج حساباً —
                        ولذلك هو **محدودٌ بالمعدّل: خمس رسائل في الدقيقة** من
                        العنوان نفسه، كي لا يصير باباً للإزعاج.

                        وهذه ليست محادثةً تجارية بين مستأجرين: تلك لها
                        `conversations`. هذه تصل صندوق الإدارة في
                        `admin/contact-messages`، ويبقى الردّ على بريد المرسِل.
                        MD,
                    'security' => [],
                    'x-throttled' => true,
                    'requestBody' => Spec::body([
                        'name' => ['type' => 'string', 'maxLength' => 191],
                        'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 191, 'description' => 'عليه يُردّ.'],
                        'phone' => ['type' => 'string', 'maxLength' => 30, 'nullable' => true],
                        'subject' => ['type' => 'string', 'maxLength' => 191],
                        'message' => ['type' => 'string', 'maxLength' => 4000],
                    ], ['name', 'email', 'subject', 'message']),
                    'responses' => [
                        '201' => Spec::created(Spec::ref('Error'), 'وصلت الرسالة. الجسم سطر طمأنةٍ للمرسِل.'),
                    ],
                ],
            ],
        ];
    }
}
