<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** الشحن: شركاته وتسعيرتها، والشحنات وحالاتها. */
final class Shipping
{
    private static function carrierFields(): array
    {
        return [
            'name' => Spec::translatable('191'),
            'code' => ['type' => 'string', 'maxLength' => 20, 'description' => 'رمزٌ قصير تعرفه به في جداولك.'],
            'phone' => ['type' => 'string', 'maxLength' => 30, 'nullable' => true],
            'website' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 255, 'nullable' => true],
            'tracking_url_template' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true, 'description' => 'قالب رابط التتبّع عند الشركة، و`{tracking_number}` يُستبدَل فيه.'],
            'coverage' => Spec::arrayOf(['type' => 'string', 'maxLength' => 100], ['nullable' => true, 'description' => 'المدن التي تغطّيها.']),
            'supports_cod' => ['type' => 'boolean', 'nullable' => true, 'description' => 'الدفع عند التسليم.'],
            'is_active' => ['type' => 'boolean', 'nullable' => true],
        ];
    }

    public static function paths(): array
    {
        return [
            '/carriers' => [
                'get' => [
                    'tags' => ['الشحن'],
                    'summary' => 'شركات الشحن',
                    'description' => 'الشركات العامّة في المنصة ومعها شركاتك الخاصّة. والفصل مقصود: السوق المحلّي مليء بشركاتٍ صغيرة لا تُعرَف خارج مدينتها، فلكلّ مستوردٍ أن يضيف من يعمل معه.',
                    'x-roles' => [Spec::AUTHED],
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('carriers', Spec::object([
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'code' => ['type' => 'string'],
                            'phone' => ['type' => 'string', 'nullable' => true],
                            'website' => ['type' => 'string', 'nullable' => true],
                            'coverage' => Spec::arrayOf(['type' => 'string']),
                            'supports_cod' => ['type' => 'boolean'],
                            'is_global' => ['type' => 'boolean', 'description' => 'شركة المنصة أم شركتك.'],
                            'is_active' => ['type' => 'boolean'],
                        ])), 'القائمة كاملةً — عددها محدود.'),
                    ],
                ],
                'post' => [
                    'tags' => ['الشحن'],
                    'summary' => 'شركة شحن خاصة',
                    'description' => 'تُضاف لدفترك وحده، ولا تظهر لغيرك.',
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body(self::carrierFields(), ['name', 'code']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['carrier' => ['type' => 'object']]), 'أُضيفت الشركة.'),
                    ],
                ],
            ],

            '/carriers/{carrier}' => [
                'put' => [
                    'tags' => ['الشحن'],
                    'summary' => 'تعديل شركة شحن',
                    'description' => 'تعديلٌ جزئيّ. ولا يُعدَّل إلّا ما أضفتَه أنت: شركات المنصة تديرها الإدارة.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('carrier', 'معرّف الشركة.')],
                    'requestBody' => Spec::optionalBody(self::carrierFields()),
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['carrier' => ['type' => 'object']]), 'الشركة بعد الحفظ.'),
                    ],
                ],
                'delete' => [
                    'tags' => ['الشحن'],
                    'summary' => 'تعطيل شركة شحن',
                    'description' => '**تعطيل لا حذف**: تختفي من قوائم الاختيار وتبقى في الشحنات التي مرّت بها — شحنةٌ قديمة لا ينبغي أن تفقد اسم من حملها.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('carrier', 'معرّف الشركة.')],
                    'responses' => [
                        '200' => Spec::message('عُطّلت الشركة.'),
                    ],
                ],
            ],

            '/carriers/{carrier}/rates' => [
                'post' => [
                    'tags' => ['الشحن'],
                    'summary' => 'تسعيرة خطّ',
                    'description' => 'سعر خطٍّ عند شركة: من مدينة إلى مدينة، في نطاق وزن، بسعرٍ ثابت وسعرٍ للكيلو. ومنها تُحسَب الأسعار المقترحة في `carriers/quote`. واتركِ المدينتين فارغتين لتسعيرةٍ تنطبق على كلّ خطّ.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('carrier', 'معرّف الشركة.')],
                    'requestBody' => Spec::body([
                        'from_city' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                        'to_city' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                        'min_weight_kg' => ['type' => 'number', 'minimum' => 0, 'nullable' => true],
                        'max_weight_kg' => ['type' => 'number', 'nullable' => true, 'description' => 'أكبر من `min_weight_kg`.'],
                        'base_price' => Spec::minor('السعر الثابت'),
                        'price_per_kg' => Spec::minor('سعر الكيلو') + ['nullable' => true],
                        'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true],
                        'transit_days' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true],
                    ], ['base_price']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['rate' => ['type' => 'object']]), 'أُضيفت التسعيرة.'),
                    ],
                ],
            ],

            '/carriers/quote' => [
                'post' => [
                    'tags' => ['الشحن'],
                    'summary' => 'تسعيرة مقترحة لخطّ ووزن',
                    'description' => 'يطابق الخطّ والوزن على تسعيرات كلّ الشركات المتاحة، ويعيد الأسعار مرتَّبة. تملأ حقل التكلفة في شاشة الشحنة بدل أن يُحسب باليد في كل مرّة. وهي قراءةٌ بصيغة POST لأنّ معاييرها مركّبة.',
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body([
                        'from_city' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                        'to_city' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                        'weight_kg' => ['type' => 'number', 'minimum' => 0],
                    ], ['weight_kg']),
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('quotes', Spec::object([
                            'carrier' => ['type' => 'string'],
                            'carrier_id' => ['type' => 'integer'],
                            'price' => Spec::ref('Money'),
                            'transit_days' => ['type' => 'integer', 'nullable' => true],
                        ])), 'الأسعار المطابِقة. قائمةٌ فارغة تعني أنّ لا تسعيرة لهذا الخطّ.'),
                    ],
                ],
            ],

            '/shipments' => [
                'get' => [
                    'tags' => ['الشحن'],
                    'summary' => 'الشحنات',
                    'description' => 'شحناتك: الصادرة إن كنت مستورداً، والواردة إن كنت تاجر جملة.',
                    'x-roles' => [Spec::PARTIES],
                    'parameters' => Spec::dataTableParams(['id', 'shipment_no', 'status', 'estimated_delivery', 'shipped_at', 'created_at'], ['status', 'order', 'carrier']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object']), 'صفحة من الشحنات.'),
                    ],
                ],
            ],

            '/orders/{order}/shipments/pending' => [
                'get' => [
                    'tags' => ['الشحن'],
                    'summary' => 'ما بقي للشحن من طلب',
                    'description' => <<<'MD'
                        لكلّ بندٍ: ما طُلب، وما شُحن، وما بقي. تملأ نموذج الشحنة
                        فلا يُشحَن ما شُحن مرّتين.

                        وإذا كان الباقي صفراً في كلّ البنود فالطلب شُحن كلّه،
                        وتقول الواجهة ذلك وتعرض طريق عودةٍ بدل نموذجٍ فارغ.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('order', 'معرّف الطلب.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'order_no' => ['type' => 'string'],
                            'items' => Spec::arrayOf(Spec::object([
                                'order_item_id' => ['type' => 'integer'],
                                'description' => ['type' => 'string'],
                                'ordered' => ['type' => 'integer', 'description' => 'ما طُلب.'],
                                'shipped' => ['type' => 'integer', 'description' => 'ما شُحن.'],
                                'remaining' => ['type' => 'integer', 'description' => 'ما بقي.'],
                            ])),
                        ]), 'بنود الطلب وأرصدتها.'),
                    ],
                ],
            ],

            '/orders/{order}/shipments' => [
                'post' => [
                    'tags' => ['الشحن'],
                    'summary' => 'شحنة جديدة',
                    'description' => <<<'MD'
                        تُنشأ من أمر الشراء، وتُشتقّ حالة الطلب منها — فلا تُكتب
                        الحالة في موضعين.

                        و`items` اختياريّ: بلا بنودٍ تُشحَن بقيّة الطلب كلّها، ومع
                        بنودٍ يكون الشحن **جزئياً** فيبقى الباقي لشحنةٍ أخرى.

                        ويُولَّد مع الشحنة **رمز تتبّع عامّ** — رمزٌ عشوائي لا رقم
                        التتبّع — يُشارَك مع المشتري أو السائق فيفتحون
                        `track/{token}` بلا حساب.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('order', 'معرّف الطلب.')],
                    'requestBody' => Spec::body([
                        'carrier_id' => ['type' => 'integer', 'nullable' => true],
                        'tracking_number' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true, 'description' => 'رقم التتبّع عند شركة الشحن.'],
                        'incoterm' => ['type' => 'string', 'maxLength' => 10, 'nullable' => true],
                        'origin_address' => ['type' => 'object', 'nullable' => true, 'description' => 'عنوان المصدر، حرّاً.'],
                        'destination_address' => ['type' => 'object', 'nullable' => true, 'description' => 'عنوان الوصول. يُقرأ منه `city` في صفحة التتبّع العامّة.'],
                        'weight_kg' => ['type' => 'number', 'minimum' => 0, 'nullable' => true],
                        'packages_count' => ['type' => 'integer', 'minimum' => 1, 'nullable' => true],
                        'shipping_cost' => Spec::minor('تكلفة الشحن') + ['nullable' => true],
                        'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true],
                        'is_cod' => ['type' => 'boolean', 'nullable' => true, 'description' => 'الدفع عند التسليم.'],
                        'cod_amount' => Spec::minor('المبلغ المحصَّل عند التسليم') + ['nullable' => true],
                        'estimated_delivery' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        'notes' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                        'items' => Spec::arrayOf(Spec::object([
                            'order_item_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                        ], ['order_item_id', 'quantity']), ['nullable' => true, 'description' => 'للشحن الجزئيّ. اتركه فارغاً لشحن الباقي كلّه.']),
                    ]),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'shipment' => ['type' => 'object', 'description' => 'الشحنة ببنودها وأحداثها ومستنداتها ورمز تتبّعها.'],
                        ]), 'أُنشئت الشحنة.'),
                    ],
                ],
            ],

            '/shipments/{shipment}' => [
                'get' => [
                    'tags' => ['الشحن'],
                    'summary' => 'تفاصيل شحنة',
                    'description' => 'الشحنة كاملةً: شركتها، ورقم تتبّعها، وبنودها، وخطّها الزمنيّ بأحداثه ومن سجّل كلّ حدث، ومستنداتها، ورمز تتبّعها العامّ.',
                    'x-roles' => [Spec::PARTIES],
                    'parameters' => [Spec::path('shipment', 'معرّف الشحنة.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['shipment' => ['type' => 'object']]), 'الشحنة.'),
                    ],
                ],
            ],

            '/shipments/{shipment}/status' => [
                'patch' => [
                    'tags' => ['الشحن'],
                    'summary' => 'نقل حالة شحنة',
                    'description' => <<<'MD'
                        **التسلسل محروس**: لا تقفز الشحنة من حالةٍ إلى غير التي
                        تليها. والانتقال الممنوع يعود ٤٢٢ ومعه `allowed`: ما
                        يُسمح من هذه الحالة — فيعرف المستدعي ما يستطيع لا ما لا
                        يستطيع فقط.

                        وكلّ انتقالٍ يُسجَّل حدثاً في الخطّ الزمنيّ، فيظهر في
                        صفحة التتبّع العامّة من غير عملٍ إضافيّ.

                        و`delivered` تضبط وقت التسليم و`received_by`.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('shipment', 'معرّف الشحنة.')],
                    'requestBody' => Spec::body([
                        'status' => ['type' => 'string', 'enum' => ['ready', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned', 'cancelled']],
                        'location' => ['type' => 'string', 'maxLength' => 150, 'nullable' => true, 'description' => 'المكان — يظهر في الخطّ الزمنيّ.'],
                        'description' => Spec::object([
                            'ar' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
                            'en' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
                        ]) + ['nullable' => true],
                        'received_by' => ['type' => 'string', 'maxLength' => 150, 'nullable' => true, 'description' => 'من استلم — مع `delivered`.'],
                    ], ['status']),
                    'responses' => [
                        '200' => Spec::message('نُقلت الحالة.', ['shipment' => ['type' => 'object']]),
                        '422' => [
                            'description' => 'انتقالٌ غير مسموح من الحالة الحالية.',
                            'content' => ['application/json' => ['schema' => Spec::object([
                                'message' => ['type' => 'string'],
                                'allowed' => Spec::arrayOf(['type' => 'string'], ['description' => 'ما يُسمح من الحالة الحالية.']),
                            ])]],
                        ],
                    ],
                ],
            ],

            '/shipments/{shipment}/events' => [
                'post' => [
                    'tags' => ['الشحن'],
                    'summary' => 'حدث تتبّع بلا تغيير حالة',
                    'description' => 'لما يقع بين الحالات: «وصلت المستودع الوسيط»، «تأخّرت على الحدود». يظهر في الخطّ الزمنيّ العامّ، ولا ينقل الشحنة. و`description.ar` إلزاميّ: حدثٌ بلا وصفٍ سطرٌ فارغ في التتبّع.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('shipment', 'معرّف الشحنة.')],
                    'requestBody' => Spec::body([
                        'location' => ['type' => 'string', 'maxLength' => 150, 'nullable' => true],
                        'description' => Spec::translatable('255'),
                    ], ['description']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['shipment' => ['type' => 'object']]), 'أُضيف الحدث.'),
                    ],
                ],
            ],

            '/shipments/{shipment}/documents' => [
                'post' => [
                    'tags' => ['الشحن'],
                    'summary' => 'رفع مستند شحن',
                    'description' => 'بوليصة الشحن، وقائمة التعبئة، والفاتورة التجارية، وشهادة المنشأ، ومذكّرة التسليم. حتى ١٠ ميغابايت، والصيغ: PDF و JPG و PNG و WEBP و XLSX و DOCX.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('shipment', 'معرّف الشحنة.')],
                    'requestBody' => Spec::form([
                        'type' => ['type' => 'string', 'enum' => ['bill_of_lading', 'packing_list', 'commercial_invoice', 'certificate_of_origin', 'delivery_note', 'other']],
                        'file' => Spec::file('المستند، حتى 10240 كيلوبايت.'),
                    ], ['type', 'file']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'document' => Spec::object([
                                'id' => ['type' => 'integer'],
                                'type' => ['type' => 'string'],
                                'url' => ['type' => 'string'],
                                'original_name' => ['type' => 'string'],
                            ]),
                        ]), 'رُفع المستند.'),
                    ],
                ],
            ],
        ];
    }
}
