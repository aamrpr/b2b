<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** المشتريات: شراء المستورد من مورّديه — لا من المنصة. */
final class Purchasing
{
    private const PO_STATUSES = ['draft', 'sent', 'partially_received', 'received', 'closed', 'cancelled'];

    private static function vendorFields(): array
    {
        return [
            'name' => ['type' => 'string', 'maxLength' => 191],
            'code' => ['type' => 'string', 'maxLength' => 30, 'description' => 'رمز المورّد في دفترك.'],
            'contact_person' => ['type' => 'string', 'maxLength' => 191, 'nullable' => true],
            'phone' => ['type' => 'string', 'maxLength' => 30, 'nullable' => true],
            'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 191, 'nullable' => true],
            'country_code' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 2, 'nullable' => true],
            'city' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
            'address' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
            'tax_number' => ['type' => 'string', 'maxLength' => 50, 'nullable' => true],
            'default_currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true, 'description' => 'عملته المعتادة — المورّد الأجنبي يُفوتر بغير عملة دفترك.'],
            'payment_terms_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 365, 'nullable' => true, 'description' => 'مهلة سداده — منها يُحسَب تاريخ استحقاق فاتورته.'],
            'notes' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
            'is_active' => ['type' => 'boolean', 'nullable' => true],
        ];
    }

    public static function paths(): array
    {
        return [
            '/vendors' => [
                'get' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'المورّدون',
                    'description' => <<<'MD'
                        دفتر مورّديك. وهؤلاء **ليسوا مستأجرين في المنصة**: مصنعٌ
                        في الصين، أو وكيلٌ في تركيا، أو تاجرٌ في السوق — سجلّات في
                        دفترك وحدك، لا حسابات لها.

                        والفعّالون وحدهم يظهرون، إلّا بـ`include_inactive`.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [
                        Spec::queryBool('include_inactive', 'يُظهر المعطَّلين معهم.'),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('vendors', ['type' => 'object'], [
                            'currency' => ['type' => 'string'],
                        ]), 'المورّدون.'),
                    ],
                ],
                'post' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'مورّد جديد',
                    'description' => 'يُضاف إلى دفترك وحده. ولا بريد ولا كلمة مرور: لا يدخل المنصة، فأنت من يكتب عنه.',
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body(self::vendorFields(), ['name', 'code']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['vendor' => ['type' => 'object']]), 'أُضيف المورّد.'),
                    ],
                ],
            ],

            '/vendors/{vendor}' => [
                'put' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'تعديل مورّد',
                    'description' => 'تعديلٌ جزئيّ: ما لم تُرسله بقي كما هو.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('vendor', 'معرّف المورّد.')],
                    'requestBody' => Spec::optionalBody(self::vendorFields()),
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['vendor' => ['type' => 'object']]), 'المورّد بعد الحفظ.'),
                    ],
                ],
                'delete' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'تعطيل مورّد',
                    'description' => '**تعطيل لا حذف**: يختفي من قوائم الاختيار وتبقى أوامره وفواتيره في الدفتر.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('vendor', 'معرّف المورّد.')],
                    'responses' => [
                        '200' => Spec::message('عُطّل المورّد.'),
                    ],
                ],
            ],

            '/purchase-orders' => [
                'get' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'أوامر الشراء إلى المورّدين',
                    'description' => 'أوامرك بحالاتها. ولا تخلط بينها وبين `orders`: تلك ما بعتَه في المنصة، وهذه ما تشتريه من خارجها.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => Spec::dataTableParams(['id', 'reference_no', 'status', 'total', 'order_date', 'expected_date'], ['reference_no', 'status', 'vendor']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object'], [
                            'currency' => ['type' => 'string'],
                        ]), 'صفحة من الأوامر.'),
                    ],
                ],
                'post' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'أمر شراء جديد',
                    'description' => <<<'MD'
                        يُنشأ **مسوَّدةً**: لا أثر له في المخزون ولا في الدفتر حتى
                        يُرسَل ويُستلَم.

                        والبند إمّا **منتجاً من كتالوجك** (`product_id` — فيدخل
                        المخزون باسمه عند الاستلام) أو **وصفاً حرّاً** (شحنٌ،
                        تخليص، عيّنة — ما لا يُخزَّن).

                        و`warehouse_id` هو المستودع الذي سيستقبل البضاعة.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body([
                        'vendor_id' => ['type' => 'integer'],
                        'warehouse_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'الافتراضي مستودعك الافتراضيّ.'],
                        'order_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        'expected_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'الوصول المتوقَّع.'],
                        'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true, 'description' => 'الافتراضي عملة المورّد.'],
                        'incoterm' => ['type' => 'string', 'maxLength' => 10, 'nullable' => true],
                        'shipping_amount' => Spec::minor('الشحن') + ['nullable' => true],
                        'notes' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                        'items' => Spec::arrayOf(Spec::object([
                            'product_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'منتجٌ من كتالوجك. اتركه فارغاً لبندٍ لا يُخزَّن.'],
                            'description' => ['type' => 'string', 'maxLength' => 255],
                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                            'unit' => ['type' => 'string', 'maxLength' => 20, 'nullable' => true],
                            'unit_cost' => Spec::minor('تكلفة الوحدة'),
                            'tax_amount' => Spec::minor('ضريبة السطر') + ['nullable' => true],
                        ], ['description', 'quantity', 'unit_cost']), ['minItems' => 1]),
                    ], ['vendor_id', 'items']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'order' => ['type' => 'object'],
                        ]), 'أُنشئ الأمر مسوَّدةً.'),
                    ],
                ],
            ],

            '/purchase-orders/{purchaseOrder}' => [
                'get' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'أمر شراء واحد',
                    'description' => 'الأمر ببنوده ومورّده ومستودعه و**استلاماته**: لكلّ بندٍ ما طُلب وما استُلم وما بقي.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('purchaseOrder', 'معرّف أمر الشراء.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['order' => ['type' => 'object']]), 'الأمر.'),
                    ],
                ],
            ],

            '/purchase-orders/{purchaseOrder}/send' => [
                'post' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'إرسال أمر الشراء للمورّد',
                    'description' => 'ينقله من مسوَّدة إلى **مُرسَل**: صار التزاماً، فلا تُعدَّل بنوده بعده. ولا يُرسَل الأمر الفارغ.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('purchaseOrder', 'معرّف أمر الشراء.')],
                    'responses' => [
                        '200' => Spec::message('أُرسل الأمر.'),
                    ],
                ],
            ],

            '/purchase-orders/{purchaseOrder}/receive' => [
                'post' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'تسجيل استلام',
                    'description' => <<<'MD'
                        الاستلام **كلّيّ أو جزئيّ**: الشحنة تأتي مجزّأة، فتُسجَّل
                        كلّ دفعةٍ باستلامها ورقم مذكّرة مورّدها.

                        وكلّ استلام **يُدخل البضاعة المخزون بتكلفتها** من سطر
                        الأمر — فلا يُدخَل المخزون مرّتين، ولا يُدخَل بتكلفةٍ
                        مكتوبةٍ باليد.

                        وحالة الأمر تُشتقّ من المستلَم: جزئيّ ⟵
                        `partially_received`، وكامل ⟵ `received`. والاستلام فوق
                        المطلوب مرفوض بـ٤٢٢.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('purchaseOrder', 'معرّف أمر الشراء.')],
                    'requestBody' => Spec::body([
                        'warehouse_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'يغلب مستودع الأمر إن أُرسل.'],
                        'received_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        'vendor_note_no' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true, 'description' => 'رقم مذكّرة التسليم عند المورّد.'],
                        'notes' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                        'items' => Spec::arrayOf(Spec::object([
                            'purchase_order_item_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'integer', 'minimum' => 0, 'description' => 'المستلَم في هذه الدفعة. صفرٌ لبندٍ لم يصل.'],
                            'condition_note' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true, 'description' => 'حال البضاعة عند الاستلام.'],
                        ], ['purchase_order_item_id', 'quantity']), ['minItems' => 1]),
                    ], ['items']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string', 'description' => 'ومعه رقم الاستلام.'],
                            'order' => ['type' => 'object'],
                        ]), 'سُجّل الاستلام ودخل المخزون.'),
                    ],
                ],
            ],

            '/purchase-orders/{purchaseOrder}/bill' => [
                'post' => [
                    'tags' => ['المشتريات'],
                    'summary' => 'إصدار فاتورة المورّد',
                    'description' => <<<'MD'
                        يُفوتر **ما استُلم وحده** لا ما طُلب: من فوتر ما لم يصل
                        قيّد ديناً على نفسه بلا بضاعة.

                        والضريبة **تُقسَم بالتناسب** على الجزئيّ، فلا تُفوتَر
                        ضريبةُ الشحنة كلّها على أوّل دفعةٍ منها.

                        و`items` اختياريّ: بلا بنودٍ يُفوتَر كلّ ما استُلم ولم
                        يُفوتَر، ومعها يُفوتَر ما عيّنتَه.

                        وتاريخ الاستحقاق يُحسَب من مهلة سداد المورّد إن لم
                        يُرسَل.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('purchaseOrder', 'معرّف أمر الشراء.')],
                    'requestBody' => Spec::optionalBody([
                        'issue_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        'due_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'الافتراضي: تاريخ الإصدار + مهلة سداد المورّد.'],
                        'vendor_invoice_no' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true, 'description' => 'رقم فاتورة المورّد — للمطابقة معه.'],
                        'items' => Spec::arrayOf(Spec::object([
                            'purchase_order_item_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'integer', 'minimum' => 0],
                        ], ['purchase_order_item_id', 'quantity']), ['nullable' => true]),
                    ]),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'invoice' => Spec::object([
                                'id' => ['type' => 'integer'],
                                'invoice_no' => ['type' => 'string'],
                                'subtotal' => ['type' => 'integer'],
                                'tax_amount' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                                'due_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                            ]),
                        ]), 'صدرت الفاتورة ورُحِّل قيدها.'),
                    ],
                ],
            ],
        ];
    }
}
