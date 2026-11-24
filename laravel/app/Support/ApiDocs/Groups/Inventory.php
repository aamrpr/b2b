<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** المخزون: مستودعات وأصناف وحركات وجرد. */
final class Inventory
{
    private const TYPES = ['purchase_in', 'opening', 'return_in', 'sale_out', 'adjustment', 'transfer_in', 'transfer_out'];

    private static function warehouseFields(): array
    {
        return [
            'name' => Spec::translatable('191'),
            'code' => ['type' => 'string', 'maxLength' => 20],
            'address' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
            'lat' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90, 'nullable' => true],
            'lng' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180, 'nullable' => true],
            'is_default' => ['type' => 'boolean', 'nullable' => true, 'description' => 'المستودع الافتراضي — إليه تذهب الحركات التي لا تحدّد مستودعاً.'],
            'is_active' => ['type' => 'boolean', 'nullable' => true],
        ];
    }

    private static function item(): array
    {
        return Spec::object([
            'id' => ['type' => 'integer'],
            'product' => Spec::object([
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'sku' => ['type' => 'string', 'nullable' => true],
            ]),
            'warehouse' => ['type' => 'string', 'nullable' => true],
            'quantity' => ['type' => 'integer', 'description' => 'الرصيد.'],
            'average_cost' => ['type' => 'integer', 'description' => 'متوسط التكلفة المرجّح، بالوحدة الصغرى.'],
            'value' => ['type' => 'integer', 'description' => 'الرصيد × متوسط التكلفة.'],
            'reorder_point' => ['type' => 'integer', 'nullable' => true, 'description' => 'حدّ إعادة الطلب — تحته يظهر الصنف في `low-stock`.'],
        ]);
    }

    public static function paths(): array
    {
        return [
            '/inventory/warehouses' => [
                'get' => [
                    'tags' => ['المخزون'],
                    'summary' => 'المستودعات',
                    'description' => 'مستودعاتك ورموزها وأرصدتها. قائمةٌ كاملة بلا تصفّح — عددها قليلٌ بطبيعته.',
                    'x-roles' => [Spec::SUPPLIER],
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('warehouses', ['type' => 'object']), 'المستودعات.'),
                    ],
                ],
                'post' => [
                    'tags' => ['المخزون'],
                    'summary' => 'مستودع جديد',
                    'description' => 'أوّل مستودعٍ يُنشأ مع الحساب. وهذا لمن له أكثر من مخزن — والرصيد يُحسَب **لكلّ مستودعٍ على حدة** لا مجموعاً.',
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body(self::warehouseFields(), ['name', 'code']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['warehouse' => ['type' => 'object']]), 'أُنشئ المستودع.'),
                    ],
                ],
            ],

            '/inventory/warehouses/{warehouse}' => [
                'put' => [
                    'tags' => ['المخزون'],
                    'summary' => 'تعديل مستودع',
                    'description' => 'تعديلٌ جزئيّ. وتعيين `is_default` ينزعها عن غيره: الافتراضيّ واحد.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('warehouse', 'معرّف المستودع.')],
                    'requestBody' => Spec::optionalBody(self::warehouseFields()),
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['warehouse' => ['type' => 'object']]), 'المستودع بعد الحفظ.'),
                    ],
                ],
                'delete' => [
                    'tags' => ['المخزون'],
                    'summary' => 'تعطيل مستودع',
                    'description' => '**تعطيل لا حذف**: يختفي من قوائم الاختيار وتبقى حركاته في السجلّ — حركةٌ مُرحَّلة لا ينبغي أن تفقد مكانها.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('warehouse', 'معرّف المستودع.')],
                    'responses' => [
                        '200' => Spec::message('عُطّل المستودع.'),
                    ],
                ],
            ],

            '/inventory/items' => [
                'get' => [
                    'tags' => ['المخزون'],
                    'summary' => 'أصناف المخزون',
                    'description' => 'رصيد كلّ منتجٍ في كلّ مستودع، ومتوسط تكلفته، وقيمته. والصنف هنا هو تقاطع «منتج × مستودع»: المنتج الواحد في مستودعين صنفان برصيدين وتكلفتين.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [
                        // شرطٌ على الصفحة لا عمودٌ يُصفّى، فيبقى معاملاً عادياً
                        Spec::queryBool('low_stock', 'ما نزل تحت حدّ إعادة الطلب وحده.'),
                        ...Spec::dataTableParams(['id', 'quantity', 'average_cost', 'reorder_point'], ['product', 'warehouse']),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(self::item(), [
                            'currency' => ['type' => 'string', 'description' => 'عملة دفترك — بها كلّ المبالغ هنا.'],
                        ]), 'صفحة من الأصناف.'),
                    ],
                ],
            ],

            '/inventory/items/{inventoryItem}' => [
                'get' => [
                    'tags' => ['المخزون'],
                    'summary' => 'صنف واحد بطبقاته وحركاته',
                    'description' => <<<'MD'
                        الرصيد، و**طبقات التكلفة** (FIFO: كلّ دفعةٍ بتكلفتها
                        وبقيّتها)، والحركات.

                        ويعيد معه `derived_quantity`: الرصيد محسوباً من مجموع
                        الحركات. ومطابقتُه للرصيد المخزَّن هي الفحص الذي يكشف أيّ
                        انحرافٍ بين الحقل وسجلّه.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('inventoryItem', 'معرّف صنف المخزون — لا معرّف المنتج.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string'],
                            'item' => self::item(),
                            'derived_quantity' => ['type' => 'integer', 'description' => 'الرصيد محسوباً من الحركات — يجب أن يطابق `item.quantity`.'],
                            'movements' => Spec::arrayOf(['type' => 'object']),
                            'layers' => Spec::arrayOf(['type' => 'object'], ['description' => 'طبقات FIFO الباقية.']),
                        ]), 'الصنف كاملاً.'),
                    ],
                ],
            ],

            '/inventory/items/{inventoryItem}/settings' => [
                'put' => [
                    'tags' => ['المخزون'],
                    'summary' => 'حدّ إعادة الطلب',
                    'description' => 'الرصيد الذي تحته يصير الصنف «ناقصاً» فيظهر في `low-stock` ويُنبَّه عليه. صفرٌ يعني لا تنبيه.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('inventoryItem', 'معرّف صنف المخزون.')],
                    'requestBody' => Spec::body([
                        'reorder_point' => ['type' => 'integer', 'minimum' => 0],
                    ], ['reorder_point']),
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['item' => self::item()]), 'الصنف بعد الحفظ.'),
                    ],
                ],
            ],

            '/inventory/valuation' => [
                'get' => [
                    'tags' => ['المخزون'],
                    'summary' => 'تقييم المخزون',
                    'description' => <<<'MD'
                        قيمة المخزون من جدوله، ورصيد **حساب المخزون في الأستاذ**،
                        والفرق بينهما.

                        والفرق صفرٌ إن كان كلّ شيء سليماً، و`is_balanced` تقول
                        ذلك صريحاً: المخزون والمحاسبة وجهان لرقمٍ واحد، وفرقٌ
                        بينهما خللٌ ينبغي أن يُرى لا أن يُكتشَف في نهاية السنة.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string'],
                            'costing_method' => ['type' => 'string', 'enum' => ['weighted_average', 'fifo'], 'description' => 'طريقة التكلفة في دفترك.'],
                            'total_value' => ['type' => 'integer', 'description' => 'قيمة المخزون من جدوله.'],
                            'ledger_balance' => ['type' => 'integer', 'description' => 'رصيد حساب المخزون في الأستاذ.'],
                            'difference' => ['type' => 'integer', 'description' => 'الفرق — صفرٌ إن تطابقا.'],
                            'is_balanced' => ['type' => 'boolean'],
                        ]), 'التقييم.'),
                    ],
                ],
            ],

            '/inventory/low-stock' => [
                'get' => [
                    'tags' => ['المخزون'],
                    'summary' => 'ما نقص من المخزون',
                    'description' => 'ما نزل تحت حدّ إعادة الطلب. مسارٌ مختصر بلا تصفّح: تقرؤه بطاقة التنبيه في اللوحة.',
                    'x-roles' => [Spec::SUPPLIER],
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('items', self::item(), [
                            'currency' => ['type' => 'string'],
                        ]), 'الأصناف الناقصة.'),
                    ],
                ],
            ],

            '/inventory/movements' => [
                'get' => [
                    'tags' => ['المخزون'],
                    'summary' => 'حركات المخزون',
                    'description' => <<<'MD'
                        كلّ دخولٍ وخروج بنوعه ومصدره، أحدثه أوّلاً.

                        و**الحركة لا تُعدَّل بعد تسجيلها**: الخطأ يُصحَّح بحركةٍ
                        مقابلة لا بتغيير القديمة — كما في الدفتر.

                        ومع كلّ حركة `balance_after` و`average_cost_after`: الرصيد
                        والتكلفة بعدها، فيُقرأ تاريخ الصنف بلا إعادة حساب.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => Spec::dataTableParams(['id', 'type', 'quantity', 'unit_cost', 'balance_after', 'created_at'], ['type', 'warehouse', 'product', 'created_at']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(Spec::object([
                            'id' => ['type' => 'integer'],
                            'type' => ['type' => 'string', 'enum' => self::TYPES],
                            'product' => ['type' => 'string'],
                            'warehouse' => ['type' => 'string', 'nullable' => true],
                            'quantity' => ['type' => 'integer', 'description' => 'موجبٌ للدخول، سالبٌ للخروج.'],
                            'unit_cost' => ['type' => 'integer'],
                            'balance_after' => ['type' => 'integer'],
                            'average_cost_after' => ['type' => 'integer'],
                            'source' => ['type' => 'string', 'nullable' => true, 'description' => 'ما ولّد الحركة: أمر شراء، تسليم، جرد…'],
                            'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
                        ]), [
                            'currency' => ['type' => 'string'],
                        ]), 'صفحة من الحركات.'),
                    ],
                ],
            ],

            '/inventory/receive' => [
                'post' => [
                    'tags' => ['المخزون'],
                    'summary' => 'إدخال بضاعة',
                    'description' => <<<'MD'
                        إدخالٌ مباشر: الرصيد الافتتاحيّ، أو شراءٌ لم يمرّ بأمر
                        شراء. والشراء الموثَّق يدخل من `purchase-orders/{id}/receive`
                        لا من هنا.

                        و`unit_cost` هو ما **يُرجَّح به المتوسط**: دفعةٌ بتكلفةٍ
                        أعلى ترفع متوسط الصنف بقدر حجمها.

                        و`post_journal` يقرّر هل يُرحَّل قيد الإدخال: الرصيد
                        الافتتاحيّ يُرحَّل مرّةً واحدة عند بدء الدفتر لا كلّما
                        أُدخل شيء.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body([
                        'product_id' => ['type' => 'integer'],
                        'variant_id' => ['type' => 'integer', 'nullable' => true],
                        'warehouse_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'الافتراضي مستودعك الافتراضيّ.'],
                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                        'unit_cost' => Spec::minor('تكلفة الوحدة'),
                        'type' => ['type' => 'string', 'enum' => ['purchase_in', 'opening', 'return_in'], 'nullable' => true, 'description' => 'الافتراضي `purchase_in`.'],
                        'post_journal' => ['type' => 'boolean', 'nullable' => true, 'description' => 'هل يُرحَّل قيد الإدخال.'],
                        'notes' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
                    ], ['product_id', 'quantity', 'unit_cost']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'movement_id' => ['type' => 'integer'],
                            'balance' => ['type' => 'integer', 'description' => 'الرصيد بعد الإدخال.'],
                            'average_cost' => ['type' => 'integer', 'description' => 'المتوسط المرجّح بعده.'],
                        ]), 'دخلت البضاعة.'),
                    ],
                ],
            ],

            '/inventory/items/{inventoryItem}/adjust' => [
                'post' => [
                    'tags' => ['المخزون'],
                    'summary' => 'تسوية رصيد صنف',
                    'description' => <<<'MD'
                        تُرسِل **الكمية المعدودة** لا الفرق، فيحسب الخادم الفرق
                        ويُرحّله حركةَ تسوية بقيدها. فلا يُخطئ العادّ في الطرح.

                        و`reason` إلزاميّ: تسويةٌ بلا سببٍ رقمٌ لا يُراجَع.

                        وإن لم يكن فرقٌ عاد السطر «لا فرق — الرصيد مطابق» ولم
                        تُرحَّل حركة.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('inventoryItem', 'معرّف صنف المخزون.')],
                    'requestBody' => Spec::body([
                        'counted_quantity' => ['type' => 'integer', 'minimum' => 0, 'description' => 'ما عددتَه فعلاً على الرفّ.'],
                        'reason' => ['type' => 'string', 'maxLength' => 255, 'description' => 'سبب الفرق.'],
                    ], ['counted_quantity', 'reason']),
                    'responses' => [
                        '200' => Spec::message('رُحّلت التسوية — أو لا فرق.', [
                            'difference' => ['type' => 'integer', 'description' => 'الفرق المُرحَّل. يغيب إن لم يكن فرق.'],
                            'balance' => ['type' => 'integer'],
                        ]),
                    ],
                ],
            ],

            '/inventory/items/{inventoryItem}/transfer' => [
                'post' => [
                    'tags' => ['المخزون'],
                    'summary' => 'نقل بين مستودعين',
                    'description' => 'حركتان متقابلتان: خروجٌ من هنا ودخولٌ هناك، **بالتكلفة نفسها** — فالنقل لا يُنشئ ربحاً ولا خسارة. و`warehouse_id` هو المستودع المنقول إليه. والنقل فوق المتاح مرفوض بـ٤٢٢.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('inventoryItem', 'معرّف صنف المخزون — المنقول منه.')],
                    'requestBody' => Spec::body([
                        'warehouse_id' => ['type' => 'integer', 'description' => 'المستودع المنقول إليه.'],
                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                        'notes' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
                    ], ['warehouse_id', 'quantity']),
                    'responses' => [
                        '200' => Spec::message('تمّ النقل.', [
                            'from_balance' => ['type' => 'integer'],
                            'to_balance' => ['type' => 'integer'],
                        ]),
                    ],
                ],
            ],

            '/inventory/stock-counts' => [
                'get' => [
                    'tags' => ['المخزون'],
                    'summary' => 'جلسات الجرد',
                    'description' => 'جرودك بحالاتها: مفتوح، مُعتمَد. والمعتمَد قد رُحِّلت فروقه.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => Spec::dataTableParams(['id', 'reference_no', 'count_date', 'status', 'approved_at'], ['status', 'warehouse']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object']), 'صفحة من الجرود.'),
                    ],
                ],
                'post' => [
                    'tags' => ['المخزون'],
                    'summary' => 'جرد جديد',
                    'description' => 'يفتح جلسةً على مستودع، **ويُجمِّد أصنافه بأرصدتها في تلك اللحظة** — فما يُعَدّ يُقابَل برصيدٍ ثابت لا برصيدٍ يتحرّك أثناء العدّ.',
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body([
                        'warehouse_id' => ['type' => 'integer'],
                        'count_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'الافتراضي اليوم.'],
                        'notes' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                    ], ['warehouse_id']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'stock_count' => ['type' => 'object', 'description' => 'الجلسة وأصنافها بأرصدتها المجمَّدة.'],
                        ]), 'فُتح الجرد.'),
                    ],
                ],
            ],

            '/inventory/stock-counts/{stockCount}' => [
                'get' => [
                    'tags' => ['المخزون'],
                    'summary' => 'جلسة جرد',
                    'description' => 'الجلسة وأصنافها: الرصيد المجمَّد، والكمية المعدودة، والفرق، وقيمته.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('stockCount', 'معرّف الجرد.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['stock_count' => ['type' => 'object']]), 'الجلسة.'),
                    ],
                ],
            ],

            '/inventory/stock-counts/{stockCount}/record' => [
                'put' => [
                    'tags' => ['المخزون'],
                    'summary' => 'حفظ الكميات المعدودة',
                    'description' => 'يُحفَظ العدّ ولا يُرحَّل شيء: العادّ يحفظ على مراحل، ويعود، ويصحّح — والترحيل خطوةٌ أخرى يقرّرها مراجعٌ بعد أن يرى الفروق.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('stockCount', 'معرّف الجرد.')],
                    'requestBody' => Spec::body([
                        'items' => Spec::arrayOf(Spec::object([
                            'id' => ['type' => 'integer', 'description' => 'معرّف سطر الجرد.'],
                            'counted_quantity' => ['type' => 'integer', 'minimum' => 0],
                            'notes' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
                        ], ['id', 'counted_quantity']), ['minItems' => 1]),
                    ], ['items']),
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['stock_count' => ['type' => 'object']]), 'الجلسة بفروقها المحدَّثة.'),
                    ],
                ],
            ],

            '/inventory/stock-counts/{stockCount}/approve' => [
                'post' => [
                    'tags' => ['المخزون'],
                    'summary' => 'اعتماد جرد',
                    'description' => '**يُرحِّل كلّ الفروق تسوياتٍ بقيودها** في فعلٍ واحد، ويُقفل الجلسة. ويعيد عدد ما رُحِّل وصافي قيمته — فالجرد الذي يُنقِص المخزون يُنقِص ربح الدفتر، وهذا رقمٌ يُقرأ.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('stockCount', 'معرّف الجرد.')],
                    'responses' => [
                        '200' => Spec::message('اعتُمد الجرد ورُحِّلت فروقه.', [
                            'adjusted' => ['type' => 'integer', 'description' => 'عدد التسويات المُرحَّلة.'],
                            'net_value' => ['type' => 'integer', 'description' => 'صافي قيمة الفروق — سالبٌ إن نقص المخزون.'],
                        ]),
                    ],
                ],
            ],
        ];
    }
}
