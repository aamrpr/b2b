<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** طلبات الأسعار: تاجر الجملة ينشر، ومستوردو الصنف يستلمون. */
final class Rfqs
{
    public static function paths(): array
    {
        return [
            '/rfqs' => [
                'post' => [
                    'tags' => ['طلبات الأسعار'],
                    'summary' => 'نشر طلب سعر',
                    'description' => <<<'MD'
                        قلب المسار (ب): تاجر الجملة يكتب ما يريد مرّةً واحدة،
                        فيصل **آلياً إلى كلّ مستوردٍ مسجَّل في الصنف** — لا
                        يبحث عنهم ولا يراسلهم واحداً واحداً.

                        ويعيد الردّ `reached_suppliers`: كم مستورداً وصله الطلب
                        فعلاً. وصفرٌ جوابٌ صريح لا خطأ — فصنفٌ لا مستوردين فيه
                        حقيقةٌ ينبغي أن تُقال لصاحب الطلب في حينها.

                        **`hide_buyer_identity`** يخفي اسم المشتري عن المستوردين
                        حتى يقبل أحد عروضهم: تاجرٌ كبير لا يريد أن يُعرَف أنّه
                        يسأل، فيُسعَّر له بسعرٍ آخر.

                        و**`visibility`**: `public` تصل كلّ مستوردي الصنف،
                        و`targeted` تصل من عيّنتهم في `supplier_ids` وحدهم.

                        وللبثّ **سقفٌ يوميّ** لكلّ مستورد (من `config/b2b.php`)،
                        كي لا يصير صندوقه الوارد مكاناً لا يُقرأ.
                        MD,
                    'x-roles' => [Spec::BUYER],
                    'requestBody' => Spec::body([
                        'title' => ['type' => 'string', 'maxLength' => 191, 'description' => 'عنوان الطلب — هو ما يقرؤه المستورد أوّلاً.'],
                        'description' => ['type' => 'string', 'nullable' => true, 'description' => 'تفاصيل إضافية.'],
                        'category_id' => ['type' => 'integer', 'description' => 'الصنف — وهو ما يحدّد من يصله الطلب.'],
                        'target_price' => Spec::minor('السعر المستهدَف') + ['nullable' => true],
                        'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true],
                        'delivery_city' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                        'delivery_deadline' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'description' => 'تاريخٌ بعد اليوم.'],
                        'validity_hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 720, 'nullable' => true, 'description' => 'صلاحية الطلب بالساعات. الافتراضي من إعداد المنصة (٧٢).'],
                        'visibility' => ['type' => 'string', 'enum' => ['public', 'targeted'], 'nullable' => true],
                        'hide_buyer_identity' => ['type' => 'boolean', 'nullable' => true],
                        'supplier_ids' => Spec::arrayOf(['type' => 'integer'], ['nullable' => true, 'description' => 'مع `targeted` وحدها.']),
                        'items' => Spec::arrayOf(Spec::object([
                            'description' => ['type' => 'string', 'maxLength' => 255],
                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                            'unit' => ['type' => 'string', 'maxLength' => 20, 'nullable' => true, 'description' => 'رمز وحدة المنتج من `GET /units`. تُؤخذ من المنتج ولا تُكتَب يدوياً.'],
                            'product_id' => ['type' => 'integer', 'description' => 'منتجٌ من كتالوج الصنف المختار — البند يُربَط به لا باسمٍ مكتوب.'],
                            'specifications' => ['type' => 'object', 'nullable' => true, 'description' => 'خصائص مطلوبة، حرّةً.'],
                        ], ['description', 'quantity', 'product_id']), ['minItems' => 1, 'description' => 'الأصناف المطلوبة — سطرٌ واحد على الأقلّ، وكلّ سطر منتجٌ من الصنف.']),
                    ], ['title', 'category_id', 'items']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string', 'description' => 'سطرٌ يقول إلى كم مستورد وصل الطلب.'],
                            'reached_suppliers' => ['type' => 'integer', 'description' => 'عدد من وصلهم فعلاً — وقد يكون صفراً.'],
                            'rfq' => Spec::ref('Rfq'),
                        ]), 'نُشر الطلب وبُثّ.'),
                    ],
                ],
            ],

            '/my/rfqs' => [
                'get' => [
                    'tags' => ['طلبات الأسعار'],
                    'summary' => 'طلباتي',
                    'description' => 'طلبات تاجر الجملة، أحدثها أوّلاً، ومع كلٍّ منها عدّادات `reach` الثلاثة — وصل، فُتح، قُدّم عرض — فيعرف هل الصمت لعدم الوصول أم لعدم الرغبة.',
                    'x-roles' => [Spec::BUYER],
                    'parameters' => Spec::pagination(20),
                    'responses' => [
                        '200' => Spec::ok(Spec::resourcePage(Spec::ref('Rfq')), 'صفحة من طلباتك.'),
                    ],
                ],
            ],

            '/rfq-inbox' => [
                'get' => [
                    'tags' => ['طلبات الأسعار'],
                    'summary' => 'الطلبات الواردة',
                    'description' => <<<'MD'
                        ما وصل المستورد في أصنافه هو — لا كلّ طلبات المنصة.

                        والمفتوحة غير المنتهية وحدها تظهر: طلبٌ انقضت صلاحيته لا
                        معنى للتسعير له.

                        ومن أخفى هويته يعود اسمه «مشترٍ مجهول الهوية» بلا معرّف:
                        يُسعَّر لما يُطلَب لا لمن يطلب.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => Spec::pagination(20),
                    'responses' => [
                        '200' => Spec::ok(Spec::resourcePage(Spec::ref('Rfq')), 'صفحة من صندوقك الوارد.'),
                    ],
                ],
            ],

            '/rfqs/{rfq}' => [
                'get' => [
                    'tags' => ['طلبات الأسعار'],
                    'summary' => 'طلب سعر واحد',
                    'description' => 'الطلب ببنوده وصنفه. يفتحه صاحبه، أو مستوردٌ وصله — وفتحُ المستورد **يُسجَّل** فيزيد عدّاد `viewed` عند المشتري.',
                    'x-roles' => [Spec::BUYER, Spec::SUPPLIER],
                    'parameters' => [Spec::path('rfq', 'معرّف الطلب.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::ref('Rfq')]), 'الطلب.'),
                    ],
                ],
            ],

            '/rfqs/{rfq}/stats' => [
                'get' => [
                    'tags' => ['طلبات الأسعار'],
                    'summary' => 'إحصاء وصول طلب',
                    'description' => 'العدّادات الثلاثة وحدها، بلا الطلب: تستدعيها الواجهة لتحديث البطاقة بلا إعادة تحميل كلّ شيء.',
                    'x-roles' => [Spec::BUYER],
                    'parameters' => [Spec::path('rfq', 'معرّف الطلب.')],
                    'responses' => [
                        '200' => Spec::ok(Spec::object([
                            'notified' => ['type' => 'integer', 'description' => 'كم مستورداً وصله.'],
                            'viewed' => ['type' => 'integer', 'description' => 'كم منهم فتحه.'],
                            'responded' => ['type' => 'integer', 'description' => 'كم منهم ردّ بعرض.'],
                        ]), 'الإحصاء.'),
                    ],
                ],
            ],
        ];
    }
}
