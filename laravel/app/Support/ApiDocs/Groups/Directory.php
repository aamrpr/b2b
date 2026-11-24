<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** ما يراه الزائر قبل أن يسجّل: المستوردون، الكتالوج، تتبّع الشحنة. */
final class Directory
{
    public static function paths(): array
    {
        return [
            '/suppliers' => [
                'get' => [
                    'tags' => ['الدليل العام'],
                    'summary' => 'دليل المستوردين',
                    'description' => <<<'MD'
                        المستوردون **الفعّالون** وحدهم: من كان بانتظار المراجعة أو
                        مرفوضاً أو معلّقاً لا يظهر هنا، فالدليل واجهة ثقةٍ لا جدول
                        حسابات.

                        ومع كلّ مستورد ما يُقارَن به قبل مراسلته: شارة التوثيق،
                        والتقييم ومتوسطه، وعدد صفقاته المكتملة، وأقلّ طلبٍ يقبله،
                        وأصنافه وماركاته.
                        MD,
                    'security' => [],
                    'x-validates' => true,
                    'parameters' => [
                        Spec::query('q', 'بحث في اسم المستورد.', ['type' => 'string', 'maxLength' => 100]),
                        Spec::query('category', 'سبيكة الصنف — تحصر الدليل بمستوردي ذلك الصنف.'),
                        Spec::query('city', 'المدينة.'),
                        Spec::queryBool('verified_only', 'الموثَّقون وحدهم.'),
                        ...Spec::pagination(12, 60),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::resourcePage(Spec::ref('Supplier')), 'صفحة من الدليل.'),
                    ],
                ],
            ],

            '/suppliers/{slug}' => [
                'get' => [
                    'tags' => ['الدليل العام'],
                    'summary' => 'صفحة مستورد',
                    'description' => 'بطاقة المستورد كاملة: نبذته وتوثيقه ومدينته وهاتفه وتقييمه، وأصنافه وماركاته. يُطلَب بالسبيكة لا بالرقم، فالرابط يُشارَك.',
                    'security' => [],
                    'parameters' => [
                        Spec::path('slug', 'سبيكة المستورد.', 'string'),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::ref('Supplier')]), 'المستورد.'),
                    ],
                ],
            ],

            '/suppliers/{slug}/products' => [
                'get' => [
                    'tags' => ['الدليل العام'],
                    'summary' => 'كتالوج مستورد',
                    'description' => 'منتجات هذا المستورد المعروضة. المسار منفصل عن صفحته كي تُصفَّح منتجاته بلا إعادة تحميل بطاقته في كلّ صفحة.',
                    'security' => [],
                    'parameters' => [
                        Spec::path('slug', 'سبيكة المستورد.', 'string'),
                        ...Spec::pagination(24, 100),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::resourcePage(Spec::ref('Product')), 'صفحة من كتالوجه.'),
                    ],
                ],
            ],

            '/products' => [
                'get' => [
                    'tags' => ['الدليل العام'],
                    'summary' => 'تصفّح السوق',
                    'description' => <<<'MD'
                        كتالوج المنصة كلّه — المنتجات **المعروضة** عند كلّ
                        المستوردين. والمسوَّدة والمؤرشفة لا تظهر.

                        والبحث يمشي في الاسم العربي والإنجليزي معاً، وفي رمز
                        المنتج والماركة: من يعرف البضاعة باسمها الأجنبي يجدها،
                        ومن يعرفها بالعربية يجدها كذلك.

                        و`category` سبيكةٌ لا رقم، ويشمل **الأصناف الفرعية** كلّها:
                        من طلب «قطع سيارات» وجد ما تحتها لا ما وُسِم بها حرفياً.
                        وأكثر من سبيكة تُفصَل بفاصلة، فيجمعها (أو). وكذلك `brand` و`city`.

                        و`price_min` و`price_max` بالوحدة الصغرى للعملة.

                        و`attr` يصفّي بالخصائص. كل سجل هو مفتاح الخاصية ثم محرف
                        الوحدة (`U+001F`) ثم مفتاح القيمة، والسجلات بينها محرف
                        السجل (`U+001E`). المفتاح هو النص العربي مطوياً — أو
                        الإنجليزي إن لم يُكتب عربي — كما يرجع في `facets.attributes`.
                        وقيم الخاصية الواحدة تُجمع، والخصائص المختلفة تُضيَّق.

                        ومع كل صفحة يأتي `facets`: شجرة الأصناف وعدّاد كل صنف،
                        والماركات، ومدن المستوردين، والخصائص وقيمها، وأدنى سعر
                        وأعلاه — كي يُبنى عمود التصفية.
                        MD,
                    'security' => [],
                    'parameters' => [
                        Spec::query('q', 'بحث في الاسم (بلغتيه) أو رمز المنتج أو الماركة.'),
                        Spec::query('category', 'سبيكة صنف أو أكثر مفصولة بفاصلة. كل سبيكة تشمل فروعها.'),
                        Spec::query('brand', 'ماركة أو أكثر، مفصولة بفاصلة.'),
                        Spec::query('city', 'مدينة المستورد أو أكثر، مفصولة بفاصلة.'),
                        Spec::query('attr', 'خصائص مختارة. السجلات مفصولة بـ U+001E، وكل سجل مفتاح الخاصية ثم U+001F ثم مفتاح القيمة.'),
                        Spec::query('price_min', 'أدنى سعر بالوحدة الصغرى.', ['type' => 'integer', 'minimum' => 0]),
                        Spec::query('price_max', 'أعلى سعر بالوحدة الصغرى.', ['type' => 'integer', 'minimum' => 0]),
                        Spec::query('supplier_id', 'حصره بمستوردٍ بعينه.', ['type' => 'integer']),
                        Spec::queryEnum('sort', 'الترتيب. الافتراضي الأحدث أوّلاً.', ['price_asc', 'price_desc', 'popular']),
                        ...Spec::pagination(24, 100),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::resourcePage(Spec::ref('Product')), 'صفحة من السوق.'),
                    ],
                ],
            ],

            '/products/bestsellers' => [
                'get' => [
                    'tags' => ['الدليل العام'],
                    'summary' => 'الأكثر مبيعاً',
                    'description' => <<<'MD'
                        عشرة منتجات على الأكثر، مرتّبة بكمية البيع الصافية:
                        ما ثُبّت في طلب قائم (مؤكّد فما بعد) ناقص ما استُلم
                        مرتجعاً. المسودّة وما ينتظر الموافقة والملغى لا تدخل،
                        ولا يظهر إلا منتج ما زال معروضاً.

                        والتعادل بين كميتين يُحسم بالأحدث بيعاً. والقائمة
                        للصفحة الرئيسية، لا للتصفّح.
                        MD,
                    'security' => [],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'data' => Spec::arrayOf(Spec::ref('Product')),
                        ]), 'المنتجات العشرة الأكثر مبيعاً، أو أقل إن قلّ ما بيع.'),
                    ],
                ],
            ],

            '/products/{slug}' => [
                'get' => [
                    'tags' => ['الدليل العام'],
                    'summary' => 'صفحة منتج',
                    'description' => <<<'MD'
                        المنتج بكلّ ما يُعرَض عنه: صوره، وصنفه، ومستورده بتوثيقه،
                        وسلّم سعره بالكمية، وخصائصه — مرّتين: `specifications`
                        بلغة الطلب للعرض، و`specifications_translations` بلغتيها
                        لشاشة التعديل.

                        والاستدعاء **يزيد عدّاد المشاهدات**، وهو ما يُرتَّب به
                        `sort=popular`.
                        MD,
                    'security' => [],
                    'parameters' => [
                        Spec::path('slug', 'سبيكة المنتج.', 'string'),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::ref('Product')]), 'المنتج.'),
                    ],
                ],
            ],

            '/track/{token}' => [
                'get' => [
                    'tags' => ['الدليل العام'],
                    'summary' => 'تتبّع شحنة برمزها العامّ',
                    'description' => <<<'MD'
                        يفتحه من لا حساب له — المشتري، أو السائق أحياناً.

                        والمفتاح **رمزٌ عشوائي لا رقم التتبّع**: الأرقام متسلسلة
                        قابلة للتخمين، ومعها يستطيع أيّ أحدٍ تصفّح شحنات غيره.

                        والردّ مختصرٌ عن قصد: حالة الشحنة وشركتها وخطّها الزمنيّ
                        ومدينة الوصول — ولا أسعار ولا أسماء أطراف، لأنّ الرابط
                        يُشارَك.
                        MD,
                    'security' => [],
                    'parameters' => [
                        Spec::path('token', 'الرمز العامّ من صفحة الشحنة.', 'string'),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'shipment' => Spec::object([
                                'shipment_no' => ['type' => 'string'],
                                'order_no' => ['type' => 'string', 'nullable' => true],
                                'carrier' => ['type' => 'string', 'nullable' => true],
                                'carrier_phone' => ['type' => 'string', 'nullable' => true],
                                'carrier_tracking_url' => ['type' => 'string', 'nullable' => true, 'description' => 'صفحة التتبّع عند شركة الشحن، إن كان لها قالب رابط.'],
                                'tracking_number' => ['type' => 'string', 'nullable' => true],
                                'status' => ['type' => 'string'],
                                'packages_count' => ['type' => 'integer', 'nullable' => true],
                                'destination_city' => ['type' => 'string', 'nullable' => true],
                                'estimated_delivery' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                'shipped_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                'delivered_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                                'received_by' => ['type' => 'string', 'nullable' => true, 'description' => 'من استلم.'],
                            ]),
                            'events' => Spec::arrayOf(Spec::object([
                                'status' => ['type' => 'string'],
                                'location' => ['type' => 'string', 'nullable' => true],
                                'description' => ['type' => 'string', 'nullable' => true],
                                'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
                            ]), ['description' => 'الخطّ الزمنيّ، أقدمه أوّلاً.']),
                        ]), 'الشحنة وخطّها الزمنيّ.'),
                        '404' => [
                            'description' => 'لا شحنة بهذا الرمز. تعرض الواجهة حقل بحثٍ بدل خطأ.',
                            'content' => ['application/json' => ['schema' => Spec::ref('Error')]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
