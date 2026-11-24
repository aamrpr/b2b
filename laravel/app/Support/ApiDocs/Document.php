<?php

namespace App\Support\ApiDocs;

/**
 * مواصفة الواجهة البرمجية كاملةً.
 *
 * تُجمَع من ملفّات المجموعات، ثمّ تُزيَّن: من يستدعي كلّ عملية يُكتب في
 * أوّل وصفها، واستجابات الخطأ المشتركة تُحقَن بحسب ما تحتاجه العملية.
 * فلا يعيد كاتب المجموعة كتابة ٤٠١ و٤٠٣ و٤٢٢ مئةً وأربعاً وسبعين مرّة،
 * ولا تختلف صياغتها من مسار إلى آخر.
 */
final class Document
{
    /** إصدار المواصفة: 3.0.3 لا 3.1، لأنّ أدوات التوليد والاستيراد أوسع دعماً له. */
    private const OPENAPI = '3.0.3';

    private const GROUPS = [
        Groups\Platform::class,
        Groups\Authentication::class,
        Groups\Directory::class,
        Groups\Account::class,
        Groups\Catalog::class,
        Groups\Rfqs::class,
        Groups\Quotations::class,
        Groups\Orders::class,
        Groups\Shipping::class,
        Groups\Returns::class,
        Groups\Inventory::class,
        Groups\Purchasing::class,
        Groups\Payments::class,
        Groups\Accounting::class,
        Groups\Messaging::class,
        Groups\Administration::class,
    ];

    public static function build(): array
    {
        return [
            'openapi' => self::OPENAPI,
            'info' => self::info(),
            'servers' => self::servers(),
            'tags' => self::tags(),
            // الأصل أنّ المسار يحتاج رمزاً؛ والعامّ يُلغيه بـ security: []
            'security' => [['bearerAuth' => []]],
            'paths' => self::paths(),
            'components' => [
                'securitySchemes' => self::securitySchemes(),
                'schemas' => Schemas::all(),
                'responses' => Schemas::responses(),
            ],
        ];
    }

    /** المسارات مجموعةً ومزيَّنة. */
    public static function paths(): array
    {
        $paths = [];

        foreach (self::GROUPS as $group) {
            foreach ($group::paths() as $path => $operations) {
                foreach ($operations as $method => $operation) {
                    if (isset($paths[$path][$method])) {
                        throw new \LogicException("المسار {$method} {$path} موصوفٌ مرّتين.");
                    }

                    $paths[$path][$method] = self::decorate($operation);
                }
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * تزيين عملية واحدة: سطر الصلاحية في أوّل الوصف، ثمّ استجابات
     * الخطأ التي تنطبق عليها فعلاً.
     */
    private static function decorate(array $operation): array
    {
        $isPublic = array_key_exists('security', $operation) && $operation['security'] === [];
        $roles = (array) ($operation['x-roles'] ?? ($isPublic ? [Spec::ANY] : [Spec::AUTHED]));

        $operation['description'] = '**من يستدعيه:** '.implode(' · ', $roles)."\n\n"
            .trim($operation['description'] ?? '');

        $responses = $operation['responses'] ?? [];

        if (! $isPublic) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];

            if (! ($operation['x-no-forbidden'] ?? false)) {
                $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
            }
        }

        if (($operation['x-not-found'] ?? true) && self::takesResource($operation)) {
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }

        if (isset($operation['requestBody']) || ($operation['x-validates'] ?? false)) {
            $responses['422'] = ['$ref' => '#/components/responses/Invalid'];
        }

        if ($operation['x-throttled'] ?? false) {
            $responses['429'] = ['$ref' => '#/components/responses/RateLimited'];
        }

        $operation['responses'] = $responses;

        return $operation;
    }

    /** هل للعملية مُعامل في المسار — أي أنّها تطلب مورداً بعينه؟ */
    private static function takesResource(array $operation): bool
    {
        foreach ($operation['parameters'] ?? [] as $parameter) {
            if (($parameter['in'] ?? null) === 'path') {
                return true;
            }
        }

        return false;
    }

    private static function info(): array
    {
        return [
            'title' => 'المستورد · ALMUSTAWRED — الواجهة البرمجية',
            'version' => '1.0.0',
            'description' => trim(<<<'MD'
                واجهة سوقٍ بين التجّار (B2B) تصل **تاجر الجملة** الباحث عن بضاعة
                بـ**المستورد** الذي يملكها، وتحمل الصفقة من أوّل طلب سعرٍ إلى آخر
                قيدٍ في الدفتر.

                ## الأدوار

                كلّ حسابٍ ينتمي إلى **مستأجر** واحد، ونوع المستأجر هو ما يحدّد ما
                يستطيع أصحابه استدعاءه:

                | النوع | من هو | ما يملكه |
                |---|---|---|
                | `supplier` | المستورد — البائع | الكتالوج، العروض، المخزون، المشتريات، المحاسبة |
                | `buyer` | تاجر الجملة — المشتري | طلبات الأسعار، المقارنة، القبول، المرتجعات |
                | `platform` | إدارة المنصة — الوسيط | الاعتماد، الحكم في النزاعات، العمولة، كلّ مسارات `admin/` |

                اسم المنصة «المستورد» هو اسم أحد دوريها لا المشتري؛ والشيفرة تسمّي
                ذلك الدور `supplier` بينما تسمّيه الواجهة «مستورد».

                ## المصادقة

                `POST auth/login` يعيد رمز Sanctum. أرسله في كلّ طلبٍ بعده:

                ```
                Authorization: Bearer 1|abcdef...
                ```

                اضغط **Authorize** أعلى الصفحة ولصّق الرمز هناك لتجريب المسارات من
                هنا مباشرة. والرمز يُبطَل عند `auth/logout`، وعند تغيير كلمة المرور،
                وعند إيقاف الحساب من إدارة المنصة.

                ## المبالغ

                كلّ مبلغ **عددٌ صحيح بالوحدة الصغرى** — لا كسور عائمة في المال.
                و١٢٥٠ في عملةٍ ذات منزلتين عشريتين هي ١٢٫٥٠. وما يخرج من الواجهة
                يأتي في الشكل الموصوف في `Money`: القيمة الخام للحساب، والنصّ
                المنسَّق للعرض.

                ## اللغة

                ترويسة `Accept-Language: ar` أو `en` تضبط لغة الاستجابة: النصوص
                المترجَمة تعود بلغة الطلب، وتعود معها الترجمات كاملةً في حقلٍ
                مرافقٍ ينتهي بـ`_translations` كي تملأ شاشات التحرير.

                ## العزل بين المستأجرين

                بيانات السوق مشتركة (المنتجات، طلبات الأسعار، الدليل)، وما خلفها
                معزولٌ إلزامياً: المحاسبة والفواتير والمخزون والشحن لا تُقرأ إلّا
                من دفتر صاحبها. فطلب مورد يملكه مستأجرٌ آخر يعود **404** لا 403 —
                العزل يخفيه ولا يُعلن وجوده.

                ## القوائم: بروتوكول DataTables

                مسارات **الجداول** — المنتجات، المستخدمون، الطلبات، الشحنات،
                المرتجعات، النزاعات، المخزون، المشتريات، المدفوعات، الصفقات،
                سجلّ التدقيق — تتكلّم بروتوكول **DataTables** للمعالجة في
                الخادم، لأنّ الواجهة تبني جداولها بتلك المكتبة.

                ترسل مع كلّ نداء: `draw` و`start` و`length`، وبحثاً عامّاً في
                `search[value]`، وبحثاً لكلّ عمود في `columns[i][search][value]`،
                وترتيباً بفهرس العمود في `order[0][column]` و`order[0][dir]`.
                وتعود:

                ```json
                { "draw": 1, "recordsTotal": 174, "recordsFiltered": 12, "data": [ … ] }
                ```

                و`recordsTotal` عدد السطور **داخل نطاقك** قبل أيّ بحث — لا عدد
                سطور الجدول في المنصة كلّها: العزل يسبق العدّ.

                و`draw` يعود **عدداً صحيحاً** لا كما أُرسل نصّاً، وذلك شرطٌ
                أمنيّ من المكتبة نفسها: قيمةٌ تعود كما جاءت تُكتَب في الصفحة.

                ولا يُقبَل الترتيب ولا التصفية إلّا بالأعمدة المعلنة في كلّ
                مسار؛ وما سواها يُهمَل صامتاً، لأنّ اسم العمود يأتي من
                المتصفّح ولو مرّ إلى الاستعلام لصار باب حقن.

                وما لا يقابله عمود — «الناقص عن حدّه»، «المتأخّر عن مهلته»،
                «ما بقي منه رصيد» — يبقى معاملاً عادياً في الطلب، فهو شرطٌ على
                الصفحة لا قيمةٌ في سطر.

                أمّا قوائم **البطاقات** — دليل المستوردين، طلبات الأسعار،
                المحادثات، الإشعارات، دفتر اليومية — فتبقى على تصفّح Laravel:
                `?page=`، وردٌّ فيه `data` و`meta`.

                ## الأخطاء

                | الرمز | معناه |
                |---|---|
                | `401` | لا رمز أو رمزٌ منتهٍ |
                | `403` | الرمز صحيح وصاحبه ليس من أهل المسار |
                | `404` | لا مورد بهذا المعرّف — أو هو لغير هذا المستأجر |
                | `422` | مُدخَل غير صالح، **أو** خرق قاعدة عمل (رصيد غير كافٍ، انتقال حالة غير مسموح) |
                | `429` | تجاوز حدّ المعدّل |

                الرمز ٤٢٢ يحمل الحالتين عن قصد: خرق قاعدة العمل مُدخَلٌ غير صالح
                لا عطل خادم، فيُعاد في جسمٍ فيه `message` جاهزاً للعرض — ومع
                تفصيلٍ إضافي حين يملكه (`shortages` للنقص، `allowed` للانتقالات
                المسموحة).
                MD),
        ];
    }

    /**
     * الخادم الأوّل هو الذي جاء منه الطلب لا الذي في الإعداد: من فتح
     * الوثيقة على منفذٍ آخر ينبغي أن يعمل زرّ «جرّب» عنده — ولو خالف
     * APP_URL. ويُضاف المُعَدّ بعده إن اختلف.
     */
    private static function servers(): array
    {
        $current = rtrim(url('/api/v1'), '/');
        $configured = rtrim((string) config('app.url'), '/').'/api/v1';

        $servers = [['url' => $current, 'description' => 'الخادم الذي جاءت منه هذه الصفحة']];

        if ($configured !== $current) {
            $servers[] = ['url' => $configured, 'description' => 'العنوان المُعَدّ في APP_URL'];
        }

        return $servers;
    }

    private static function securitySchemes(): array
    {
        return [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'رمز Sanctum من `auth/login` أو من أحد مسارَي التسجيل.',
            ],
        ];
    }

    private static function tags(): array
    {
        return [
            ['name' => 'المنصة', 'description' => 'بيانات لا تخصّ حساباً: اللغات والعملات، وشجرة الأصناف، ورسالة إلى الإدارة.'],
            ['name' => 'المصادقة', 'description' => 'التسجيل بدورين، والدخول، ومن أنا، والخروج.'],
            ['name' => 'الدليل العام', 'description' => 'ما يراه الزائر قبل أن يسجّل: المستوردون، الكتالوج، تتبّع الشحنة برمزها.'],
            ['name' => 'الحساب', 'description' => 'كلّ مستخدم يدير معلوماته وكلمة مروره وصورته، وبيانات شركته وشعارها.'],
            ['name' => 'كتالوج المستورد', 'description' => 'منتجات المستورد: إنشاء وتعديل وحذف، وصورٌ وخصائص وسلّم أسعار.'],
            ['name' => 'طلبات الأسعار', 'description' => 'تاجر الجملة ينشر طلبه فيصل آلياً إلى مستوردي الصنف.'],
            ['name' => 'عروض الأسعار', 'description' => 'المستورد يردّ بعرضه، وتاجر الجملة يقارن ثمّ يوقّع قبوله.'],
            ['name' => 'أوامر الشراء', 'description' => 'ما ينشأ عن قبول عرض: حالته، وبنوده، وعمولته.'],
            ['name' => 'الشحن', 'description' => 'شركات الشحن وتسعيرتها، والشحنات وحالاتها وأحداثها ومستنداتها.'],
            ['name' => 'المرتجعات والنزاعات', 'description' => 'الإرجاع من الطلب إلى إشعار الائتمان، والنزاع حين يبقى الخلاف.'],
            ['name' => 'المخزون', 'description' => 'المستودعات والأصناف والحركات والجرد، بالمتوسط المرجّح وطبقات FIFO.'],
            ['name' => 'المشتريات', 'description' => 'شراء المستورد من مورّديه: أوامر، واستلام، وفوترة ما استُلم.'],
            ['name' => 'المدفوعات', 'description' => 'التحصيل والصرف وتوزيع الدفعات وكشوف الحساب وإشعارات الائتمان.'],
            ['name' => 'المحاسبة', 'description' => 'دفترٌ بالقيد المزدوج لكلّ مستأجر: دليل الحسابات واليومية والتقارير.'],
            ['name' => 'المحادثات والإشعارات', 'description' => 'مراسلة بين المستأجرين، وإشعارات المستخدم وتفضيلاتها.'],
            ['name' => 'إدارة المنصة', 'description' => 'مقصورة على مستخدمي المنصة: الاعتماد والتحكّم والعمولة والتدقيق.'],
        ];
    }
}
