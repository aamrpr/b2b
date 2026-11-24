<?php

namespace App\Support\ApiDocs\Groups;

use App\Models\Returns\Dispute;
use App\Models\Returns\ReturnRequest;
use App\Support\ApiDocs\Spec;

/** المرتجعات والنزاعات: الإرجاع، ثمّ الوساطة حين يبقى الخلاف. */
final class Returns
{
    public static function paths(): array
    {
        return [
            '/returns' => [
                'get' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'المرتجعات',
                    'description' => 'مرتجعاتك: ما طلبتَه إن كنت تاجر جملة، وما طُلب منك إن كنت مستورداً.',
                    'x-roles' => [Spec::PARTIES],
                    'parameters' => Spec::dataTableParams(['id', 'reference_no', 'status', 'reason', 'created_at'], ['status', 'reason']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object']), 'صفحة من المرتجعات.'),
                    ],
                ],
            ],

            '/orders/{order}/returnable' => [
                'get' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'ما يُرَدّ من طلب',
                    'description' => <<<'MD'
                        لكلّ بند: ما استُلم، وما رُدّ من قبل، وما يبقى قابلاً
                        للإرجاع. تملأ نموذج المرتجع فلا يُرَدّ ما رُدّ مرّتين.

                        والإرجاع لا يُفتح إلّا على **المسلَّم والمغلق**: ما لم
                        يُستلَم بعد يُلغى لا يُرَدّ.
                        MD,
                    'x-roles' => [Spec::BUYER],
                    'parameters' => [Spec::path('order', 'معرّف الطلب.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'order_no' => ['type' => 'string'],
                            'currency' => ['type' => 'string'],
                            'items' => Spec::arrayOf(Spec::object([
                                'order_item_id' => ['type' => 'integer'],
                                'description' => ['type' => 'string'],
                                'quantity' => ['type' => 'integer', 'description' => 'ما طُلب.'],
                                'returned' => ['type' => 'integer', 'description' => 'ما رُدّ من قبل.'],
                                'returnable' => ['type' => 'integer', 'description' => 'ما يبقى قابلاً للإرجاع.'],
                                'unit_price' => Spec::ref('Money'),
                            ])),
                        ]), 'البنود وأرصدة إرجاعها.'),
                    ],
                ],
            ],

            '/orders/{order}/returns' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'طلب مرتجع',
                    'description' => <<<'MD'
                        تاجر الجملة يطلب الإرجاع: البنود والكميات والسبب، ويُبلَّغ
                        المستورد.

                        و`restock` يقول هل تعود البضاعة إلى المخزون عند
                        الاستلام: تالفٌ لا يعود، وسليمٌ أُرسل بالخطأ يعود.

                        > **ملاحظة:** `type: replacement` مقبولٌ في الإدخال، وما
                        > بعده (إرسال البديل) غير منفَّذ بعد — والمعمول به اليوم
                        > هو `refund`.
                        MD,
                    'x-roles' => [Spec::BUYER],
                    'parameters' => [Spec::path('order', 'معرّف الطلب.')],
                    'requestBody' => Spec::body([
                        'type' => ['type' => 'string', 'enum' => ['refund', 'replacement'], 'nullable' => true, 'description' => 'الافتراضي `refund`.'],
                        'reason' => ['type' => 'string', 'enum' => ReturnRequest::REASONS],
                        'description' => ['type' => 'string', 'maxLength' => 1000, 'nullable' => true],
                        'restock' => ['type' => 'boolean', 'nullable' => true, 'description' => 'هل تعود البضاعة إلى المخزون عند الاستلام.'],
                        'items' => Spec::arrayOf(Spec::object([
                            'order_item_id' => ['type' => 'integer'],
                            'quantity' => ['type' => 'integer', 'minimum' => 1, 'description' => 'لا يتجاوز `returnable`.'],
                            'condition_note' => ['type' => 'string', 'maxLength' => 255, 'nullable' => true],
                        ], ['order_item_id', 'quantity']), ['minItems' => 1]),
                    ], ['reason', 'items']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'return' => ['type' => 'object'],
                        ]), 'أُرسل الطلب إلى المستورد.'),
                    ],
                ],
            ],

            '/returns/{returnRequest}' => [
                'get' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'مرتجع واحد',
                    'description' => 'المرتجع ببنوده وصوره وحالته وإشعاره الدائن إن صدر. يفتحه طرفاه.',
                    'x-roles' => [Spec::PARTIES],
                    'parameters' => [Spec::path('returnRequest', 'معرّف المرتجع.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['return' => ['type' => 'object']]), 'المرتجع.'),
                    ],
                ],
            ],

            '/returns/{returnRequest}/approve' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'اعتماد مرتجع',
                    'description' => 'المستورد يعتمد. والاعتماد **قد يكون جزئياً**: `quantities` يربط معرّف بند المرتجع بالكمية المعتمَدة، فيقبل اثنتين من خمسٍ ويُعلِن ذلك بدل أن يرفض الكلّ.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('returnRequest', 'معرّف المرتجع.')],
                    'requestBody' => Spec::optionalBody([
                        'note' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                        'quantities' => [
                            'type' => 'object',
                            'nullable' => true,
                            'description' => 'معرّف بند المرتجع ⟵ الكمية المعتمَدة. اتركه فارغاً لاعتماد المطلوب كلّه.',
                            'additionalProperties' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ]),
                    'responses' => [
                        '200' => Spec::message('اعتُمد المرتجع.', ['return' => ['type' => 'object']]),
                    ],
                ],
            ],

            '/returns/{returnRequest}/reject' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'رفض مرتجع',
                    'description' => 'المستورد يرفض، و**السبب إلزاميّ**: رفضٌ بلا سبب هو أوّل خطوةٍ نحو نزاع. ويبقى للمشتري أن يفتح نزاعاً بعده.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('returnRequest', 'معرّف المرتجع.')],
                    'requestBody' => Spec::body([
                        'reason' => ['type' => 'string', 'maxLength' => 500, 'description' => 'سبب الرفض — يُبلَّغ به المشتري.'],
                    ], ['reason']),
                    'responses' => [
                        '200' => Spec::message('رُفض المرتجع.'),
                    ],
                ],
            ],

            '/returns/{returnRequest}/in-transit' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'أرسلت البضاعة',
                    'description' => 'تاجر الجملة يعلن أنّه أرسل المرتجع، فينقله إلى «في الطريق». والإعلان له لا للمستورد: هو من أرسل.',
                    'x-roles' => [Spec::BUYER],
                    'parameters' => [Spec::path('returnRequest', 'معرّف المرتجع.')],
                    'responses' => [
                        '200' => Spec::message('نُقل المرتجع إلى «في الطريق».'),
                    ],
                ],
            ],

            '/returns/{returnRequest}/receive' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'تسجيل استلام مرتجع',
                    'description' => 'المستورد يسجّل ما استلمه فعلاً — وقد يقلّ عمّا أُرسل. وإن كان `restock` مطلوباً **عادت البضاعة إلى المخزون بتكلفتها** وقيدها.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('returnRequest', 'معرّف المرتجع.')],
                    'requestBody' => Spec::optionalBody([
                        'quantities' => [
                            'type' => 'object',
                            'nullable' => true,
                            'description' => 'معرّف بند المرتجع ⟵ الكمية المستلَمة. اتركه فارغاً لاستلام المعتمَد كلّه.',
                            'additionalProperties' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ]),
                    'responses' => [
                        '200' => Spec::message('سُجّل الاستلام.', ['return' => ['type' => 'object']]),
                    ],
                ],
            ],

            '/returns/{returnRequest}/complete' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'إقفال مرتجع وإصدار إشعار دائن',
                    'description' => <<<'MD'
                        آخر خطوة، وأثقلها: يُصدر **إشعار الائتمان** بقيمة ما
                        استُلم، ويُرحَّل قيده، و**تُعكس عمولة المنصة بالتناسب** —
                        فما لم يبق مبيعاً لا تُؤخَذ عليه عمولة.

                        والإشعار يصير رصيداً للعميل: يُطبَّق على فاتورةٍ من
                        `credit-notes/{creditNote}/apply`.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('returnRequest', 'معرّف المرتجع.')],
                    'responses' => [
                        '200' => Spec::message('صدر إشعار الائتمان.', [
                            'credit_note' => Spec::object([
                                'id' => ['type' => 'integer'],
                                'note_no' => ['type' => 'string'],
                                'subtotal' => ['type' => 'integer'],
                                'tax_amount' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                            ]),
                        ]),
                    ],
                ],
            ],

            '/returns/{returnRequest}/photos' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'رفع صورة مرتجع',
                    'description' => 'صورة التلف أو الصنف الخطأ، حتى ٥ ميغابايت. وهي ما يُحتجّ به عند الخلاف: دعوى تلفٍ بلا صورة كلامٌ مقابل كلام.',
                    'x-roles' => [Spec::PARTIES],
                    'parameters' => [Spec::path('returnRequest', 'معرّف المرتجع.')],
                    'requestBody' => Spec::form([
                        'photo' => Spec::file('صورة، حتى 5120 كيلوبايت.'),
                    ], ['photo']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'photo' => Spec::object([
                                'id' => ['type' => 'integer'],
                                'url' => ['type' => 'string'],
                            ]),
                        ]), 'رُفعت الصورة.'),
                    ],
                ],
            ],

            /* ---------- النزاعات ---------- */

            '/disputes' => [
                'get' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'النزاعات',
                    'description' => <<<'MD'
                        الطرفان يريان نزاعاتهما، وإدارة المنصة ترى كلّ النزاعات —
                        فهي الوسيط.

                        ويعيد الردّ `is_mediator`: به تعرف الواجهة هل تعرض أزرار
                        الوساطة أم أزرار الطرف.
                        MD,
                    'x-roles' => [Spec::PARTIES, Spec::PLATFORM],
                    'parameters' => [
                        // «المتأخّر» مقارنةٌ بالوقت الحاضر لا قيمةٌ في عمود
                        Spec::queryBool('overdue', 'ما انقضت مهلة ردّه — ما يحتاج تدخّل الوسيط الآن.'),
                        ...Spec::dataTableParams(['id', 'reference_no', 'status', 'reason', 'claimed_amount', 'due_at', 'created_at'], ['status', 'reason']),
                    ],
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(['type' => 'object'], [
                            'is_mediator' => ['type' => 'boolean', 'description' => 'هل القارئ وسيط — تُبنى منه أزرار الصفحة.'],
                        ]), 'صفحة من النزاعات.'),
                    ],
                ],
            ],

            '/orders/{order}/disputes' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'فتح نزاع',
                    'description' => 'يفتحه **أيّ الطرفين** — فالمستورد قد يكون هو المتضرّر — ويُبلَّغ الطرف الآخر ومعه إدارة المنصة. و`return_request_id` يربطه بمرتجعٍ رُفض، فيُقرأ النزاع في سياقه.',
                    'x-roles' => [Spec::PARTIES],
                    'parameters' => [Spec::path('order', 'معرّف الطلب المتنازع عليه.')],
                    'requestBody' => Spec::body([
                        'reason' => ['type' => 'string', 'enum' => Dispute::REASONS],
                        'description' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'الدعوى — يقرؤها الطرف الآخر والوسيط.'],
                        'claimed_amount' => Spec::minor('المبلغ المتنازع عليه') + ['nullable' => true],
                        'return_request_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'المرتجع الذي نشأ عنه النزاع، إن نشأ عنه.'],
                    ], ['reason', 'description']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'dispute' => ['type' => 'object'],
                        ]), 'فُتح النزاع وأُبلغ الطرف الآخر.'),
                    ],
                ],
            ],

            '/disputes/{dispute}' => [
                'get' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'سجل نزاع',
                    'description' => <<<'MD'
                        النزاع كاملاً: طرفاه، وطلبه، وسببه، والمبلغ المتنازع
                        عليه، ومهلة الردّ، وكلّ حدثٍ فيه.

                        و**ملاحظات الوسيط الداخلية تُحجب عن الطرفين**: الوسيط
                        يحتاج أن يكتب لنفسه، والرأي قبل القرار لا يُعرَض على
                        الخصوم.
                        MD,
                    'x-roles' => [Spec::PARTIES, Spec::PLATFORM],
                    'parameters' => [Spec::path('dispute', 'معرّف النزاع.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['dispute' => ['type' => 'object']]), 'النزاع وخطّه الزمنيّ.'),
                    ],
                ],
            ],

            '/disputes/{dispute}/messages' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'رسالة في نزاع',
                    'description' => 'يكتبها الطرفان والوسيط، مع مرفقٍ حتى ١٠ ميغابايت. و`is_internal` **للوسيط وحده**: ملاحظةٌ لا يراها الخصمان — ومن طلبها وهو طرفٌ تُهمَل منه.',
                    'x-roles' => [Spec::PARTIES, Spec::PLATFORM],
                    'parameters' => [Spec::path('dispute', 'معرّف النزاع.')],
                    'requestBody' => Spec::form([
                        'body' => ['type' => 'string', 'maxLength' => 2000],
                        'file' => Spec::file('مرفق، حتى 10240 كيلوبايت: PDF أو صورة أو XLSX أو DOCX.'),
                        'is_internal' => ['type' => 'boolean', 'description' => 'ملاحظة داخلية — للوسيط وحده.'],
                    ], ['body']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['dispute' => ['type' => 'object']]), 'أُضيفت الرسالة.'),
                    ],
                ],
            ],

            '/disputes/{dispute}/close' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'إقفال نزاع',
                    'description' => 'يُنهي النزاع بلا قرار — سُحبت الدعوى، أو اتّفق الطرفان خارجه. والقرار له مسارٌ آخر: `resolve`.',
                    'x-roles' => [Spec::PARTIES, Spec::PLATFORM],
                    'parameters' => [Spec::path('dispute', 'معرّف النزاع.')],
                    'requestBody' => Spec::optionalBody([
                        'note' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true],
                    ]),
                    'responses' => [
                        '200' => Spec::message('أُقفل النزاع.', ['status' => ['type' => 'string']]),
                    ],
                ],
            ],

            '/disputes/{dispute}/assign' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'تولّي الوساطة',
                    'description' => 'مستخدم المنصة يُسند النزاع إلى نفسه، فيصير الوسيط فيه وينتقل إلى «قيد المراجعة». والإسناد صريح كي لا يبقى النزاع بلا صاحبٍ في صندوقٍ مشترك.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('dispute', 'معرّف النزاع.')],
                    'responses' => [
                        '200' => Spec::message('بدأت المراجعة.', ['dispute' => ['type' => 'object']]),
                    ],
                ],
            ],

            '/disputes/{dispute}/request-response' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'طلب إيضاح من طرف',
                    'description' => 'الوسيط يطلب ردّاً **ويضبط مهلته**. ومن انقضت مهلته يظهر في `disputes?overdue=1` — فالصمت لا يُجمِّد النزاع إلى الأبد.',
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('dispute', 'معرّف النزاع.')],
                    'requestBody' => Spec::body([
                        'message' => ['type' => 'string', 'maxLength' => 1000, 'description' => 'ما يُطلَب إيضاحه.'],
                    ], ['message']),
                    'responses' => [
                        '200' => Spec::message('طُلب الإيضاح وضُبطت المهلة.', ['dispute' => ['type' => 'object']]),
                    ],
                ],
            ],

            '/disputes/{dispute}/resolve' => [
                'post' => [
                    'tags' => ['المرتجعات والنزاعات'],
                    'summary' => 'إصدار قرار',
                    'description' => <<<'MD'
                        حكم الوسيط، و**يُترجَم إلى قيوده** لا يبقى نصّاً: النتيجة
                        ومبلغها يُرحَّلان في دفتري الطرفين.

                        - `buyer_favored` — لصالح المشتري
                        - `supplier_favored` — لصالح المستورد
                        - `partial` — جزئيّ، ومعه `resolution_amount`
                        - `withdrawn` — سُحبت الدعوى
                        MD,
                    'x-roles' => [Spec::PLATFORM],
                    'parameters' => [Spec::path('dispute', 'معرّف النزاع.')],
                    'requestBody' => Spec::body([
                        'outcome' => ['type' => 'string', 'enum' => ['buyer_favored', 'supplier_favored', 'partial', 'withdrawn']],
                        'resolution_amount' => Spec::minor('المبلغ المحكوم به') + ['nullable' => true],
                        'resolution_note' => ['type' => 'string', 'maxLength' => 2000, 'nullable' => true, 'description' => 'حُجّة القرار — يقرؤها الطرفان.'],
                    ], ['outcome']),
                    'responses' => [
                        '200' => Spec::message('صدر القرار وتُرجم إلى قيوده.', ['dispute' => ['type' => 'object']]),
                    ],
                ],
            ],
        ];
    }
}
