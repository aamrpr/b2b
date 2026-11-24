<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** أوامر الشراء: ما ينشأ عن قبول عرض. */
final class Orders
{
    public static function paths(): array
    {
        return [
            '/orders' => [
                'get' => [
                    'tags' => ['أوامر الشراء'],
                    'summary' => 'أوامر الشراء',
                    'description' => <<<'MD'
                        المسار واحد والطرفان يقرؤانه: المستورد يرى ما بيع منه،
                        وتاجر الجملة يرى ما اشتراه — والعزل هو ما يفصل.

                        ومع كلّ طلبٍ عمولة المنصة عليه: المستورد يراها لأنّها
                        عليه.
                        MD,
                    'x-roles' => [Spec::PARTIES],
                    'parameters' => Spec::dataTableParams(['id', 'order_no', 'status', 'payment_status', 'total', 'created_at'], ['order_no', 'counterpart', 'total', 'commission', 'status', 'payment_status']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(Spec::ref('Order')), 'صفحة من الطلبات.'),
                    ],
                ],
            ],

            '/orders/{order}' => [
                'get' => [
                    'tags' => ['أوامر الشراء'],
                    'summary' => 'أمر شراء واحد',
                    'description' => 'الطلب ببنوده ومجاميعه وعمولته وحالته وحالة سداده. يفتحه طرفاه.',
                    'x-roles' => [Spec::PARTIES],
                    'parameters' => [Spec::path('order', 'معرّف الطلب.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::ref('Order')]), 'الطلب.'),
                    ],
                ],
            ],

            '/orders/{order}/status' => [
                'patch' => [
                    'tags' => ['أوامر الشراء'],
                    'summary' => 'نقل حالة أمر شراء',
                    'description' => <<<'MD'
                        المستورد ينقل حالة طلبه. والانتقال يمرّ عبر خدمة التنفيذ،
                        فيجرّ وراءه ما يترتّب عليه لا مجرّد تغيير حقل:

                        - **`delivered`** هي الحدث الثقيل: يخرج المخزون بتكلفته،
                          وتُولَّد الفاتورة وقيودها في دفتر المستورد، ويُرحَّل قيد
                          العمولة في دفتري المستورد والمنصة.
                        - **`shipped`** تُشتقّ عادةً من الشحنة لا تُكتب هنا — فلا
                          تُكتب الحالة في موضعين.
                        - **`cancelled`** تُقفل الطلب ولا تُعكس ما رُحِّل: ما
                          رُحِّل يُعكس بقيدٍ مقابل لا بالمحو.

                        وإن كان **الرصيد غير كافٍ** للتسليم رُفض الانتقال بـ٤٢٢،
                        ومعه `shortages`: كلّ صنفٍ ناقص وكم ينقصه. فالرفض يقول ما
                        يجب فعله لا «لا».
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('order', 'معرّف الطلب.')],
                    'requestBody' => Spec::body([
                        'status' => ['type' => 'string', 'enum' => ['in_production', 'shipped', 'delivered', 'closed', 'cancelled']],
                        'note' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true, 'description' => 'ملاحظة تُسجَّل مع الانتقال.'],
                    ], ['status']),
                    'responses' => [
                        '200' => Spec::message('نُقلت الحالة.', [
                            'order' => Spec::ref('Order'),
                            'status' => ['type' => 'string'],
                        ]),
                        '422' => [
                            'description' => 'انتقالٌ غير مسموح، أو رصيدٌ غير كافٍ للتسليم.',
                            'content' => ['application/json' => ['schema' => Spec::object([
                                'message' => ['type' => 'string'],
                                'shortages' => Spec::arrayOf(Spec::object([
                                    'product' => ['type' => 'string'],
                                    'required' => ['type' => 'integer'],
                                    'available' => ['type' => 'integer'],
                                ]), ['description' => 'مع رفض التسليم لنقص الرصيد وحده.']),
                            ])]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
