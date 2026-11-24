<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** عروض الأسعار: المستورد يردّ، وتاجر الجملة يقارن ثمّ يوقّع. */
final class Quotations
{
    public static function paths(): array
    {
        return [
            '/rfqs/{rfq}/quotations' => [
                'post' => [
                    'tags' => ['عروض الأسعار'],
                    'summary' => 'إرسال عرض سعر',
                    'description' => <<<'MD'
                        ردّ المستورد على طلبٍ وصله. بنود الطلب تأتي جاهزة، فما
                        يضيفه هو السعر وشروطه.

                        والمجاميع **تُحسَب في الخادم** لا تُرسَل: المجموع وضريبته
                        وحسمه وشحنه كلّها مشتقّة من البنود، فلا يستطيع مُرسِلٌ أن
                        يعرض مجموعاً لا يطابق سطوره.

                        و`quotation_no` يُرقَّم من **دفتر هذا المستورد وحده**، فقد
                        يتكرّر الرقم نفسه عند مستوردٍ آخر: لكلٍّ تسلسله.

                        والعرض يُرسَل بحالة `sent`، ولا يصير أمر شراء إلّا بقبول
                        المشتري.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('rfq', 'معرّف الطلب الذي تُسعِّره.')],
                    'requestBody' => Spec::body([
                        'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true],
                        'shipping_amount' => Spec::minor('الشحن') + ['nullable' => true],
                        'discount_amount' => Spec::minor('الحسم') + ['nullable' => true],
                        'incoterm' => ['type' => 'string', 'maxLength' => 10, 'nullable' => true, 'description' => 'شرط التسليم: EXW، FOB، CIF…'],
                        'lead_time_days' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true],
                        'payment_terms_days' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true, 'description' => 'مهلة السداد. صفرٌ يعني نقداً.'],
                        'validity_hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 720, 'nullable' => true, 'description' => 'صلاحية عرضك. بعدها يصير `expired` ولا يُقبَل.'],
                        'notes' => ['type' => 'string', 'nullable' => true],
                        'items' => Spec::arrayOf(Spec::object([
                            'rfq_item_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'البند المقابل في الطلب — يربط سعرك بما طُلب.'],
                            'product_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'منتجك الذي تعرضه لهذا البند.'],
                            'description' => ['type' => 'string', 'maxLength' => 255],
                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                            'unit' => ['type' => 'string', 'maxLength' => 20, 'nullable' => true],
                            'unit_price' => Spec::minor('سعر الوحدة'),
                            'tax_amount' => Spec::minor('ضريبة السطر') + ['nullable' => true],
                        ], ['description', 'quantity', 'unit_price']), ['minItems' => 1]),
                    ], ['items']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'quotation' => Spec::ref('Quotation'),
                        ]), 'أُرسل العرض.'),
                    ],
                ],
                'get' => [
                    'tags' => ['عروض الأسعار'],
                    'summary' => 'العروض الواردة على طلب',
                    'description' => <<<'MD'
                        جدول المقارنة: كلّ ما ورد على هذا الطلب، صاحبه يقرؤه.

                        والشارتان — **الأفضل سعراً** و**الأسرع تسليماً** — تُحسبان
                        في الواجهة من هذه القائمة: الأدنى إجمالاً، والأقلّ مدّة
                        تسليم. فلا رأي للخادم في أيّها أفضل؛ هو يعطي الأرقام.
                        MD,
                    'x-roles' => [Spec::BUYER],
                    'parameters' => [Spec::path('rfq', 'معرّف الطلب.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'data' => Spec::arrayOf(Spec::ref('Quotation')),
                        ]), 'العروض كلّها، بلا تصفّح — العدد محدودٌ بطبيعته.'),
                    ],
                ],
            ],

            '/my/quotations' => [
                'get' => [
                    'tags' => ['عروض الأسعار'],
                    'summary' => 'عروضي',
                    'description' => 'كلّ ما أرسله المستورد، أحدثه أوّلاً، بحالته: مُرسَل، مقبول، مرفوض، منتهٍ.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => Spec::dataTableParams(['id', 'quotation_no', 'status', 'total', 'lead_time_days', 'valid_until', 'created_at'], ['quotation_no', 'total', 'lead_time_days', 'valid_until', 'status']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(Spec::ref('Quotation')), 'صفحة من عروضك.'),
                    ],
                ],
            ],

            '/quotations/{quotation}' => [
                'get' => [
                    'tags' => ['عروض الأسعار'],
                    'summary' => 'وثيقة عرض سعر',
                    'description' => <<<'MD'
                        العرض كما يُقرأ وثيقةً: شعار المستورد وتوثيقه، ورقمه،
                        وشروطه، وبنوده، ومجاميعه، وملاحظاته، و**تواقيعه**.

                        يفتحه **طرفاه** ومعهما إدارة المنصة. والإدارة تقرأ ولا
                        توقّع: ليست طرفاً في العقد.
                        MD,
                    'x-roles' => [Spec::PARTIES, Spec::PLATFORM],
                    'parameters' => [Spec::path('quotation', 'معرّف العرض.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::ref('Quotation')]), 'الوثيقة.'),
                    ],
                ],
            ],

            '/quotations/{quotation}/sign' => [
                'post' => [
                    'tags' => ['عروض الأسعار'],
                    'summary' => 'توقيع المستورد على عرضه',
                    'description' => <<<'MD'
                        المستورد يوقّع عرضه **مرّةً واحدة**: محاولةٌ ثانية تُرفض،
                        فالتوقيع ليس حقلاً يُحرَّر.

                        و`signature` صورة PNG بترميز data URL من لوحة التوقيع.
                        ويُفحَص **أوّل بايتاتها** لا ما تقوله الترويسة عن نفسها.

                        ويُحفَظ معها **`signed_by` ووقت التوقيع**: الصورة خطّ يد
                        يُشبه ويُقلَّد، والاسم والوقت هما ما يُحتجّ به عند الخلاف.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('quotation', 'معرّف العرض.')],
                    'requestBody' => Spec::body([
                        'signature' => ['type' => 'string', 'description' => 'صورة التوقيع — `data:image/png;base64,…`.'],
                        'signed_by' => ['type' => 'string', 'maxLength' => 191, 'description' => 'اسم الموقّع كما يُكتب في العقد.'],
                    ], ['signature', 'signed_by']),
                    'responses' => [
                        '200' => Spec::message('وُقّع العرض.', ['quotation' => Spec::ref('Quotation')]),
                    ],
                ],
            ],

            '/quotations/{quotation}/accept' => [
                'post' => [
                    'tags' => ['عروض الأسعار'],
                    'summary' => 'قبول عرض ⟵ أمر شراء',
                    'description' => <<<'MD'
                        **اللحظة التي تصير فيها المحادثة عقداً.** توقيع تاجر
                        الجملة هو قبوله، والقبول يُنشئ أمر الشراء في الفعل نفسه:
                        تُنقَل البنود والمجاميع، وتُحسَب عمولة المنصة بقاعدتها،
                        وتُقفَل بقيّة العروض على الطلب.

                        والتوقيع هنا **اختياريّ**: من قبل من شاشةٍ لا لوحة فيها
                        قُبل قبوله، وبقي القبول مسجَّلاً بوقته وفاعله في سجلّ
                        الطلب.

                        ولا يُقبَل عرضٌ انقضت صلاحيته أو قُبل سواه: يعود ٤٢٢ بسببه.
                        MD,
                    'x-roles' => [Spec::BUYER],
                    'parameters' => [Spec::path('quotation', 'معرّف العرض.')],
                    'requestBody' => Spec::optionalBody([
                        'signature' => ['type' => 'string', 'nullable' => true, 'description' => 'صورة التوقيع، إن وُقّع.'],
                        'signed_by' => ['type' => 'string', 'maxLength' => 191, 'nullable' => true],
                    ]),
                    'responses' => [
                        '200' => Spec::message('قُبل العرض وأُنشئ أمر الشراء.', ['order' => Spec::ref('Order')]),
                    ],
                ],
            ],
        ];
    }
}
