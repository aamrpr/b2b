<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/**
 * المحاسبة: دفترٌ بالقيد المزدوج لكلّ مستأجر.
 *
 * والمسارات نفسها تخدم دفتر المستورد ودفتر المنصة: الهوية هي ما يحدّد
 * أيّ دفترٍ يُقرأ، فلا مسار إداريّ موازٍ.
 */
final class Accounting
{
    public static function paths(): array
    {
        return [
            '/accounting/accounts' => [
                'get' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'دليل الحسابات',
                    'description' => 'حسابات دفترك شجرةً، كلٌّ برمزه ونوعه ورصيده. ويُنشأ الدليل مع الحساب من قالبٍ جاهز، فلا يبدأ أحدٌ من ورقةٍ بيضاء.',
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string', 'description' => 'عملة الدفتر.'],
                            'accounts' => Spec::arrayOf(Spec::object([
                                'id' => ['type' => 'integer'],
                                'parent_id' => ['type' => 'integer', 'nullable' => true],
                                'code' => ['type' => 'string', 'description' => 'رمز الحساب.'],
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string', 'enum' => ['asset', 'liability', 'equity', 'revenue', 'expense']],
                                'balance' => ['type' => 'integer'],
                            ])),
                        ]), 'الدليل.'),
                    ],
                ],
                'post' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'حساب جديد',
                    'description' => 'لما لا يغطّيه القالب. و`parent_id` يضعه في الشجرة، و`type` يحدّد جهة رصيده: الأصول والمصروفات مدينة، وما سواها دائنة.',
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'requestBody' => Spec::body([
                        'parent_id' => ['type' => 'integer', 'nullable' => true],
                        'code' => ['type' => 'string', 'maxLength' => 20],
                        'name' => Spec::translatable('191'),
                        'type' => ['type' => 'string', 'enum' => ['asset', 'liability', 'equity', 'revenue', 'expense']],
                    ], ['code', 'name', 'type']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['account' => ['type' => 'object']]), 'أُنشئ الحساب.'),
                    ],
                ],
            ],

            '/accounting/journal' => [
                'get' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'دفتر اليومية',
                    'description' => <<<'MD'
                        كلّ قيدٍ بسطوره ومصدره، أحدثه أوّلاً.

                        وأكثر القيود **يُولّدها النظام** لا الإنسان: الفاتورة،
                        والتسليم، والدفعة، والمرتجع، والعمولة. والمصدر مذكورٌ مع
                        كلّ قيد، فيُعرَف أيّ عمليةٍ ولّدته.
                        MD,
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'parameters' => [
                        Spec::queryEnum('status', 'حصرها بحالة.', ['draft', 'posted', 'reversed']),
                        Spec::query('from', 'من تاريخ.', ['type' => 'string', 'format' => 'date']),
                        Spec::query('to', 'إلى تاريخ.', ['type' => 'string', 'format' => 'date']),
                        ...Spec::pagination(25, 100),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::page(Spec::ref('JournalEntry')), 'صفحة من اليومية.'),
                    ],
                ],
                'post' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'قيد يدوي',
                    'description' => <<<'MD'
                        للتسويات التي لا يولّدها النظام: إهلاك، مصروفٌ نقديّ،
                        تسوية افتتاحية.

                        و**القيد لا يُرحَّل إلّا متوازناً**: مجموع المدين يساوي
                        مجموع الدائن، وسطران على الأقلّ. وغير المتوازن مرفوضٌ
                        بـ٤٢٢ — لا دفتر يقبل قيداً معلّقاً.

                        و`lines.*.account` **رمز الحساب** من الدليل لا معرّفه:
                        الرمز ثابتٌ يُعرَف، والمعرّف يختلف بين دفترٍ ودفتر.
                        MD,
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'requestBody' => Spec::body([
                        'entry_date' => ['type' => 'string', 'format' => 'date'],
                        'description' => ['type' => 'string', 'maxLength' => 500, 'description' => 'بيان القيد.'],
                        'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true],
                        'lines' => Spec::arrayOf(Spec::object([
                            'account' => ['type' => 'string', 'description' => 'رمز الحساب من الدليل.'],
                            'debit' => Spec::minor('المدين') + ['nullable' => true],
                            'credit' => Spec::minor('الدائن') + ['nullable' => true],
                            'description' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
                        ], ['account']), ['minItems' => 2, 'description' => 'سطران على الأقلّ، ومجموع المدين = مجموع الدائن.']),
                    ], ['entry_date', 'description', 'lines']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['entry' => Spec::ref('JournalEntry')]), 'رُحِّل القيد.'),
                    ],
                ],
            ],

            '/accounting/journal/{journalEntry}' => [
                'get' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'قيد واحد',
                    'description' => 'القيد بسطوره وحساباتها ومصدره.',
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'parameters' => [Spec::path('journalEntry', 'معرّف القيد.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::ref('JournalEntry'), 'القيد.'),
                    ],
                ],
            ],

            '/accounting/journal/{journalEntry}/reverse' => [
                'post' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'عكس قيد',
                    'description' => <<<'MD'
                        **التصحيح الوحيد المسموح به.** المُرحَّل لا يُعدَّل ولا
                        يُحذَف: يُنشأ قيدٌ مقابلٌ يُلغي أثره، ويبقى الأصل والعكس
                        كلاهما في الدفتر.

                        وهذا ليس تزمّتاً: دفترٌ تُمحى منه السطور ليس دفتراً — ومن
                        راجع الحساب بعد سنةٍ يحتاج أن يرى الخطأ وتصحيحه معاً.

                        والقيد المعكوس من قبل لا يُعكَس مرّتين.
                        MD,
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'parameters' => [Spec::path('journalEntry', 'معرّف القيد الأصل.')],
                    'requestBody' => Spec::optionalBody([
                        'reason' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true, 'description' => 'سبب العكس — يُكتب في بيان القيد العكسيّ.'],
                    ]),
                    'responses' => [
                        '200' => Spec::message('أُنشئ القيد العكسيّ.', ['entry' => Spec::ref('JournalEntry')]),
                    ],
                ],
            ],

            '/accounting/reports/trial-balance' => [
                'get' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'ميزان المراجعة',
                    'description' => 'لكلّ حسابٍ مدينُه ودائنُه ورصيدُه في المدّة. ومجموع المدين يساوي مجموع الدائن — وإلّا فثمّة خلل، ولذلك هو أوّل ما يُقرأ.',
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'parameters' => [
                        Spec::query('from', 'من تاريخ. الافتراضي بداية الشهر.', ['type' => 'string', 'format' => 'date']),
                        Spec::query('to', 'إلى تاريخ. الافتراضي اليوم.', ['type' => 'string', 'format' => 'date']),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string'],
                            'from' => ['type' => 'string', 'format' => 'date'],
                            'to' => ['type' => 'string', 'format' => 'date'],
                            'rows' => Spec::arrayOf(Spec::object([
                                'code' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'debit' => ['type' => 'integer'],
                                'credit' => ['type' => 'integer'],
                                'balance' => ['type' => 'integer'],
                            ])),
                            'totals' => Spec::object([
                                'debit' => ['type' => 'integer'],
                                'credit' => ['type' => 'integer'],
                            ]) + ['description' => 'المجموعان — ويجب أن يتساويا.'],
                        ]), 'الميزان.'),
                    ],
                ],
            ],

            '/accounting/reports/income-statement' => [
                'get' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'قائمة الدخل',
                    'description' => 'الإيراد، وتكلفة المبيعات، والربح الإجمالي، والمصروفات، وصافي الربح في المدّة. وتكلفة المبيعات تأتي من تكلفة المخزون الخارج عند كلّ تسليم — لا من تقديرٍ في نهاية المدّة.',
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'parameters' => [
                        Spec::query('from', 'من تاريخ.', ['type' => 'string', 'format' => 'date']),
                        Spec::query('to', 'إلى تاريخ.', ['type' => 'string', 'format' => 'date']),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string'],
                            'revenue' => ['type' => 'integer'],
                            'cost_of_sales' => ['type' => 'integer'],
                            'gross_profit' => ['type' => 'integer'],
                            'expenses' => ['type' => 'integer'],
                            'net_profit' => ['type' => 'integer'],
                            'lines' => Spec::arrayOf(['type' => 'object'], ['description' => 'تفصيل الحسابات تحت كلّ بند.']),
                        ]), 'القائمة.'),
                    ],
                ],
            ],

            '/accounting/reports/balance-sheet' => [
                'get' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'قائمة المركز المالي',
                    'description' => 'الأصول والخصوم وحقوق الملكية في **لحظة** لا في مدّة — ولذلك معاملها `as_of` لا `from`/`to`. والأصول تساوي الخصوم وحقوق الملكية، و`is_balanced` تقول ذلك.',
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'parameters' => [
                        Spec::query('as_of', 'اللحظة. الافتراضي اليوم.', ['type' => 'string', 'format' => 'date']),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string'],
                            'as_of' => ['type' => 'string', 'format' => 'date'],
                            'assets' => ['type' => 'integer'],
                            'liabilities' => ['type' => 'integer'],
                            'equity' => ['type' => 'integer'],
                            'is_balanced' => ['type' => 'boolean', 'description' => 'الأصول = الخصوم + حقوق الملكية.'],
                            'lines' => Spec::arrayOf(['type' => 'object']),
                        ]), 'القائمة.'),
                    ],
                ],
            ],

            '/accounting/reports/receivables-aging' => [
                'get' => [
                    'tags' => ['المحاسبة'],
                    'summary' => 'أعمار الذمم',
                    'description' => 'ما لك على عملائك، موزَّعاً على سلال التأخّر: جارٍ، ١–٣٠، ٣١–٦٠، ٦١–٩٠، وأكثر من ٩٠ يوماً. ومنه يُعرَف أيّ دَينٍ يحتاج مطالبةً اليوم.',
                    'x-roles' => [Spec::SUPPLIER, Spec::PLATFORM],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string'],
                            'buckets' => ['type' => 'object', 'description' => 'سلّة التأخّر ⟵ مجموعها.', 'additionalProperties' => ['type' => 'integer']],
                            'total' => ['type' => 'integer'],
                            'by_customer' => Spec::arrayOf(['type' => 'object'], ['description' => 'التوزيع نفسه لكلّ عميل.']),
                        ]), 'الأعمار.'),
                    ],
                ],
            ],
        ];
    }
}
