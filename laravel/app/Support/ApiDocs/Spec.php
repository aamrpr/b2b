<?php

namespace App\Support\ApiDocs;

/**
 * أدوات صياغة قصيرة لمواصفة OpenAPI.
 *
 * المواصفة مكتوبة بيد لا مولَّدة من تعليقات داخل المتحكّمات: التعليق
 * في هذا المشروع يشرح «لماذا»، ودفن أربعين سطراً من التوصيف فوق كل
 * دالّة يطمر ذلك الشرح. فالمواصفة هنا ملفّها، والمتحكّمات تبقى نظيفة —
 * وأمر `api:docs --check` يقابلها بجدول المسارات الحقيقي كي لا تنحرف.
 *
 * وهذه الأدوال تختصر ما يتكرّر: مُعامل، جسم طلب، صفحة من قائمة، مبلغ.
 */
final class Spec
{
    /* من يستدعي العملية — يُكتب في أوّل وصفها، ويقرّر حقن ٤٠١ و٤٠٣. */
    public const ANY = 'أيّ زائر، بلا رمز';

    public const AUTHED = 'أيّ مستخدم مسجَّل';

    public const SUPPLIER = 'المستورد';

    public const BUYER = 'تاجر الجملة';

    public const PLATFORM = 'إدارة المنصة';

    public const PARTIES = 'طرفا الصفقة';

    /** إشارة إلى مخطَّط في components. */
    public static function ref(string $schema): array
    {
        return ['$ref' => '#/components/schemas/'.$schema];
    }

    /** إشارة إلى مخطَّط، مع وصف أو سمة زائدة — allOf لأنّ 3.0 يُهمل ما يجاور $ref. */
    public static function refWith(string $schema, array $extra): array
    {
        return $extra + ['allOf' => [self::ref($schema)]];
    }

    /** مُعامل في مسار الطلب — إلزامي دائماً. */
    public static function path(string $name, string $description, string $type = 'integer'): array
    {
        return [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'description' => $description,
            'schema' => ['type' => $type],
        ];
    }

    /** مُعامل في سلسلة الاستعلام. */
    public static function query(string $name, string $description, array $schema = ['type' => 'string']): array
    {
        return [
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'schema' => $schema,
        ];
    }

    /** مُعامل استعلام من قائمة قيم مغلقة. */
    public static function queryEnum(string $name, string $description, array $values): array
    {
        return self::query($name, $description, ['type' => 'string', 'enum' => $values]);
    }

    /** مُعامل استعلام منطقي — Laravel يقبل 1/0 و true/false. */
    public static function queryBool(string $name, string $description): array
    {
        return self::query($name, $description, ['type' => 'boolean']);
    }

    /**
     * مُعاملا التصفّح. المسارات التي تقرأ per_page تمرّر حدّها الأعلى،
     * وما لا يقرؤه يُثبّت حجم صفحته في الشيفرة فيُذكر العدد وحده.
     */
    public static function pagination(int $perPage, ?int $max = null): array
    {
        $params = [
            self::query('page', 'رقم الصفحة، يبدأ من ١.', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
        ];

        if ($max !== null) {
            $params[] = self::query(
                'per_page',
                "عدد السطور في الصفحة (الافتراضي {$perPage}، والأعلى {$max}).",
                ['type' => 'integer', 'minimum' => 1, 'maximum' => $max, 'default' => $perPage],
            );
        }

        return $params;
    }

    /** مخطَّط كائن. */
    public static function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** مخطَّط مصفوفة. */
    public static function arrayOf(array $items, array $extra = []): array
    {
        return ['type' => 'array', 'items' => $items] + $extra;
    }

    /** جسم طلب بصيغة JSON. */
    public static function body(array $properties, array $required = [], ?string $description = null): array
    {
        return array_filter([
            'required' => true,
            'description' => $description,
            'content' => ['application/json' => ['schema' => self::object($properties, $required)]],
        ], fn ($value) => $value !== null);
    }

    /** جسم طلب بصيغة JSON، كلّ حقوله اختيارية. */
    public static function optionalBody(array $properties, ?string $description = null): array
    {
        return array_filter([
            'required' => false,
            'description' => $description,
            'content' => ['application/json' => ['schema' => self::object($properties)]],
        ], fn ($value) => $value !== null);
    }

    /** جسم طلب برفع ملفات — multipart/form-data. */
    public static function form(array $properties, array $required = [], ?string $description = null): array
    {
        return array_filter([
            'required' => true,
            'description' => $description,
            'content' => ['multipart/form-data' => ['schema' => self::object($properties, $required)]],
        ], fn ($value) => $value !== null);
    }

    /** ملف مرفوع. */
    public static function file(string $description): array
    {
        return ['type' => 'string', 'format' => 'binary', 'description' => $description];
    }

    /** استجابة ٢٠٠ بمخطَّط. */
    public static function ok(array $schema, string $description = 'نجح الطلب.'): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /** استجابة ٢٠١ بمخطَّط. */
    public static function created(array $schema, string $description = 'أُنشئ المورد.'): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /** استجابة لا تحمل إلّا سطر النجاح الذي تعرضه الواجهة. */
    public static function message(string $description, array $extra = []): array
    {
        return self::ok(
            self::object(['message' => ['type' => 'string', 'description' => 'نصّ النجاح، جاهز للعرض.']] + $extra),
            $description,
        );
    }

    /** مخطَّط مبلغ: القيمة الخام للحساب، والنصّ المنسَّق للعرض. */
    public static function money(): array
    {
        return self::ref('Money');
    }

    /**
     * مُعاملات DataTables للمعالجة في الخادم.
     *
     * المكتبة ترسلها كلّها في كلّ نداء، وهي كثيرة ومتداخلة — فتُوصَف
     * هنا مرّةً ويُشار إليها، كي لا يتكرّر وصفها في سبعة عشر مساراً.
     */
    public static function dataTableParams(array $sortable = [], array $filterable = []): array
    {
        $note = $sortable === []
            ? ''
            : ' الأعمدة التي يجوز الترتيب بها: `'.implode('`، `', $sortable).'`.';

        $filters = $filterable === []
            ? ''
            : ' والتي يجوز تصفيتها بالعمود: `'.implode('`، `', $filterable).'`.';

        return [
            self::query('draw', 'عدّاد النداء. يعود كما أُرسل — عدداً صحيحاً — فتعرف المكتبة أنّ الردّ لطلبها لا لطلبٍ سبقه.', ['type' => 'integer', 'default' => 1]),
            self::query('start', 'أوّل سطرٍ مطلوب، من الصفر.', ['type' => 'integer', 'minimum' => 0, 'default' => 0]),
            self::query('length', 'عدد السطور. و‎-1‎ تعني «الكلّ» عند المكتبة، وهي هنا سقف الصفحة لا إلغاؤه.', ['type' => 'integer', 'default' => 25]),
            self::query('search[value]', 'بحثٌ عامّ في كلّ الأعمدة القابلة للبحث.'),
            self::query('columns[i][data]', 'مفتاح العمود رقم i — به يُعرَف أيّ عمودٍ يُرتَّب أو يُصفّى.'.$note.$filters),
            self::query('columns[i][search][value]', 'تصفية العمود رقم i وحده.'),
            self::query('order[0][column]', 'فهرس العمود المرتَّب به، من `columns`.', ['type' => 'integer']),
            self::queryEnum('order[0][dir]', 'اتجاه الترتيب.', ['asc', 'desc']),
        ];
    }

    /** استجابة DataTables: ما تنتظره المكتبة بالضبط. */
    public static function dataTable(array $item, array $extra = []): array
    {
        return self::object([
            'draw' => ['type' => 'integer', 'description' => 'عدّاد النداء كما أُرسل.'],
            'recordsTotal' => ['type' => 'integer', 'description' => 'عدد السطور قبل أيّ بحثٍ أو تصفية، داخل نطاقك أنت.'],
            'recordsFiltered' => ['type' => 'integer', 'description' => 'عددها بعدهما — ومنه تُحسَب الصفحات.'],
            'data' => self::arrayOf($item),
        ] + $extra);
    }

    /**
     * صفحة من قائمة بناها المتحكّم بيده: {data, meta} و meta ثلاثة حقول.
     */
    public static function page(array $item, array $extra = []): array
    {
        return self::object([
            'data' => self::arrayOf($item),
            'meta' => self::ref('PageMeta'),
        ] + $extra);
    }

    /**
     * صفحة من مجموعة JsonResource: Laravel يضيف links و meta أوسع.
     */
    public static function resourcePage(array $item): array
    {
        return self::object([
            'data' => self::arrayOf($item),
            'links' => self::ref('ResourceLinks'),
            'meta' => self::ref('ResourceMeta'),
        ]);
    }

    /** قائمة كاملة بلا تصفّح، تحت مفتاح واحد. */
    public static function listing(string $key, array $item, array $extra = []): array
    {
        return self::object([$key => self::arrayOf($item)] + $extra);
    }

    /** مخطَّط حقل مترجَم: نصّ بلغة الطلب، ومعه الترجمات كاملة. */
    public static function translatable(string $max = '191'): array
    {
        return self::object([
            'ar' => ['type' => 'string', 'maxLength' => (int) $max, 'description' => 'النصّ العربي — إلزامي.'],
            'en' => ['type' => 'string', 'maxLength' => (int) $max, 'nullable' => true, 'description' => 'النصّ الإنجليزي — اختياري.'],
        ], ['ar']);
    }

    /** مبلغ صحيح بالوحدة الصغرى. */
    public static function minor(string $description, int $min = 0): array
    {
        return [
            'type' => 'integer',
            'minimum' => $min,
            'description' => $description.' — بالوحدة الصغرى (قرش/سنت)، عددٌ صحيح.',
        ];
    }
}
