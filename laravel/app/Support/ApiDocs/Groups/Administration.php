<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/**
 * إدارة المنصة — كلّها خلف وسيط `platform`.
 *
 * وكلّ فعلٍ كاتبٍ هنا يُسجَّل في سجلّ التدقيق باسم فاعله ووقته وعنوانه
 * وقيمه قبل وبعد: من يملك سلطةً على حسابات الناس ينبغي أن يبقى أثره.
 */
final class Administration
{
    private static function countedSection(): array
    {
        return Spec::object([
            'key' => ['type' => 'string', 'description' => 'مفتاح القسم — تترجمه الواجهة.'],
            'items' => Spec::arrayOf(Spec::object([
                'key' => ['type' => 'string'],
                'value' => ['type' => 'integer'],
                'tone' => ['type' => 'string', 'nullable' => true, 'description' => 'نبرة العرض: `warning` لما يحتاج تدخّلاً.'],
            ])),
        ]);
    }

    private static function chartPoints(): array
    {
        return Spec::arrayOf(Spec::object([
            'key' => ['type' => 'string'],
            'value' => ['type' => 'integer'],
        ]));
    }

    private static function categoryFields(): array
    {
        return [
            'name' => Spec::translatable('120'),
            'parent_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'الصنف الأب — اتركه فارغاً لصنفٍ رئيسيّ.'],
            'icon' => ['type' => 'string', 'maxLength' => 60, 'nullable' => true, 'description' => 'اسم أيقونة lucide.'],
            'sort_order' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999, 'nullable' => true],
            'is_active' => ['type' => 'boolean', 'nullable' => true],
            'is_featured' => ['type' => 'boolean', 'nullable' => true, 'description' => 'يظهر في بطاقات الصفحة الرئيسية.'],
        ];
    }

    private static function commissionFields(): array
    {
        return [
            'scope' => ['type' => 'string', 'enum' => ['global', 'category', 'plan', 'supplier'], 'description' => 'نطاق القاعدة.'],
            'scope_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'معرّف الصنف أو الخطة أو المستورد — مع `global` يُترك فارغاً.'],
            'type' => ['type' => 'string', 'enum' => ['percentage', 'fixed', 'tiered'], 'description' => 'نسبة، أو مبلغ ثابت، أو سلّم درجات.'],
            'value' => ['type' => 'number', 'minimum' => 0, 'nullable' => true, 'description' => 'النسبة أو المبلغ — بحسب `type`.'],
            'tiers' => Spec::arrayOf(Spec::object([
                'up_to' => ['type' => 'integer', 'minimum' => 1, 'nullable' => true, 'description' => 'سقف الدرجة. اتركه فارغاً للأخيرة المفتوحة.'],
                'rate' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            ], ['rate']), ['nullable' => true, 'description' => 'مع `tiered` وحده.']),
            'accrual_trigger' => ['type' => 'string', 'enum' => ['confirmed', 'shipped', 'delivered'], 'nullable' => true, 'description' => 'لحظة استحقاق العمولة. الافتراضي من إعداد المنصة (`delivered`).'],
            'priority' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true, 'description' => 'عند تساوي النطاق، الأعلى أولوية يغلب.'],
            'is_active' => ['type' => 'boolean', 'nullable' => true],
        ];
    }

    public static function paths(): array
    {
        return [
            /* ---------- اللوحة ---------- */

            '/admin/dashboard' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'لوحة المنصة',
                    'description' => <<<'MD'
                        أرقام المنصة في مدّة: قيمة البضاعة المتداولة بعملاتها
                        **ومحوَّلةً إلى عملة المنصة**، وعدّادات الطلبات بحالاتها،
                        وإيراد العمولة وما استُحقّ منها وما حُصِّل، والاشتراكات
                        وإيرادها الشهريّ المتكرّر، وعدّادات المستوردين
                        والمشترين، وقمع طلبات الأسعار.

                        وإيراد العمولة **يُقرأ من الدفتر** لا من جدول العمولات:
                        الدفتر هو الرقم المعتمد.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [
                        Spec::query('from', 'من تاريخ. الافتراضي بداية الشهر.', ['type' => 'string', 'format' => 'date']),
                        Spec::query('to', 'إلى تاريخ. الافتراضي اليوم.', ['type' => 'string', 'format' => 'date']),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string', 'description' => 'عملة المنصة — إليها تُحوَّل المجاميع.'],
                            'from' => ['type' => 'string', 'format' => 'date'],
                            'to' => ['type' => 'string', 'format' => 'date'],
                            'gmv' => Spec::object([
                                'total' => ['type' => 'integer', 'description' => 'المجموع بعملة المنصة.'],
                                'by_currency' => Spec::arrayOf(Spec::object([
                                    'currency' => ['type' => 'string'],
                                    'amount' => ['type' => 'integer', 'description' => 'بعملته الأصلية.'],
                                    'converted' => ['type' => 'integer', 'description' => 'محوَّلاً إلى عملة المنصة.'],
                                    'orders' => ['type' => 'integer'],
                                ])),
                            ]),
                            'orders' => Spec::object([
                                'total' => ['type' => 'integer'],
                                'by_status' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                            ]),
                            'commission' => Spec::object([
                                'revenue' => ['type' => 'integer', 'description' => 'من الدفتر — الرقم المعتمد.'],
                                'receivable' => ['type' => 'integer', 'description' => 'رصيد ذمم المستوردين.'],
                                'accrued' => ['type' => 'integer', 'description' => 'ما استُحقّ.'],
                                'settled' => ['type' => 'integer', 'description' => 'ما حُصِّل.'],
                                'currency' => ['type' => 'string'],
                            ]),
                            'subscriptions' => Spec::object([
                                'active' => ['type' => 'integer'],
                                'mrr' => ['type' => 'integer', 'description' => 'الإيراد الشهريّ المتكرّر.'],
                                'arr' => ['type' => 'integer', 'description' => 'السنويّ = الشهريّ × ١٢.'],
                                'currency' => ['type' => 'string'],
                            ]),
                            'suppliers' => Spec::object([
                                'total' => ['type' => 'integer'],
                                'active' => ['type' => 'integer'],
                                'pending_review' => ['type' => 'integer', 'description' => 'ما ينتظر اعتماداً — عملٌ على الإدارة.'],
                                'verified' => ['type' => 'integer'],
                            ]),
                            'buyers' => ['type' => 'integer'],
                            'rfq' => Spec::object([
                                'published' => ['type' => 'integer'],
                                'quoted' => ['type' => 'integer', 'description' => 'ما وصله عرضٌ واحد على الأقلّ.'],
                                'awarded' => ['type' => 'integer', 'description' => 'ما صار أمر شراء.'],
                                'quote_rate' => ['type' => 'number', 'description' => 'نسبة ما وصله عرض — صحّة جانب العرض.'],
                                'conversion_rate' => ['type' => 'number', 'description' => 'نسبة ما صار صفقة.'],
                            ]),
                        ]), 'أرقام المدّة.'),
                    ],
                ],
            ],

            '/admin/dashboard/summary' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'ملخّص كل شيء بالعدد',
                    'description' => <<<'MD'
                        عدّ المنصة كلّها في استدعاءٍ واحد، بلا مدّة: `highlights`
                        خمسة أرقام كبيرة، ثمّ `sections` ثمانية أقسام تعدّ
                        المستأجرين والكتالوج والطلب والصفقات والشحن والمرتجعات
                        والفواتير والعمولة.

                        و**بيانات الرسوم صريحةٌ من الخادم** في `charts` لا
                        مستخرَجة من الأقسام في الواجهة: القسم ترتيبُ عرضٍ قد
                        يتبدّل، والرسم له معناه — قمعٌ يقرأ من الطلب إلى
                        التسليم، وتوزّعٌ يقرأ تكوين الحسابات.

                        والمفاتيح إنجليزية تترجمها الواجهة: الخادم يعدّ ولا
                        يصيغ.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'generated_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'لحظة العدّ — فالأرقام لحظية.'],
                            'highlights' => Spec::object([
                                'suppliers' => ['type' => 'integer'],
                                'buyers' => ['type' => 'integer'],
                                'products' => ['type' => 'integer'],
                                'categories' => ['type' => 'integer'],
                                'completed_orders' => ['type' => 'integer', 'description' => 'المسلَّم والمغلق — ما بعده لا عودة إلى دورة الشراء.'],
                                'gmv_orders' => ['type' => 'integer', 'description' => 'عدد الطلبات كلّها.'],
                            ]),
                            'charts' => Spec::object([
                                'funnel' => self::chartPoints() + ['description' => 'طلبات ⟵ عروض ⟵ أوامر ⟵ مكتملة.'],
                                'accounts' => self::chartPoints() + ['description' => 'موثَّقون، بانتظار المراجعة، مشترون.'],
                                'orders_by_status' => self::chartPoints(),
                            ]),
                            'sections' => Spec::arrayOf(self::countedSection()),
                        ]), 'الملخّص.'),
                    ],
                ],
            ],

            '/admin/dashboard/series' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'سلسلة شهرية',
                    'description' => 'لكلّ شهر: قيمة البضاعة المتداولة، وعدد الطلبات، والعمولة. يرسمها الخطّ في اللوحة. والشهور الفارغة **تعود بأصفار** لا تُحذَف، فلا يقفز الخطّ فوق شهرٍ لم يحدث فيه شيء.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [
                        Spec::query('months', 'عدد الشهور إلى الوراء. الافتراضي ١٢.', ['type' => 'integer', 'minimum' => 1, 'maximum' => 36, 'default' => 12]),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('series', Spec::object([
                            'month' => ['type' => 'string', 'example' => '2026-10'],
                            'gmv' => ['type' => 'integer'],
                            'orders' => ['type' => 'integer'],
                            'commission' => ['type' => 'integer'],
                        ]), [
                            'currency' => ['type' => 'string'],
                        ]), 'السلسلة، أقدمها أوّلاً.'),
                    ],
                ],
            ],

            '/admin/dashboard/top-suppliers' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'أعلى المستوردين',
                    'description' => 'العشرة الأوائل في المدّة بقيمة ما بيع منهم، ومعهم عدد طلباتهم وعمولتهم. ومنهم يُعرَف على من تقوم المنصة فعلاً.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [
                        Spec::query('from', 'من تاريخ.', ['type' => 'string', 'format' => 'date']),
                        Spec::query('to', 'إلى تاريخ.', ['type' => 'string', 'format' => 'date']),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('suppliers', Spec::object([
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'slug' => ['type' => 'string'],
                            'currency' => ['type' => 'string'],
                            'orders' => ['type' => 'integer'],
                            'gmv' => ['type' => 'integer'],
                            'commission' => ['type' => 'integer'],
                        ])), 'الأوائل.'),
                    ],
                ],
            ],

            /* ---------- اعتماد المستوردين ---------- */

            '/admin/suppliers' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'المستوردون للمراجعة',
                    'description' => 'كلّ المستوردين بحالات توثيقهم. و`status=pending` هو صندوق العمل: من ينتظر قراراً.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [
                        Spec::queryEnum('status', 'حالة التوثيق.', ['pending', 'under_review', 'verified', 'rejected']),
                        ...Spec::pagination(20),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::page(['type' => 'object']), 'صفحة من المستوردين.'),
                    ],
                ],
            ],

            '/admin/suppliers/{tenant}' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'ملف مستورد ووثائقه',
                    'description' => 'ما يُبنى عليه القرار: بطاقة المستورد، وأصنافه، و**وثائقه المرفوعة**، ومستخدموه. فالاعتماد يُقرأ من الأوراق لا من الاسم.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('tenant', 'معرّف مستأجر المستورد.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'supplier' => ['type' => 'object'],
                            'documents' => Spec::arrayOf(['type' => 'object'], ['description' => 'الوثائق المرفوعة بروابطها.']),
                            'users' => Spec::arrayOf(Spec::object([
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'email' => ['type' => 'string'],
                            ])),
                        ]), 'الملفّ.'),
                    ],
                ],
            ],

            '/admin/suppliers/{tenant}/verify' => [
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'اعتماد مستورد',
                    'description' => '**يفتح له السوق**: يظهر في الدليل بشارة التوثيق، وتبدأ طلبات الأسعار تصله في أصنافه. ويُبلَّغ مستخدموه، ويُسجَّل الفعل في التدقيق.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('tenant', 'معرّف مستأجر المستورد.')],
                    'responses' => [
                        '200' => Spec::message('اعتُمد المستورد.', ['supplier' => ['type' => 'object']]),
                    ],
                ],
            ],

            '/admin/suppliers/{tenant}/reject' => [
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'رفض طلب توثيق',
                    'description' => '**السبب إلزاميّ**، ويُبلَّغ به المستورد: من رُفض ينبغي أن يعرف ماذا يصحّح ليعيد الطلب. والرفض لا يحذف الحساب؛ يبقى ولا يُعرَض.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('tenant', 'معرّف مستأجر المستورد.')],
                    'requestBody' => Spec::body([
                        'reason' => ['type' => 'string', 'maxLength' => 500, 'description' => 'يُبلَّغ به المستورد، ويُسجَّل في التدقيق.'],
                    ], ['reason']),
                    'responses' => [
                        '200' => Spec::message('رُفض الطلب وأُبلغ المستورد.'),
                    ],
                ],
            ],

            '/admin/suppliers/{tenant}/suspend' => [
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعليق حساب أو تفعيله',
                    'description' => '**مفتاحٌ يقلب الحالة**: يعلّق الفعّال ويفعّل المعلَّق، فزرٌّ واحد لا زرّان. والتعليق يُخفي المستورد من الدليل ويمنع مستخدميه من الدخول، ولا يمسّ صفقاته الجارية.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('tenant', 'معرّف مستأجر المستورد.')],
                    'requestBody' => Spec::optionalBody([
                        'reason' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                    ]),
                    'responses' => [
                        '200' => Spec::message('قُلبت الحالة.', ['status' => ['type' => 'string', 'description' => 'الحالة بعد القلب.']]),
                    ],
                ],
            ],

            /* ---------- الكتالوج ---------- */

            '/admin/products' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'كتالوج كل المستوردين',
                    'description' => 'منتجات المنصة كلّها بكلّ حالاتها، مع اسم مستورد كلٍّ منها — فالإدارة تتجاوز العزل حين تراقب. وهو ما تُضبط به البيانات الخاطئة والمعروضات غير اللائقة.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => Spec::dataTableParams(['id', 'sku', 'brand', 'status', 'price', 'created_at'], ['status', 'supplier']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object']), 'صفحة من الكتالوج.'),
                    ],
                ],
            ],

            '/admin/products/{product}' => [
                'patch' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تغيير حالة منتج',
                    'description' => 'تُنزل الإدارة منتجاً عن العرض (`archived`) أو تعيده. و`reason` يُسجَّل في التدقيق: سلطةٌ على كتالوج غيرك تحتاج أثراً.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('product', 'معرّف المنتج.')],
                    'requestBody' => Spec::body([
                        'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'out_of_stock', 'archived']],
                        'reason' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true, 'description' => 'يُسجَّل في التدقيق.'],
                    ], ['status']),
                    'responses' => [
                        '200' => Spec::message('تغيّرت الحالة.', ['status' => ['type' => 'string']]),
                    ],
                ],
                'delete' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'حذف منتج',
                    'description' => 'حذفٌ ناعم كحذف صاحبه: يختفي ويبقى مرتبطاً بما مضى من صفقات. و`reason` يُسجَّل في التدقيق.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('product', 'معرّف المنتج.')],
                    'requestBody' => Spec::optionalBody([
                        'reason' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                    ]),
                    'responses' => [
                        '200' => Spec::message('حُذف المنتج.'),
                    ],
                ],
            ],

            /* ---------- المستخدمون ---------- */

            '/admin/users' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'كل المستخدمين',
                    'description' => 'مستخدمو المنصة كلّهم مع شركاتهم وأدوارها وحالاتهم. ومنه يُعرَف من يملك الوصول إلى ماذا.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => Spec::dataTableParams(['id', 'name', 'email', 'status', 'created_at'], ['name', 'phone', 'status', 'tenant', 'type']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object']), 'صفحة من المستخدمين.'),
                    ],
                ],
            ],

            '/admin/tenants' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'كل الشركات',
                    'description' => 'المستأجرون كلّهم مرتَّبين بالنوع ثمّ الاسم، بلا تصفّح. تملأ قوائم التصفية في شاشات الإدارة.',
                    'x-roles' => [Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'data' => Spec::arrayOf(Spec::object([
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                            ])),
                        ]), 'الشركات.'),
                    ],
                ],
            ],

            '/admin/users/{user}' => [
                'patch' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعديل مستخدم',
                    'description' => <<<'MD'
                        الاسم والهاتف والحالة. و**الإيقاف يُنهي جلسات صاحبه
                        فوراً**: حسابٌ موقوف ورمزٌ يعمل تعارضٌ لا معنى له.

                        ولا يستطيع المدير **إيقاف نفسه** من هنا — يعود ٤٢٢: من
                        أوقف نفسه أغلق الباب وأبقى المفتاح في الداخل. وحسابه
                        بابه صفحة حسابه.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('user', 'معرّف المستخدم.')],
                    'requestBody' => Spec::optionalBody([
                        'name' => ['type' => 'string', 'maxLength' => 191],
                        'phone' => ['type' => 'string', 'maxLength' => 30, 'nullable' => true],
                        'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'suspended']],
                    ]),
                    'responses' => [
                        '200' => Spec::message('حُفظ المستخدم.', ['user' => ['type' => 'object']]),
                    ],
                ],
            ],

            '/admin/users/{user}/password' => [
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعيين كلمة مرور لمستخدم',
                    'description' => <<<'MD'
                        لمن فقد كلمته ولا يستطيع استعادتها. **ولا تُطلَب كلمته
                        القديمة** — الإدارة لا تعرفها ولا ينبغي أن تعرفها.

                        والتعيين **يُنهي كلّ جلسات المستخدم**: من تغيّرت كلمته
                        يبدأ من جديد.

                        ولا يستطيع المدير تطبيقها **على نفسه** من هنا: بابه
                        `me/password` حيث تُطلَب كلمته الحالية.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('user', 'معرّف المستخدم.')],
                    'requestBody' => Spec::body([
                        'password' => ['type' => 'string', 'minLength' => 8, 'format' => 'password'],
                        'password_confirmation' => ['type' => 'string', 'format' => 'password'],
                    ], ['password']),
                    'responses' => [
                        '200' => Spec::message('تغيّرت الكلمة وأُنهيت جلساته.'),
                    ],
                ],
            ],

            /* ---------- الصفقات ---------- */

            '/admin/quotations' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'كل عروض الأسعار',
                    'description' => 'عروض المنصة كلّها بطرفيها وحالتها، **ومع كلٍّ من وقّع**: المستورد، والمشتري، أو لا أحد. وهو ما يُراجَع عند الخلاف على ما اتُّفق عليه.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => Spec::dataTableParams(['id', 'quotation_no', 'status', 'total', 'created_at'], ['quotation_no', 'supplier', 'buyer', 'total', 'status']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object']), 'صفحة من العروض.'),
                    ],
                ],
            ],

            '/admin/orders' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'كل أوامر الشراء',
                    'description' => 'أوامر المنصة كلّها بطرفيها ومجاميعها وعمولتها وحالتي تنفيذها وسدادها.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => Spec::dataTableParams(['id', 'order_no', 'status', 'payment_status', 'total', 'created_at'], ['order_no', 'supplier', 'buyer', 'total', 'status']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object']), 'صفحة من الأوامر.'),
                    ],
                ],
            ],

            '/admin/orders/{order}/status' => [
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تصحيح حالة أمر شراء',
                    'description' => <<<'MD'
                        للعُقَد: مستوردٌ ترك طلباً معلّقاً، أو حالةٌ تخلّفت عن
                        الواقع.

                        والتصحيح يمرّ **بخدمة التنفيذ نفسها** التي يمرّ بها
                        المستورد: فحقن الحالة من باب الإدارة لا يتجاوز المخزون
                        ولا القيود — وإلّا صار الدفتر يقول غير ما حدث. ولذلك
                        رفضُ التسليم لنقص الرصيد يقع هنا أيضاً، بـ٤٢٢
                        و`shortages`.

                        والفعل يُسجَّل في التدقيق بالحالة قبله وبعده.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('order', 'معرّف الطلب.')],
                    'requestBody' => Spec::body([
                        'status' => ['type' => 'string', 'enum' => ['in_production', 'shipped', 'delivered', 'closed', 'cancelled']],
                        'note' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true, 'description' => 'سبب التصحيح — يُسجَّل في التدقيق.'],
                    ], ['status']),
                    'responses' => [
                        '200' => Spec::message('تغيّرت الحالة.', ['status' => ['type' => 'string']]),
                        '422' => [
                            'description' => 'انتقالٌ غير مسموح، أو رصيدٌ غير كافٍ للتسليم.',
                            'content' => ['application/json' => ['schema' => Spec::object([
                                'message' => ['type' => 'string'],
                                'shortages' => Spec::arrayOf(['type' => 'object']),
                            ])]],
                        ],
                    ],
                ],
            ],

            /* ---------- الأصناف ---------- */

            '/admin/categories' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'شجرة الأصناف للإدارة',
                    'description' => 'الأصناف كلّها — **المعطَّلة معها**، بخلاف `categories` العامّ. ومع كلٍّ عدّاد منتجاته ومستورديه، فيُعرَف أيّ صنفٍ يمكن حذفه.',
                    'x-roles' => [Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::arrayOf(['type' => 'object'])]), 'الشجرة كاملة.'),
                    ],
                ],
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'صنف جديد',
                    'description' => <<<'MD'
                        الأصناف **عامّة للمنصة** لا لمستأجر: المستورد يختار منها
                        ولا يضيف إليها — وإلّا تشعّبت الشجرة حتى لا يجد المشتري
                        شيئاً.

                        والسبيكة تُولَّد من الاسم، وبها يُطلَب الصنف في المسارات
                        العامّة.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'requestBody' => Spec::body(self::categoryFields(), ['name']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'id' => ['type' => 'integer'],
                        ]), 'أُضيف الصنف.'),
                    ],
                ],
            ],

            '/admin/categories/{category}' => [
                'put' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعديل صنف',
                    'description' => 'تعديلٌ جزئيّ. **الأب لا يتغيّر** بعد الإنشاء — `parent_id` يُتجاهل هنا. و`is_active: false` تعطيل: يختفي من الاختيار ويبقى على منتجاته — فلا تُفقَد منتجاتٌ لأنّ صنفها أُلغي.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('category', 'معرّف الصنف.')],
                    'requestBody' => Spec::optionalBody(self::categoryFields()),
                    'responses' => [
                        '200' => Spec::message('حُفظ الصنف.'),
                    ],
                ],
                'delete' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'حذف صنف',
                    'description' => '**مشروطٌ بخلوّه**: صنفٌ فيه منتجاتٌ أو أبناءٌ أو مستوردون مسجَّلون لا يُحذَف، ويعود ٤٢٢ بسببه — والبديل تعطيله.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('category', 'معرّف الصنف.')],
                    'responses' => [
                        '200' => Spec::message('حُذف الصنف.'),
                    ],
                ],
            ],

            '/admin/categories/{category}/image' => [
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'رفع صورة صنف',
                    'description' => 'صورة بطاقة الصنف في الصفحة الرئيسية، حتى ٤ ميغابايت (JPG، PNG، WEBP). يُبنى معها مصغّر للبطاقة، والأصل يبقى. وتديرها الإدارة لأنّ الأصناف عامّة.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('category', 'معرّف الصنف.')],
                    'requestBody' => Spec::form([
                        'image' => Spec::file('صورة، حتى 4096 كيلوبايت.'),
                    ], ['image']),
                    'responses' => [
                        '200' => Spec::message('رُفعت الصورة.', [
                            'image_url' => ['type' => 'string'],
                            'image_thumb_url' => ['type' => 'string', 'nullable' => true],
                        ]),
                    ],
                ],
                'delete' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'حذف صورة صنف',
                    'description' => 'تعود البطاقة إلى أيقونة الصنف.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('category', 'معرّف الصنف.')],
                    'responses' => [
                        '200' => Spec::message('حُذفت الصورة.'),
                    ],
                ],
            ],

            /* ---------- بنرات الدعاية ---------- */

            '/admin/banners' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'بنرات الدعاية للإدارة',
                    'description' => 'البنرات كلها — المعطَّلة معها — بترجمتي النص والصورة والرابط والترتيب.',
                    'x-roles' => [Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::arrayOf(['type' => 'object'])]), 'قائمة البنرات.'),
                    ],
                ],
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'بنر جديد',
                    'description' => 'العنوان بالعربية إلزامي. والرابط مسار داخل المنصة يبدأ بـ `/` وليس عنواناً خارجياً. والصورة JPG أو PNG أو WEBP حتى ٤ ميغابايت.',
                    'x-roles' => [Spec::PLATFORM],
                    'requestBody' => Spec::form([
                        'eyebrow' => Spec::translatable('80'),
                        'title' => Spec::translatable('160'),
                        'text' => Spec::translatable('300'),
                        'href' => ['type' => 'string', 'maxLength' => 255, 'description' => 'مسار داخلي، مثل `/products?category=auto-parts`.'],
                        'sort_order' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999, 'nullable' => true],
                        'is_active' => ['type' => 'boolean', 'nullable' => true],
                        'image' => Spec::file('صورة البنر، حتى 4096 كيلوبايت.'),
                    ], ['title', 'href', 'image']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'id' => ['type' => 'integer'],
                        ]), 'أُضيف البنر.'),
                    ],
                ],
            ],

            '/admin/banners/{banner}' => [
                'put' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعديل بنر',
                    'description' => 'تعديل النص والرابط والترتيب والتفعيل. الصورة تُستبدل من مسارها الخاص.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('banner', 'معرّف البنر.')],
                    'requestBody' => Spec::optionalBody([
                        'eyebrow' => Spec::translatable('80'),
                        'title' => Spec::translatable('160'),
                        'text' => Spec::translatable('300'),
                        'href' => ['type' => 'string', 'maxLength' => 255],
                        'sort_order' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999, 'nullable' => true],
                        'is_active' => ['type' => 'boolean', 'nullable' => true],
                    ]),
                    'responses' => [
                        '200' => Spec::message('حُفظ البنر.'),
                    ],
                ],
                'delete' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'حذف بنر',
                    'description' => 'يحذف البنر وصورته. لإخفائه مؤقتاً عطّله بدل الحذف.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('banner', 'معرّف البنر.')],
                    'responses' => [
                        '200' => Spec::message('حُذف البنر.'),
                    ],
                ],
            ],

            '/admin/banners/{banner}/image' => [
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'استبدال صورة بنر',
                    'description' => 'صورة جديدة حتى ٤ ميغابايت، والصورة السابقة تُحذف.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('banner', 'معرّف البنر.')],
                    'requestBody' => Spec::form([
                        'image' => Spec::file('صورة البنر، حتى 4096 كيلوبايت.'),
                    ], ['image']),
                    'responses' => [
                        '200' => Spec::message('رُفعت الصورة.', ['image_url' => ['type' => 'string']]),
                    ],
                ],
            ],

            /* ---------- وحدات القياس ---------- */

            '/admin/units' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'وحدات القياس للإدارة',
                    'description' => 'الوحدات كلها — المعطَّلة معها — ومع كلٍّ ترجمتا اسمها. الرمز لا يتغيّر بعد الإنشاء.',
                    'x-roles' => [Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::arrayOf(['type' => 'object'])]), 'قائمة الوحدات.'),
                    ],
                ],
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'وحدة جديدة',
                    'description' => 'الرمز لاتينيّ ثابت (`piece`). والاسم بالعربية والإنجليزية. وحدةٌ بلا ترجمة إنجليزية تأخذ العربية.',
                    'x-roles' => [Spec::PLATFORM],
                    'requestBody' => Spec::body([
                        'code' => ['type' => 'string', 'maxLength' => 20, 'description' => 'أحرف لاتينية، ويبدأ بحرف.'],
                        'name' => Spec::translatable('80'),
                        'sort_order' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999, 'nullable' => true],
                        'is_active' => ['type' => 'boolean', 'nullable' => true],
                    ], ['code', 'name']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'id' => ['type' => 'integer'],
                        ]), 'أُضيفت الوحدة.'),
                    ],
                ],
            ],

            '/admin/units/{unit}' => [
                'put' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعديل وحدة',
                    'description' => 'تعديل الاسم والترتيب والتفعيل. **الرمز لا يتغيّر** — المنتجات تحفظه.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('unit', 'معرّف الوحدة.')],
                    'requestBody' => Spec::optionalBody([
                        'name' => Spec::translatable('80'),
                        'sort_order' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999, 'nullable' => true],
                        'is_active' => ['type' => 'boolean', 'nullable' => true],
                    ]),
                    'responses' => [
                        '200' => Spec::message('حُفظت الوحدة.'),
                    ],
                ],
                'delete' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'حذف وحدة',
                    'description' => 'يُرفض إن كان الرمز مستخدماً في منتج أو طلب أو عرض أو أمر شراء. البديل تعطيلها.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('unit', 'معرّف الوحدة.')],
                    'responses' => [
                        '200' => Spec::message('حُذفت الوحدة.'),
                    ],
                ],
            ],

            /* ---------- رسائل العميل ---------- */

            '/admin/email-templates' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'رسائل البريد للعميل',
                    'description' => <<<'MD'
                        رسائل العميل كلها: الحساب، وطلب السعر وعروضه، والطلب
                        والشحنة والمرتجع والنزاع والرسالة. كلٌّ منها مفعّلة أو
                        متوقفة، وبموضوع ونص بالعربية والإنجليزية. الإرسال يتبع
                        لغة حساب العميل، والشعار والإطار ثابتان.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::arrayOf(['type' => 'object'])]), 'قائمة الرسائل.'),
                    ],
                ],
            ],

            '/admin/email-templates/{emailTemplate}' => [
                'put' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعديل رسالة بريد',
                    'description' => <<<'MD'
                        يحفظ التفعيل والموضوع والنص باللغتين. المفتاح مفتاح
                        رسالة موجودة، لا يُنشأ مفتاح جديد. الوسوم غير المسموحة
                        في النص تُحذف. العناصر `{{name}}` و`{{email}}` تُستبدل
                        عند الإرسال.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('emailTemplate', 'مفتاح رسالة موجودة، مثل login أو order.', 'string')],
                    'requestBody' => Spec::body([
                        'enabled' => ['type' => 'boolean'],
                        'subject' => Spec::translatable('191'),
                        'body' => Spec::translatable('20000'),
                    ], ['enabled', 'subject', 'body']),
                    'responses' => [
                        '200' => Spec::message('حُفظت الرسالة.'),
                    ],
                ],
            ],

            /* ---------- العمولة والخطط ---------- */

            '/admin/commission-rules' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'قواعد العمولة',
                    'description' => <<<'MD'
                        القواعد مرتَّبةً كما تُطبَّق: **الأخصّ يغلب**. قاعدةٌ
                        لمستوردٍ بعينه تسبق قاعدة صنفه، وهي تسبق قاعدة خطّته،
                        وهي تسبق القاعدة العامّة — فيُتفَّق مع مستوردٍ كبير على
                        نسبةٍ خاصّة بلا استثناءٍ مكتوبٍ في الشيفرة.

                        وعند تساوي النطاق يغلب الأعلى `priority`.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('rules', ['type' => 'object']), 'القواعد بترتيب تطبيقها.'),
                    ],
                ],
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'قاعدة عمولة جديدة',
                    'description' => 'نسبةٌ أو مبلغٌ ثابت أو سلّم درجات، على نطاقٍ تختاره. و`accrual_trigger` يحدّد لحظة الاستحقاق: عند التأكيد، أو الشحن، أو التسليم.',
                    'x-roles' => [Spec::PLATFORM],
                    'requestBody' => Spec::body(self::commissionFields(), ['scope', 'type']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['rule' => ['type' => 'object']]), 'أُضيفت القاعدة.'),
                    ],
                ],
            ],

            '/admin/commission-rules/{commissionRule}' => [
                'put' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعديل قاعدة عمولة',
                    'description' => 'تعديلٌ جزئيّ. ولا يمسّ ما استُحقّ من قبل: العمولة تُحسَب بالقاعدة السارية **لحظة الاستحقاق** وتُثبَّت، فتعديل القاعدة اليوم لا يُعيد تسعير صفقات أمس.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('commissionRule', 'معرّف القاعدة.')],
                    'requestBody' => Spec::optionalBody(self::commissionFields()),
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['rule' => ['type' => 'object']]), 'القاعدة بعد الحفظ.'),
                    ],
                ],
                'delete' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'حذف قاعدة عمولة',
                    'description' => 'تُحذَف فيرجع ما كانت تغطّيه إلى القاعدة الأعمّ. وحذف القاعدة العامّة يُسقط الحساب إلى النسبة الافتراضية في `config/b2b.php`.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('commissionRule', 'معرّف القاعدة.')],
                    'responses' => [
                        '200' => Spec::message('حُذفت القاعدة.'),
                    ],
                ],
            ],

            '/admin/plans' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'خطط الاشتراك',
                    'description' => 'الخطط وأسعارها الشهرية والسنوية وحدودها: عدد المنتجات، وعدد المستخدمين، وحسم العمولة، وهل المحاسبة مفتوحة فيها.',
                    'x-roles' => [Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::listing('plans', ['type' => 'object']), 'الخطط.'),
                    ],
                ],
            ],

            '/admin/plans/{plan}' => [
                'put' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعديل خطة',
                    'description' => 'تعديلٌ جزئيّ. و`product_limit` فارغاً يعني بلا حدّ. و`commission_discount` نسبةٌ تُحسَم من عمولة مشتركي الخطة.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('plan', 'معرّف الخطة.')],
                    'requestBody' => Spec::optionalBody([
                        'name' => Spec::translatable('191'),
                        'price_monthly' => Spec::minor('السعر الشهري'),
                        'price_yearly' => Spec::minor('السعر السنوي'),
                        'product_limit' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true, 'description' => 'فارغاً: بلا حدّ.'],
                        'user_limit' => ['type' => 'integer', 'minimum' => 1],
                        'commission_discount' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'نسبة الحسم من العمولة.'],
                        'accounting_enabled' => ['type' => 'boolean'],
                        'is_active' => ['type' => 'boolean'],
                    ]),
                    'responses' => [
                        '200' => Spec::message('حُدّثت الخطة.'),
                    ],
                ],
            ],

            '/admin/subscriptions' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'الاشتراكات الجارية',
                    'description' => 'من مشتركٌ في ماذا، ومتى يبدأ وينتهي، وبأيّ سعر. ومنه يُقرأ الإيراد المتكرّر في اللوحة.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => Spec::dataTableParams(['id', 'amount', 'status', 'billing_cycle', 'starts_at', 'ends_at'], ['status', 'tenant']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object']), 'صفحة من الاشتراكات.'),
                    ],
                ],
            ],

            /* ---------- المركز المالي ---------- */

            '/admin/finance/commissions' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'عمولات المنصة',
                    'description' => 'كلّ عمولة بطلبها ومستوردها ومبلغها وحالتها: مستحقّة أم محصَّلة. وهي دفتر ما **للمنصة على مستورديها**.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => Spec::dataTableParams(['id', 'amount', 'base_amount', 'rate', 'status', 'accrued_at', 'settled_at'], ['status', 'supplier', 'order_no']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object']), 'صفحة من العمولات.'),
                    ],
                ],
            ],

            '/admin/finance/commission-aging' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'أعمار العمولات المستحقّة',
                    'description' => 'ما لم يُحصَّل، موزَّعاً على سلال التأخّر، ومجموعاً **لكلّ مستورد**. ومنه تُعرَف المطالبة التي تستحقّ اتّصالاً اليوم.',
                    'x-roles' => [Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'buckets' => ['type' => 'object', 'description' => 'سلّة التأخّر ⟵ مجموعها.', 'additionalProperties' => ['type' => 'integer']],
                            'total' => ['type' => 'integer'],
                            'by_supplier' => Spec::arrayOf(['type' => 'object'], ['description' => 'التوزيع نفسه لكلّ مستورد.']),
                        ]), 'الأعمار.'),
                    ],
                ],
            ],

            '/admin/finance/commissions/{commission}/settle' => [
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تحصيل عمولة',
                    'description' => '**يُولّد القيدين معاً**: في دفتر المنصة (تحصيل) وفي دفتر المستورد (سداد). فالعمولة الواحدة ترحيلان في دفترين، لا رقمٌ في جدول — وبذلك يطابق الدفتران بعضهما دائماً.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('commission', 'معرّف العمولة.')],
                    'responses' => [
                        '200' => Spec::message('سُجّل التحصيل ورُحِّل القيدان.', ['commission' => ['type' => 'object']]),
                    ],
                ],
            ],

            '/admin/finance/suppliers/{tenant}/settle' => [
                'post' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تحصيل كل عمولات مستورد',
                    'description' => 'تحصيلٌ جماعيّ: مستوردٌ يسدّد عمولات شهرٍ بحوالةٍ واحدة، فتُحصَّل كلّها في فعلٍ واحد بقيودها. ويعيد عدد ما حُصِّل ومجموعه.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('tenant', 'معرّف مستأجر المستورد.')],
                    'responses' => [
                        '200' => Spec::message('حُصِّلت العمولات.', [
                            'count' => ['type' => 'integer'],
                            'total' => ['type' => 'integer'],
                        ]),
                    ],
                ],
            ],

            /* ---------- الصندوق والتدقيق ---------- */

            '/admin/contact-messages' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'صندوق رسائل التواصل',
                    'description' => 'ما يصل من نموذج صفحة «عن المنصة»: سؤال زائرٍ لم يسجّل، أو شكوى مستوردٍ من المنصة نفسها. وهي ليست محادثاتٍ تجارية — تلك في `conversations` — فلها صندوقها هنا، ويبقى الردّ على بريد المرسِل.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [
                        Spec::queryEnum('status', 'حصرها بحالة.', ['new', 'read', 'archived']),
                        ...Spec::pagination(20, 100),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::page(Spec::object([
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'email' => ['type' => 'string'],
                            'phone' => ['type' => 'string', 'nullable' => true],
                            'subject' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['new', 'read', 'archived']],
                            'sender' => ['type' => 'string', 'nullable' => true, 'description' => 'شركة المرسِل إن كان مسجَّلاً.'],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        ])), 'صفحة من الصندوق.'),
                    ],
                ],
            ],

            '/admin/contact-messages/{contactMessage}' => [
                'patch' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'تعليم رسالة أو أرشفتها',
                    'description' => 'الحالة وحدها: مقروءة، أو مؤرشفة. ولا حذف — رسالةٌ وصلت تبقى.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('contactMessage', 'معرّف الرسالة.')],
                    'requestBody' => Spec::body([
                        'status' => ['type' => 'string', 'enum' => ['new', 'read', 'archived']],
                    ], ['status']),
                    'responses' => [
                        '200' => Spec::message('حُدّثت الرسالة.', ['status' => ['type' => 'string']]),
                    ],
                ],
            ],

            '/admin/audit-logs' => [
                'get' => [
                    'tags' => ['إدارة المنصة'],
                    'summary' => 'سجل التدقيق',
                    'description' => <<<'MD'
                        كلّ إجراءٍ إداريّ: الفعل، وفاعله، وهدفه، وعنوان شبكته،
                        و**قيمه قبل وبعد**.

                        وهو **للقراءة فقط**: لا يُكتَب فيه من الواجهة ولا يُحذَف
                        منه — سجلٌّ يُمحى منه ليس سجلّاً. والكتابة فيه تقع من
                        الأفعال نفسها.
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => Spec::dataTableParams(['id', 'action', 'created_at'], ['action', 'user', 'ip_address', 'created_at']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(Spec::object([
                            'id' => ['type' => 'integer'],
                            'action' => ['type' => 'string'],
                            'user' => Spec::object([
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                            ]) + ['nullable' => true],
                            'target' => ['type' => 'string', 'nullable' => true, 'description' => 'ما وقع عليه الفعل.'],
                            'ip' => ['type' => 'string', 'nullable' => true],
                            'before' => ['type' => 'object', 'nullable' => true],
                            'after' => ['type' => 'object', 'nullable' => true],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        ])), 'صفحة من السجلّ.'),
                    ],
                ],
            ],
        ];
    }
}
