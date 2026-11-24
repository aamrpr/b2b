<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** كتالوج المستورد: منتجاته وصورها. */
final class Catalog
{
    /** حقول المنتج — واحدة للإنشاء وللتعديل، كما في المتحكّم. */
    private static function productFields(): array
    {
        return [
            'category_id' => ['type' => 'integer', 'description' => 'صنف المنتج.'],
            'name' => Spec::translatable('191'),
            'description' => Spec::object([
                'ar' => ['type' => 'string', 'nullable' => true],
                'en' => ['type' => 'string', 'nullable' => true],
            ]) + ['nullable' => true],
            'sku' => ['type' => 'string', 'maxLength' => 60, 'nullable' => true, 'description' => 'رمز المنتج عندك. لا يُفحَص تفرّده بين المستوردين.'],
            'brand' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
            'hs_code' => ['type' => 'string', 'maxLength' => 20, 'nullable' => true, 'description' => 'الرمز الجمركي المنسَّق — يُقرأ في التخليص.'],
            'country_of_origin' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 2, 'nullable' => true, 'description' => 'رمز البلد بحرفين: SY، CN، TR…'],
            'unit_of_measure' => ['type' => 'string', 'maxLength' => 20, 'nullable' => true, 'description' => 'رمز وحدة من `GET /units`، مثل `piece`. الاسم المترجم يُقرأ من هناك.'],
            'min_order_quantity' => ['type' => 'integer', 'minimum' => 1, 'nullable' => true, 'description' => 'أقلّ كمية تقبل بيعها.'],
            'lead_time_days' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true, 'description' => 'مدّة التسليم بالأيام.'],
            'price_visible' => ['type' => 'boolean', 'nullable' => true, 'description' => 'false يعني «السعر عند الطلب»: يُخزَّن السعر ولا يُعرَض.'],
            'base_price' => Spec::minor('سعر الوحدة') + ['nullable' => true],
            'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true, 'description' => 'الافتراضي عملة دفترك.'],
            'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'out_of_stock', 'archived'], 'nullable' => true, 'description' => '`active` وحدها تظهر في السوق.'],
            'price_tiers' => Spec::arrayOf(Spec::object([
                'min_qty' => ['type' => 'integer', 'minimum' => 1],
                'max_qty' => ['type' => 'integer', 'nullable' => true, 'description' => 'أكبر من `min_qty`. اتركه فارغاً للدرجة الأخيرة المفتوحة.'],
                'unit_price' => Spec::minor('سعر الوحدة في هذه الدرجة'),
            ], ['min_qty', 'unit_price']), ['nullable' => true, 'description' => 'سلّم السعر بالكمية. يُستبدَل كاملاً عند كلّ حفظ.']),
            'specifications' => Spec::arrayOf(Spec::object([
                'label' => Spec::translatable('120'),
                'value' => Spec::translatable('255'),
            ], ['label', 'value']), ['nullable' => true, 'maxItems' => 40, 'description' => 'الخصائص: تسمية وقيمة، كلٌّ بلغتيه. حتى أربعين خاصية.']),
        ];
    }

    public static function paths(): array
    {
        return [
            '/my/products' => [
                'get' => [
                    'tags' => ['كتالوج المستورد'],
                    'summary' => 'منتجاتي',
                    'description' => 'كتالوجك كلّه — **بكلّ حالاته**، لا المعروض وحده: المسوَّدة والمؤرشفة تظهر هنا ولا تظهر في السوق. والبحث العام يمرّ على كل أعمدة السجل ما عدا الصور: الرمز والاسم والسعر والحدّ الأدنى والوحدة والحالة.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => Spec::dataTableParams(['id', 'sku', 'brand', 'status', 'price', 'min_order_quantity', 'created_at'], ['status', 'category', 'price', 'min_order_quantity']),
                    'responses' => [
                        '200' => Spec::ok(Spec::dataTable(Spec::ref('Product')), 'صفحة من كتالوجك.'),
                    ],
                ],
                'post' => [
                    'tags' => ['كتالوج المستورد'],
                    'summary' => 'منتج جديد',
                    'description' => <<<'MD'
                        ينشئ المنتج في كتالوجك. السبيكة تُولَّد من الاسم، والصور
                        تُرفَع بعده في مسارٍ آخر — لأنّ رفع ملفٍّ مع JSON يخلط
                        صيغتين في طلبٍ واحد.

                        والاسم الإنجليزي اختياريّ: من كتب العربية وحدها حصل على
                        منتجٍ يعمل، ومن أراد كتالوجاً بلغتين أضاف الإنجليزية
                        لاحقاً.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'requestBody' => Spec::body(self::productFields(), ['category_id', 'name']),
                    'responses' => [
                        '201' => Spec::created(Spec::object(['data' => Spec::ref('Product')]), 'أُنشئ المنتج.'),
                    ],
                ],
            ],

            '/my/products/{product}' => [
                'put' => [
                    'tags' => ['كتالوج المستورد'],
                    'summary' => 'تعديل منتج',
                    'description' => <<<'MD'
                        تعديلٌ جزئيّ: ما لم تُرسله بقي كما هو.

                        وما تكتبه بالعربية **لا يمحو الإنجليزية**: الترجمات
                        تُدمَج لا تُستبدل، فالتعديل من شاشةٍ عربية لا يُفقد
                        الكتالوج نصفه الإنجليزي.

                        أمّا `price_tiers` و`specifications` فتُستبدلان كاملتين
                        حين تُرسَلان، لأنّهما قائمتان لا حقول: أرسل السلّم الذي
                        تريده لا الفرق.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('product', 'معرّف المنتج — ويجب أن يكون في كتالوجك.')],
                    'requestBody' => Spec::optionalBody(self::productFields()),
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['data' => Spec::ref('Product')]), 'المنتج بعد الحفظ.'),
                    ],
                ],
                'delete' => [
                    'tags' => ['كتالوج المستورد'],
                    'summary' => 'حذف منتج',
                    'description' => 'حذفٌ ناعم: يختفي من الكتالوج ومن السوق، ويبقى مرتبطاً بما مضى من عروضٍ وأوامر شراء — فالطلب القديم لا ينبغي أن يفقد اسم ما اشتُري فيه.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('product', 'معرّف المنتج.')],
                    'responses' => [
                        '200' => Spec::message('حُذف المنتج.'),
                    ],
                ],
            ],

            '/my/products/{product}/images' => [
                'post' => [
                    'tags' => ['كتالوج المستورد'],
                    'summary' => 'رفع صور منتج',
                    'description' => <<<'MD'
                        حتى ثماني صورٍ في الطلب الواحد، كلٌّ حتى ٤ ميغابايت،
                        والصيغ المقبولة JPG و PNG و WEBP.

                        ويُفحَص **أوّل بايتات الملفّ** لا امتداده: ملفٌّ اسمه
                        `.jpg` وفيه شيءٌ آخر يُرفَض.

                        وأوّل صورةٍ في الترتيب هي **صورة الغلاف**. البطاقة تعرض
                        مصغّرها، وصفحة المنتج تبقي الأصل للتكبير.
                        MD,
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('product', 'معرّف المنتج.')],
                    'requestBody' => Spec::form([
                        'images' => Spec::arrayOf(Spec::file('صورة واحدة'), [
                            'minItems' => 1,
                            'maxItems' => 8,
                            'description' => 'تُرسَل كـ `images[]`.',
                        ]),
                    ], ['images']),
                    'responses' => [
                        '201' => Spec::created(Spec::object([
                            'message' => ['type' => 'string'],
                            'images' => Spec::arrayOf(Spec::object([
                                'id' => ['type' => 'integer'],
                                'url' => ['type' => 'string'],
                                'thumb_url' => ['type' => 'string', 'nullable' => true],
                            ])),
                        ]), 'رُفعت الصور، ومعها معرّفاتها.'),
                    ],
                ],
            ],

            '/my/products/{product}/images/order' => [
                'put' => [
                    'tags' => ['كتالوج المستورد'],
                    'summary' => 'ترتيب صور منتج',
                    'description' => 'المعرّفات بالترتيب المطلوب. **أوّلها يصير الغلاف.** وما لم يُذكر في القائمة يبقى بعدها.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [Spec::path('product', 'معرّف المنتج.')],
                    'requestBody' => Spec::body([
                        'ids' => Spec::arrayOf(['type' => 'integer'], ['minItems' => 1, 'description' => 'معرّفات الصور بالترتيب المطلوب.']),
                    ], ['ids']),
                    'responses' => [
                        '200' => Spec::message('حُفظ الترتيب.'),
                    ],
                ],
            ],

            '/my/products/{product}/images/{media}' => [
                'delete' => [
                    'tags' => ['كتالوج المستورد'],
                    'summary' => 'حذف صورة منتج',
                    'description' => 'يُحذف السجلّ وملفّه من القرص. ولذلك يرافق المعرّفُ الرابطَ في `images`: ليُحذف واحدةٌ بعينها لا الكلّ.',
                    'x-roles' => [Spec::SUPPLIER],
                    'parameters' => [
                        Spec::path('product', 'معرّف المنتج.'),
                        Spec::path('media', 'معرّف الصورة، من `images[].id`.'),
                    ],
                    'responses' => [
                        '200' => Spec::message('حُذفت الصورة.'),
                    ],
                ],
            ],
        ];
    }
}
