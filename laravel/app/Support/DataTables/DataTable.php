<?php

namespace App\Support\DataTables;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * بروتوكول DataTables للمعالجة في الخادم.
 *
 * المكتبة ترسل شكلاً خاصاً بها — draw و start و length، وبحثاً عاماً،
 * وبحثاً لكل عمود، وترتيباً بفهرس العمود — وتنتظر
 * {draw, recordsTotal, recordsFiltered, data}. فهذا الصنف يترجم بينها
 * وبين استعلام Eloquent.
 *
 * وأهمّ ما فيه القوائم البيضاء: اسم العمود يأتي من المتصفّح، فلو مرّ
 * إلى orderBy أو where كما هو لصار بابَ حقنٍ في الاستعلام. فلا يُقبَل
 * إلّا ما أعلنه المتحكّم في sortable/searchable، وما سواه يُهمَل صامتاً.
 */
class DataTable
{
    /** @var array<string, string> مفتاح العمود في الواجهة ⟵ عمود القاعدة */
    private array $sortable = [];

    /** @var array<string, string> */
    private array $searchable = [];

    /** @var array<string, string> حقول JSON المترجمة: تُبحَث بلغتيها */
    private array $translated = [];

    /** @var array<string, Closure> أعمدة لها منطق بحثٍ خاصّ */
    private array $filters = [];

    /**
     * شروط تُضاف إلى البحث العام بـ or.
     * للاستعلامات التي لا يكفي فيها LIKE على عمود: سعرٌ مخزَّن بالوحدة
     * الصغرى، أو حالةٌ يبحثها المستخدم بنصّها لا بمفتاحها.
     *
     * @var array<int, Closure>
     */
    private array $globalSearch = [];

    private ?Closure $transform = null;

    /** @var array<string, mixed> مفاتيح زائدة ترافق الاستجابة — المكتبة تتجاهل ما لا تعرفه */
    private array $extra = [];

    private string $defaultColumn = 'id';

    private string $defaultDirection = 'desc';

    private int $maxLength = 200;

    /*
     * سقف التصدير أعلى من سقف الصفحة ولا يُلغى: التصدير يأخذ ما يطابق
     * البحث كلّه لا الصفحة المعروضة — وإلّا كان الملفّ كاذباً — لكنّ
     * «الكلّ» بلا حدٍّ في جدولٍ كبير يُسقط الخادم. فهذا هو الحدّ، وحين
     * يُبلَغ يُقال ذلك في الاستجابة لا يُكتَم.
     */
    private int $exportLimit = 5000;

    private function __construct(
        private readonly Request $request,
        private readonly Builder|QueryBuilder $query,
    ) {}

    public static function make(Request $request, Builder|QueryBuilder $query): self
    {
        return new self($request, $query);
    }

    /** أعمدة يجوز الترتيب بها: مفتاح الواجهة ⟵ عمود القاعدة. */
    public function sortable(array $columns): self
    {
        $this->sortable = array_merge($this->sortable, self::normalize($columns));

        return $this;
    }

    /** أعمدة يجوز البحث فيها، عامّاً وبالعمود. */
    public function searchable(array $columns): self
    {
        $this->searchable = array_merge($this->searchable, self::normalize($columns));

        return $this;
    }

    /** حقول JSON المترجمة — يُبحَث في العربية والإنجليزية معاً. */
    public function translated(array $columns): self
    {
        $this->translated = array_merge($this->translated, self::normalize($columns));

        return $this;
    }

    /**
     * شرطٌ داخل البحث العام. يُستدعى بـ (query, term) ضمن مجموعة الـ or،
     * فعلى الدالة أن تضيف شرطها بـ orWhere لا بـ where.
     */
    public function alsoSearch(Closure $apply): self
    {
        $this->globalSearch[] = $apply;

        return $this;
    }

    /**
     * عمودٌ بحثه ليس LIKE: حالة من قائمة، أو معرّف، أو مدى تواريخ.
     * يتلقّى الاستعلام والقيمة كما أرسلها المتصفّح.
     */
    public function filter(string $column, Closure $apply): self
    {
        $this->filters[$column] = $apply;

        return $this;
    }

    /** الترتيب حين لا ترسل المكتبة ترتيباً. */
    public function defaultOrder(string $column, string $direction = 'desc'): self
    {
        $this->defaultColumn = $column;
        $this->defaultDirection = $direction === 'asc' ? 'asc' : 'desc';

        return $this;
    }

    /** أقصى عدد سطورٍ في الصفحة — يحمي من length=-1 على جدولٍ كبير. */
    public function maxLength(int $rows): self
    {
        $this->maxLength = $rows;

        return $this;
    }

    /** أقصى عدد سطورٍ يُصدَّر في المرّة الواحدة. */
    public function exportLimit(int $rows): self
    {
        $this->exportLimit = $rows;

        return $this;
    }

    /**
     * مفاتيح زائدة في الاستجابة — عملة الدفتر مثلاً. المكتبة تقرأ ما
     * تعرفه وتتجاهل ما سواه، فلا حاجة إلى نداءٍ ثانٍ لحقلٍ واحد.
     */
    public function with(array $extra): self
    {
        $this->extra = array_merge($this->extra, $extra);

        return $this;
    }

    /** شكل السطر كما تقرؤه الواجهة. */
    public function transform(Closure $transform): self
    {
        $this->transform = $transform;

        return $this;
    }

    /** الاستجابة كما تنتظرها المكتبة. */
    public function toArray(): array
    {
        $total = (clone $this->query)->toBase()->getCountForPagination();

        $this->applySearch();

        $filtered = (clone $this->query)->toBase()->getCountForPagination();

        $this->applyOrder();

        $rows = $this->query
            ->skip($this->isExport() ? 0 : $this->start())
            ->take($this->length())
            ->get();

        return [
            // يُعاد كعددٍ صحيح لا كما أُرسل: نصٌّ يعود كما جاء بابُ XSS
            'draw' => (int) $this->request->input('draw', 1),
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            // يقول للتصدير إنّ ما عاد أقلّ ممّا طابق، فلا يدّعي الملفّ تمامه
            'truncated' => $this->isExport() && $filtered > $this->exportLimit,
            'data' => $this->transform
                ? $rows->map($this->transform)->values()
                : $rows->values(),
            ...$this->extra,
        ];
    }

    /* ---------- البحث ---------- */

    private function applySearch(): void
    {
        $global = trim((string) $this->request->input('search.value', ''));

        if ($global !== '') {
            $this->query->where(function ($query) use ($global) {
                foreach ($this->searchable as $column) {
                    $query->orWhere($column, 'like', "%{$global}%");
                }

                foreach ($this->translated as $column) {
                    $this->whereTranslatedLike($query, $column, $global, 'or');
                }

                foreach ($this->globalSearch as $callback) {
                    $callback($query, $global);
                }
            });
        }

        foreach ($this->columns() as $column) {
            $key = $column['data'] ?? null;
            $value = trim((string) ($column['search']['value'] ?? ''));

            if ($key === null || $value === '') {
                continue;
            }

            if (isset($this->filters[$key])) {
                ($this->filters[$key])($this->query, $value);

                continue;
            }

            if (isset($this->translated[$key])) {
                $this->whereTranslatedLike($this->query, $this->translated[$key], $value);

                continue;
            }

            if (isset($this->searchable[$key])) {
                $this->query->where($this->searchable[$key], 'like', "%{$value}%");
            }
        }
    }

    /** البحث في حقل JSON المترجَم بلغتيه معاً. */
    private function whereTranslatedLike($query, string $column, string $term, string $boolean = 'and'): void
    {
        $clause = function ($query) use ($column, $term) {
            foreach (['ar', 'en'] as $locale) {
                $query->orWhereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(`{$column}`, '$.{$locale}')) LIKE ?",
                    ["%{$term}%"],
                );
            }
        };

        $boolean === 'or' ? $query->orWhere($clause) : $query->where($clause);
    }

    /* ---------- الترتيب ---------- */

    private function applyOrder(): void
    {
        $columns = $this->columns();
        $ordered = false;

        foreach ((array) $this->request->input('order', []) as $order) {
            $index = $order['column'] ?? null;
            $key = $index !== null ? ($columns[$index]['data'] ?? null) : null;

            if ($key === null || ! isset($this->sortable[$key])) {
                continue;
            }

            $direction = strtolower((string) ($order['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            $this->query->orderBy($this->sortable[$key], $direction);
            $ordered = true;
        }

        if (! $ordered) {
            $this->query->orderBy(
                $this->sortable[$this->defaultColumn] ?? $this->defaultColumn,
                $this->defaultDirection,
            );
        }
    }

    /* ---------- قراءة الطلب ---------- */

    /** @return array<int, array> */
    private function columns(): array
    {
        return array_values((array) $this->request->input('columns', []));
    }

    private function start(): int
    {
        return max(0, (int) $this->request->input('start', 0));
    }

    private function length(): int
    {
        // التصدير يأخذ ما طابق كلّه إلى سقفه، ولا يقرأ length المرسَل:
        // «صدّر» لا تعني «صدّر الصفحة التي أنا فيها»
        if ($this->isExport()) {
            return $this->exportLimit;
        }

        $length = (int) $this->request->input('length', 25);

        // ‎-1‎ تعني «الكلّ» عند المكتبة، وهي هنا سقف الصفحة لا إلغاؤه
        return $length < 1 ? $this->maxLength : min($length, $this->maxLength);
    }

    /** نداء تصدير: يأخذ ما طابق البحث كلّه حتى سقف التصدير. */
    private function isExport(): bool
    {
        return $this->request->boolean('export');
    }

    /** يقبل ['sku'] اختصاراً لـ ['sku' => 'sku']. */
    private static function normalize(array $columns): array
    {
        $normalized = [];

        foreach ($columns as $key => $value) {
            is_int($key) ? $normalized[$value] = $value : $normalized[$key] = $value;
        }

        return $normalized;
    }
}
