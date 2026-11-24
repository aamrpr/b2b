<?php

namespace App\Support\ApiDocs;

/**
 * المخطَّطات المشتركة — مقروءة من أصناف App\Http\Resources.
 *
 * ما تخرجه الواجهة البرمجية في أكثر من مسار يُوصَف هنا مرّة واحدة
 * ويُشار إليه، كما تفعل أصناف المورد نفسها: الشكل واحد في الشيفرة،
 * فيبقى واحداً في التوصيف.
 */
final class Schemas
{
    public static function all(): array
    {
        return [
            /* ---------- أدوات عامّة ---------- */

            'Money' => Spec::object([
                'amount' => ['type' => 'integer', 'description' => 'القيمة الخام بالوحدة الصغرى — للحساب.'],
                'currency' => ['type' => 'string', 'example' => 'SYP', 'description' => 'رمز العملة بثلاثة أحرف.'],
                'decimals' => ['type' => 'integer', 'example' => 2, 'description' => 'عدد المنازل العشرية للعملة.'],
                'formatted' => ['type' => 'string', 'example' => '1,250.00', 'description' => 'النصّ المنسَّق — للعرض.'],
            ], ['amount', 'currency', 'decimals', 'formatted']),

            'PageMeta' => Spec::object([
                'current_page' => ['type' => 'integer'],
                'last_page' => ['type' => 'integer'],
                'total' => ['type' => 'integer', 'description' => 'عدد السطور كلّها، لا سطور الصفحة.'],
            ], ['current_page', 'last_page', 'total']),

            'ResourceLinks' => Spec::object([
                'first' => ['type' => 'string', 'nullable' => true],
                'last' => ['type' => 'string', 'nullable' => true],
                'prev' => ['type' => 'string', 'nullable' => true],
                'next' => ['type' => 'string', 'nullable' => true],
            ]),

            'ResourceMeta' => Spec::object([
                'current_page' => ['type' => 'integer'],
                'from' => ['type' => 'integer', 'nullable' => true],
                'last_page' => ['type' => 'integer'],
                'path' => ['type' => 'string'],
                'per_page' => ['type' => 'integer'],
                'to' => ['type' => 'integer', 'nullable' => true],
                'total' => ['type' => 'integer'],
            ]),

            'Error' => Spec::object([
                'message' => ['type' => 'string', 'description' => 'سبب الرفض بلغة الطلب — تعرضه الواجهة كما هو.'],
            ], ['message']),

            'ValidationError' => Spec::object([
                'message' => ['type' => 'string', 'description' => 'أوّل سبب، جاهزاً للعرض.'],
                'errors' => [
                    'type' => 'object',
                    'description' => 'خطأ كل حقل على حدة: اسم الحقل ⟵ قائمة أسبابه.',
                    'additionalProperties' => Spec::arrayOf(['type' => 'string']),
                    'example' => ['email' => ['البريد مستخدم من قبل.']],
                ],
            ], ['message']),

            /* ---------- الهوية ---------- */

            'Tenant' => Spec::object([
                'id' => ['type' => 'integer'],
                'type' => ['type' => 'string', 'enum' => ['supplier', 'buyer', 'platform'], 'description' => 'نوع المستأجر — هو ما يحدّد ما يراه أصحابه.'],
                'name' => ['type' => 'string', 'description' => 'الاسم المعروض بلغة الطلب.'],
                'name_translations' => ['type' => 'object', 'description' => 'الاسم بلغتيه.'],
                'description' => ['type' => 'string', 'nullable' => true],
                'description_translations' => ['type' => 'object'],
                'slug' => ['type' => 'string'],
                'city' => ['type' => 'string', 'nullable' => true],
                'phone' => ['type' => 'string', 'nullable' => true],
                'whatsapp' => ['type' => 'string', 'nullable' => true],
                'logo_url' => ['type' => 'string', 'nullable' => true],
                'base_currency' => ['type' => 'string', 'description' => 'عملة دفتر هذا المستأجر.'],
                'status' => ['type' => 'string', 'enum' => ['active', 'pending', 'suspended']],
                'accounting_enabled' => ['type' => 'boolean'],
                'verification_status' => ['type' => 'string', 'enum' => ['pending', 'verified', 'rejected'], 'nullable' => true],
            ]),

            'User' => Spec::object([
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'phone' => ['type' => 'string', 'nullable' => true],
                'locale' => ['type' => 'string', 'enum' => ['ar', 'en'], 'nullable' => true],
                'avatar_url' => ['type' => 'string', 'nullable' => true],
                'tenant' => Spec::ref('Tenant'),
            ], ['id', 'name', 'email']),

            'AuthSession' => Spec::object([
                'token' => ['type' => 'string', 'description' => 'رمز Sanctum. يُرسَل بعدها في ترويسة Authorization: Bearer.'],
                'user' => Spec::ref('User'),
            ], ['token', 'user']),

            /* ---------- الكتالوج ---------- */

            'Category' => Spec::object([
                'id' => ['type' => 'integer'],
                'parent_id' => ['type' => 'integer', 'nullable' => true],
                'name' => ['type' => 'string', 'description' => 'الاسم بلغة الطلب.'],
                'name_translations' => ['type' => 'object'],
                'description' => ['type' => 'string', 'nullable' => true],
                'description_translations' => ['type' => 'object'],
                'slug' => ['type' => 'string'],
                'icon' => ['type' => 'string', 'nullable' => true],
                'image_url' => ['type' => 'string', 'nullable' => true, 'description' => 'الصورة الأصلية.'],
                'image_thumb_url' => ['type' => 'string', 'nullable' => true, 'description' => 'مصغّر بطاقة الصنف. null إن لم يُبنَ بعد، فيُستخدم image_url.'],
                'is_featured' => ['type' => 'boolean'],
                'suppliers_count' => ['type' => 'integer', 'nullable' => true],
                'products_count' => ['type' => 'integer', 'nullable' => true],
                'children' => Spec::arrayOf(['type' => 'object'], ['description' => 'الأصناف الفرعية — تُحمَّل في الشجرة فقط.']),
            ]),

            'Supplier' => Spec::object([
                'id' => ['type' => 'integer'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'name_translations' => ['type' => 'object'],
                'description' => ['type' => 'string', 'nullable' => true],
                'logo_url' => ['type' => 'string', 'nullable' => true],
                'country_code' => ['type' => 'string', 'nullable' => true],
                'city' => ['type' => 'string', 'nullable' => true],
                'phone' => ['type' => 'string', 'nullable' => true],
                'whatsapp' => ['type' => 'string', 'nullable' => true],
                'is_verified' => ['type' => 'boolean', 'description' => 'موثَّق — شارة الدليل، ومعناها أنّ الإدارة راجعت أوراقه.'],
                'rating' => Spec::object([
                    'average' => ['type' => 'number', 'format' => 'float'],
                    'count' => ['type' => 'integer'],
                ]),
                'completed_orders' => ['type' => 'integer', 'description' => 'الطلبات المسلَّمة والمغلقة.'],
                'min_order' => Spec::ref('Money'),
                'categories' => Spec::arrayOf(Spec::object([
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'is_primary' => ['type' => 'boolean'],
                    'brands' => Spec::arrayOf(['type' => 'string']),
                ])),
                'products_count' => ['type' => 'integer', 'nullable' => true],
            ]),

            'Product' => Spec::object([
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'description' => 'الاسم بلغة الطلب.'],
                'name_translations' => ['type' => 'object', 'description' => 'الاسم بلغتيه — تقرؤه شاشة التعديل.'],
                'description' => ['type' => 'string', 'nullable' => true],
                'description_translations' => ['type' => 'object'],
                'slug' => ['type' => 'string'],
                'sku' => ['type' => 'string', 'nullable' => true, 'description' => 'رمز المنتج عند مستورده.'],
                'brand' => ['type' => 'string', 'nullable' => true],
                'hs_code' => ['type' => 'string', 'nullable' => true, 'description' => 'الرمز الجمركي المنسَّق.'],
                'specifications' => Spec::arrayOf(Spec::object([
                    'label' => ['type' => 'string'],
                    'value' => ['type' => 'string'],
                ]), ['description' => 'الخصائص بلغة الطلب — للعرض.']),
                'specifications_translations' => Spec::arrayOf(['type' => 'object'], ['description' => 'الخصائص بلغتيها — للتحرير.']),
                'category_id' => ['type' => 'integer'],
                'category' => Spec::ref('Category'),
                'supplier' => Spec::ref('Supplier'),
                'unit_of_measure' => ['type' => 'string', 'nullable' => true],
                'min_order_quantity' => ['type' => 'integer', 'nullable' => true],
                'lead_time_days' => ['type' => 'integer', 'nullable' => true],
                'country_of_origin' => ['type' => 'string', 'nullable' => true, 'description' => 'رمز البلد بحرفين.'],
                'price_visible' => ['type' => 'boolean', 'description' => 'إن كان false فالسعر عند الطلب، وحقل price يعود null.'],
                'price' => Spec::refWith('Money', ['nullable' => true]),
                'price_tiers' => Spec::arrayOf(Spec::object([
                    'min_qty' => ['type' => 'integer'],
                    'max_qty' => ['type' => 'integer', 'nullable' => true],
                    'unit_price' => Spec::ref('Money'),
                ]), ['description' => 'سلّم السعر بالكمية.']),
                'images' => Spec::arrayOf(Spec::object([
                    'id' => ['type' => 'integer'],
                    'url' => ['type' => 'string'],
                    'thumb_url' => ['type' => 'string', 'nullable' => true, 'description' => 'مصغّر WebP للبطاقات. null إن لم يُبنَ بعد، فيُستخدم url.'],
                ]), ['description' => 'المعرّف يرافق الرابط ليتمكّن المالك من حذف صورة بعينها. url الأصل، وthumb_url للعرض الصغير.']),
                'cover_url' => ['type' => 'string', 'nullable' => true, 'description' => 'أولى الصور بحجمها الأصلي، لصفحة المنتج.'],
                'cover_thumb_url' => ['type' => 'string', 'nullable' => true, 'description' => 'مصغّر الغلاف لبطاقات السوق. null إن لم يوجد ملفّه.'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'out_of_stock', 'archived']],
            ]),

            /* ---------- الصفقة ---------- */

            'Rfq' => Spec::object([
                'id' => ['type' => 'integer'],
                'reference_no' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string', 'nullable' => true],
                'category' => Spec::ref('Category'),
                'buyer' => Spec::object([
                    'id' => ['type' => 'integer', 'nullable' => true],
                    'name' => ['type' => 'string'],
                ], []) + ['description' => 'إن أخفى المشتري هويته عاد الاسم «مشترٍ مجهول الهوية» بلا معرّف.'],
                'target_price' => Spec::refWith('Money', ['nullable' => true]),
                'currency' => ['type' => 'string'],
                'delivery_city' => ['type' => 'string', 'nullable' => true],
                'delivery_deadline' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'hours_remaining' => ['type' => 'integer', 'nullable' => true, 'description' => 'ساعات حتى الانتهاء، صفر لا سالب.'],
                'visibility' => ['type' => 'string', 'enum' => ['public', 'targeted']],
                'status' => ['type' => 'string', 'enum' => ['open', 'quoted', 'awarded', 'closed', 'expired', 'cancelled']],
                'reach' => Spec::object([
                    'notified' => ['type' => 'integer', 'description' => 'كم مستورداً وصله الطلب.'],
                    'viewed' => ['type' => 'integer', 'description' => 'كم منهم فتحه.'],
                    'quoted' => ['type' => 'integer', 'description' => 'كم منهم قدّم عرضاً.'],
                ]),
                'items' => Spec::arrayOf(Spec::object([
                    'id' => ['type' => 'integer'],
                    'description' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer'],
                    'unit' => ['type' => 'string', 'nullable' => true],
                    'specifications' => ['type' => 'object'],
                    'product' => Spec::object([
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'slug' => ['type' => 'string', 'description' => 'رابط صفحة المنتج.'],
                    ], ['id', 'name', 'slug']) + ['nullable' => true, 'description' => 'المنتج المرتبط بالبند. فارغ إن حُذف المنتج.'],
                ])),
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
            ]),

            'Quotation' => Spec::object([
                'id' => ['type' => 'integer'],
                'quotation_no' => ['type' => 'string', 'description' => 'رقم من دفتر المستورد نفسه — فقد يتكرّر عند مستوردٍ آخر.'],
                'version' => ['type' => 'integer'],
                'rfq_id' => ['type' => 'integer'],
                'supplier' => Spec::ref('Supplier'),
                'buyer' => Spec::ref('Supplier'),
                'rfq_title' => ['type' => 'string', 'nullable' => true],
                'subtotal' => Spec::ref('Money'),
                'discount' => Spec::ref('Money'),
                'tax' => Spec::ref('Money'),
                'shipping' => Spec::ref('Money'),
                'total' => Spec::ref('Money'),
                'currency' => ['type' => 'string'],
                'incoterm' => ['type' => 'string', 'nullable' => true, 'description' => 'شرط التسليم: EXW، FOB، CIF…'],
                'lead_time_days' => ['type' => 'integer', 'nullable' => true],
                'payment_terms_days' => ['type' => 'integer', 'nullable' => true, 'description' => 'مهلة السداد بالأيام.'],
                'valid_until' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'is_actionable' => ['type' => 'boolean', 'description' => 'هل ما زال يُقبل — مُرسَل وغير منتهٍ.'],
                'notes' => ['type' => 'string', 'nullable' => true],
                'status' => ['type' => 'string', 'enum' => ['draft', 'sent', 'countered', 'accepted', 'rejected', 'expired']],
                'signatures' => Spec::object([
                    'supplier' => Spec::refWith('Signature', ['nullable' => true]),
                    'buyer' => Spec::refWith('Signature', ['nullable' => true]),
                ]),
                'items' => Spec::arrayOf(Spec::object([
                    'id' => ['type' => 'integer'],
                    'description' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer'],
                    'unit' => ['type' => 'string', 'nullable' => true],
                    'unit_price' => Spec::ref('Money'),
                    'line_total' => Spec::ref('Money'),
                ])),
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
            ]),

            'Signature' => Spec::object([
                'url' => ['type' => 'string', 'description' => 'صورة خطّ اليد.'],
                'by' => ['type' => 'string', 'description' => 'اسم الموقّع — هو ما يُحتجّ به عند الخلاف.'],
                'at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'وقت التوقيع.'],
            ], ['url', 'by', 'at']),

            'Order' => Spec::object([
                'id' => ['type' => 'integer'],
                'order_no' => ['type' => 'string'],
                'supplier' => Spec::ref('Supplier'),
                'buyer' => Spec::object([
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ]),
                'subtotal' => Spec::ref('Money'),
                'tax' => Spec::ref('Money'),
                'shipping' => Spec::ref('Money'),
                'total' => Spec::ref('Money'),
                'commission' => Spec::refWith('Money', ['description' => 'عمولة المنصة على هذا الطلب.']),
                'currency' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'awaiting_approval', 'confirmed', 'in_production', 'shipped', 'delivered', 'closed', 'cancelled']],
                'payment_status' => ['type' => 'string', 'enum' => ['unpaid', 'partial', 'paid', 'overdue', 'refunded']],
                'confirmed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'delivered_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'items' => Spec::arrayOf(Spec::object([
                    'id' => ['type' => 'integer'],
                    'description' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer'],
                    'unit_price' => Spec::ref('Money'),
                    'line_total' => Spec::ref('Money'),
                ])),
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
            ]),

            /* ---------- الدفتر ---------- */

            'JournalEntry' => Spec::object([
                'id' => ['type' => 'integer'],
                'entry_no' => ['type' => 'string', 'nullable' => true],
                'entry_date' => ['type' => 'string', 'format' => 'date'],
                'description' => ['type' => 'string'],
                'currency' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'posted', 'reversed']],
                'source' => ['type' => 'string', 'nullable' => true, 'description' => 'ما ولّد القيد: فاتورة، تسليم، دفعة، عمولة، جرد… وفارغٌ للقيد اليدويّ.'],
                'total_debit' => ['type' => 'integer'],
                'total_credit' => ['type' => 'integer', 'description' => 'يساوي المدين — وإلّا لم يُرحَّل القيد.'],
                'reversed_entry_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'القيد الذي عكسه هذا، إن كان عكسياً.'],
                'lines' => Spec::arrayOf(Spec::object([
                    'id' => ['type' => 'integer'],
                    'account' => Spec::object([
                        'code' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                    ]),
                    'debit' => ['type' => 'integer'],
                    'credit' => ['type' => 'integer'],
                    'description' => ['type' => 'string', 'nullable' => true],
                ])),
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
            ]),

            /* ---------- المحادثة ---------- */

            'Conversation' => Spec::object([
                'id' => ['type' => 'integer'],
                'subject' => ['type' => 'string', 'nullable' => true],
                'context' => Spec::object([
                    'type' => ['type' => 'string', 'description' => 'Rfq أو Order.'],
                    'id' => ['type' => 'integer'],
                ]) + ['nullable' => true, 'description' => 'ما نشأت عنه المحادثة، إن نشأت عن شيء.'],
                'counterpart' => Spec::object([
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                ]) + ['nullable' => true, 'description' => 'الطرف الآخر — يُحسب من هوية القارئ.'],
                'last_message' => Spec::object([
                    'body' => ['type' => 'string', 'nullable' => true],
                    'type' => ['type' => 'string'],
                    'attachment_mime' => ['type' => 'string', 'nullable' => true],
                    'sender_tenant_id' => ['type' => 'integer'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ]) + ['nullable' => true],
                'unread_count' => ['type' => 'integer'],
                'last_message_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ]),

            'Message' => Spec::object([
                'id' => ['type' => 'integer'],
                'conversation_id' => ['type' => 'integer'],
                'sender_tenant_id' => ['type' => 'integer'],
                'sender_name' => ['type' => 'string', 'nullable' => true],
                'sender_company' => ['type' => 'string', 'nullable' => true],
                'body' => ['type' => 'string', 'nullable' => true],
                'type' => ['type' => 'string', 'enum' => ['text', 'file', 'image', 'audio', 'video', 'system']],
                'attachment_url' => ['type' => 'string', 'nullable' => true],
                'attachment_name' => ['type' => 'string', 'nullable' => true, 'description' => 'اسم الملف كما أرسله صاحبه، للعرض فقط.'],
                'attachment_mime' => ['type' => 'string', 'nullable' => true],
                'thumbnail_url' => ['type' => 'string', 'nullable' => true, 'description' => 'لقطة صغيرة للعرض في المحادثة. الملف الكامل في attachment_url.'],
                'is_mine' => ['type' => 'boolean', 'description' => 'محسوبٌ من هوية القارئ — توفيراً للمقارنة في الواجهة.'],
                'seen' => ['type' => 'boolean', 'description' => 'للرسالة الصادرة فقط: رآها الطرف الآخر حتى هذا المعرّف.'],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
            ]),

            'Notification' => Spec::object([
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'event' => ['type' => 'string', 'nullable' => true, 'description' => 'اسم الحدث — به تُربَط التفضيلات.'],
                'title' => ['type' => 'string', 'nullable' => true],
                'body' => ['type' => 'string', 'nullable' => true],
                'url' => ['type' => 'string', 'nullable' => true, 'description' => 'إلى أين تنقل النقرة داخل الواجهة.'],
                'read_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
            ]),
        ];
    }

    /** الاستجابات المشتركة — تُحقن في كل عملية بحسب ما تحتاجه. */
    public static function responses(): array
    {
        return [
            'Unauthenticated' => [
                'description' => 'لا رمز، أو رمزٌ منتهٍ. الواجهة تنقل صاحبه إلى صفحة الدخول.',
                'content' => ['application/json' => ['schema' => Spec::ref('Error')]],
            ],
            'Forbidden' => [
                'description' => 'الرمز صحيح لكنّ صاحبه ليس من أهل هذا المسار — دورٌ آخر، أو مستأجر آخر.',
                'content' => ['application/json' => ['schema' => Spec::ref('Error')]],
            ],
            'NotFound' => [
                'description' => 'لا مورد بهذا المعرّف — أو هو موجود لغير هذا المستأجر، فالعزل يخفيه.',
                'content' => ['application/json' => ['schema' => Spec::ref('Error')]],
            ],
            'Invalid' => [
                'description' => 'مُدخَل غير صالح، أو خرقٌ لقاعدة عمل (رصيد غير كافٍ، انتقال حالة غير مسموح). كلاهما ٤٢٢ لأنّ كلاهما مُدخَل لا عطل.',
                'content' => ['application/json' => ['schema' => Spec::ref('ValidationError')]],
            ],
            'RateLimited' => [
                'description' => 'تجاوز حدّ المعدّل. ترويسة Retry-After تقول متى يُعاد.',
                'content' => ['application/json' => ['schema' => Spec::ref('Error')]],
            ],
        ];
    }
}
