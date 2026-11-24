<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** المدفوعات: التحصيل والصرف والتوزيع وإشعارات الائتمان. */
final class Payments
{
    private const METHODS = ['cash', 'bank', 'gateway', 'wallet', 'hawala', 'cheque'];

    /** حقول الدفعة — واحدة للتحصيل وللصرف، كما في المتحكّم. */
    private static function paymentFields(): array
    {
        return [
            'contact_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'معرّف مستأجر الطرف الآخر — العميل في التحصيل، والمورّد في الصرف.'],
            'method' => ['type' => 'string', 'enum' => self::METHODS, 'description' => '`hawala` الحوالة: طريقةٌ أساسية في السوق السوري لا خياراً ثانوياً.'],
            'amount' => Spec::minor('المبلغ', 1),
            'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true],
            'fee_amount' => Spec::minor('رسوم التحصيل') + ['nullable' => true, 'description' => 'رسوم الحوالة أو البوابة — تُقيَّد مصروفاً لا تُنقَص من الدفعة.'],
            'payment_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
            'gateway_reference' => ['type' => 'string', 'maxLength' => 191, 'nullable' => true, 'description' => 'رقم الحوالة أو مرجع البوابة.'],
            'notes' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
            'allocations' => Spec::arrayOf(Spec::object([
                'invoice_id' => ['type' => 'integer'],
                'amount' => Spec::minor('ما يُوزَّع على هذه الفاتورة', 1),
            ], ['invoice_id', 'amount']), ['nullable' => true, 'description' => 'توزيعٌ يدويّ. اتركه فارغاً ليبقى المبلغ مقدَّماً يُوزَّع لاحقاً.']),
        ];
    }

    public static function paths(): array
    {
        return [
            '/payments' => [
                'get' => [
                    'tags' => ['المدفوعات'],
                    'summary' => 'سجل الدفعات',
                    'description' => <<<'MD'
                        كلّ دفعة بمبلغها، وما وُزّع منها، و**ما بقي مقدَّماً**.

                        والمقدَّم ليس خطأً: عميلٌ يدفع على الحساب قبل أن تصدر
                        فاتورته، فيُسجَّل المبلغ ويُوزَّع حين تصدر.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => Spec::dataTableParams(['id', 'payment_date', 'amount', 'method', 'direction'], ['payment_no', 'direction', 'method', 'payment_date']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object'], [
                            'currency' => ['type' => 'string'],
                        ]), 'صفحة من الدفعات.'),
                    ],
                ],
            ],

            '/payments/outstanding' => [
                'get' => [
                    'tags' => ['المدفوعات'],
                    'summary' => 'الفواتير المستحقّة',
                    'description' => <<<'MD'
                        تملأ شاشة التحصيل: الفواتير غير المسدَّدة **مجموعةً
                        بعميلها**، لكلٍّ إجماليه وأعمار فواتيره.

                        ويعيد معها `available_credit`: رصيد إشعارات الائتمان
                        المتاح — فمن له رصيدٌ لا يُطالَب به مرّتين.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'x-validates' => true,
                    'parameters' => [
                        Spec::query('contact_id', 'حصرها بعميلٍ بعينه.', ['type' => 'integer']),
                        Spec::queryEnum('type', 'فواتير بيعٍ أم شراء.', ['sales', 'purchase']),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string'],
                            'available_credit' => ['type' => 'integer', 'description' => 'رصيد إشعارات الائتمان غير المطبَّق.'],
                            'customers' => Spec::arrayOf(Spec::object([
                                'contact_id' => ['type' => 'integer', 'nullable' => true],
                                'name' => ['type' => 'string', 'nullable' => true],
                                'total_due' => ['type' => 'integer'],
                                'invoices' => Spec::arrayOf(Spec::object([
                                    'id' => ['type' => 'integer'],
                                    'invoice_no' => ['type' => 'string'],
                                    'issue_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                    'due_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                                    'total' => ['type' => 'integer'],
                                    'balance_due' => ['type' => 'integer'],
                                    'days_overdue' => ['type' => 'integer', 'description' => 'صفرٌ إن لم تتجاوز استحقاقها.'],
                                ])),
                            ])),
                        ]), 'المستحقّ، مجموعاً بعميله.'),
                    ],
                ],
            ],

            '/payments/receive' => [
                'post' => [
                    'tags' => ['المدفوعات'],
                    'summary' => 'تسجيل تحصيل',
                    'description' => <<<'MD'
                        دفعةٌ **واردة** من عميل، وقيدها معها.

                        والتوزيع طريقان: `allocations` يدويّ، أو لا توزيع فيبقى
                        المبلغ مقدَّماً. والواجهة تعرض فوقهما زرّ **«الأقدم
                        أوّلاً»** يبني `allocations` من أقدم الفواتير — وهو حساب
                        واجهةٍ لا قاعدة خادم، لأنّ المحصِّل قد يريد غير ذلك.

                        و`fee_amount` رسوم الحوالة أو البوابة: **تُقيَّد مصروفاً**
                        ولا تُنقَص من الدفعة، فالعميل سدّد كلّ ما عليه.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body(self::paymentFields(), ['method', 'amount']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'payment' => ['type' => 'object', 'description' => 'الدفعة بتوزيعاتها.'],
                        ]), 'سُجّل التحصيل.'),
                    ],
                ],
            ],

            '/payments/pay' => [
                'post' => [
                    'tags' => ['المدفوعات'],
                    'summary' => 'تسجيل صرف لمورّد',
                    'description' => 'دفعةٌ **صادرة** إلى مورّد — عكس التحصيل بالحقول نفسها. وتُوزَّع على فواتير المورّد لا على فواتير العملاء.',
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body(self::paymentFields(), ['method', 'amount']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'payment' => ['type' => 'object'],
                        ]), 'سُجّل الصرف.'),
                    ],
                ],
            ],

            '/payments/{payment}' => [
                'get' => [
                    'tags' => ['المدفوعات'],
                    'summary' => 'دفعة واحدة',
                    'description' => 'الدفعة وتوزيعاتها على الفواتير، وما بقي منها مقدَّماً.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('payment', 'معرّف الدفعة.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['payment' => ['type' => 'object']]), 'الدفعة.'),
                    ],
                ],
            ],

            '/payments/{payment}/allocate' => [
                'post' => [
                    'tags' => ['المدفوعات'],
                    'summary' => 'توزيع دفعة',
                    'description' => 'لتوزيع **ما بقي مقدَّماً** بعد أن تصدر الفواتير. والتوزيع فوق الباقي، أو فوق رصيد الفاتورة، مرفوضٌ بـ٤٢٢ — فلا يُوزَّع ما ليس في الدفعة.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('payment', 'معرّف الدفعة.')],
                    'requestBody' => Spec::body([
                        'allocations' => Spec::arrayOf(Spec::object([
                            'invoice_id' => ['type' => 'integer'],
                            'amount' => Spec::minor('ما يُوزَّع', 1),
                        ], ['invoice_id', 'amount']), ['minItems' => 1]),
                    ], ['allocations']),
                    'responses' => [
                        '200' => Spec::message('وُزّعت الدفعة.', ['payment' => ['type' => 'object']]),
                    ],
                ],
            ],

            '/customers/{tenant}/statement' => [
                'get' => [
                    'tags' => ['المدفوعات'],
                    'summary' => 'كشف حساب عميل',
                    'description' => 'كلّ ما بينك وبين هذا العميل في سطرٍ واحد زمنيّ: فواتير، ودفعات، وإشعارات ائتمان، ورصيدٌ متحرّك بعد كلّ سطر. هو ما يُرسَل إليه عند المطالبة.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('tenant', 'معرّف مستأجر العميل.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'currency' => ['type' => 'string'],
                            'opening_balance' => ['type' => 'integer'],
                            'closing_balance' => ['type' => 'integer'],
                            'lines' => Spec::arrayOf(Spec::object([
                                'date' => ['type' => 'string', 'format' => 'date'],
                                'type' => ['type' => 'string', 'description' => 'فاتورة، دفعة، إشعار ائتمان.'],
                                'reference' => ['type' => 'string'],
                                'debit' => ['type' => 'integer'],
                                'credit' => ['type' => 'integer'],
                                'balance' => ['type' => 'integer', 'description' => 'الرصيد بعد هذا السطر.'],
                            ])),
                        ]), 'الكشف.'),
                    ],
                ],
            ],

            '/credit-notes' => [
                'get' => [
                    'tags' => ['المدفوعات'],
                    'summary' => 'إشعارات الائتمان',
                    'description' => 'ما صدر عن المرتجعات: لكلٍّ إجماليه وما طُبِّق منه وما بقي. و`open_only` يترك ما بقي منه رصيدٌ وحده.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [
                        // مقارنةٌ بين عمودين لا قيمةٌ تُصفّى
                        Spec::queryBool('open_only', 'ما بقي منه رصيدٌ غير مطبَّق وحده.'),
                        ...Spec::dataTableParams(['id', 'note_no', 'issue_date', 'total', 'applied_amount', 'status'], ['status']),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(Spec::object([
                            'id' => ['type' => 'integer'],
                            'note_no' => ['type' => 'string'],
                            'issue_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                            'total' => ['type' => 'integer'],
                            'applied' => ['type' => 'integer'],
                            'unapplied' => ['type' => 'integer', 'description' => 'الرصيد القابل للتطبيق.'],
                        ]), [
                            'currency' => ['type' => 'string'],
                        ]), 'صفحة من الإشعارات.'),
                    ],
                ],
            ],

            '/credit-notes/{creditNote}/apply' => [
                'post' => [
                    'tags' => ['المدفوعات'],
                    'summary' => 'تطبيق إشعار ائتمان على فاتورة',
                    'description' => <<<'MD'
                        يُنزل رصيد الإشعار من رصيد فاتورة.

                        و`amount` اختياريّ: بلا مبلغٍ يُطبَّق الأقلّ من الرصيدين
                        — رصيد الإشعار ورصيد الفاتورة — فلا يُطبَّق أكثر مما
                        يحتمل أحدهما.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('creditNote', 'معرّف الإشعار.')],
                    'requestBody' => Spec::body([
                        'invoice_id' => ['type' => 'integer'],
                        'amount' => Spec::minor('ما يُطبَّق', 1) + ['nullable' => true, 'description' => 'اتركه فارغاً ليُطبَّق الأقلّ من الرصيدين.'],
                    ], ['invoice_id']),
                    'responses' => [
                        '200' => Spec::message('طُبِّق الإشعار.', [
                            'applied' => ['type' => 'integer', 'description' => 'ما طُبِّق فعلاً.'],
                            'unapplied' => ['type' => 'integer', 'description' => 'ما بقي في الإشعار.'],
                            'invoice_balance' => ['type' => 'integer', 'description' => 'رصيد الفاتورة بعده.'],
                        ]),
                    ],
                ],
            ],
        ];
    }
}
