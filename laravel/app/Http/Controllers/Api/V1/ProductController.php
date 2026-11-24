<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Accounting\Currency;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Orders\OrderItem;
use App\Models\Tenancy\Tenant;
use App\Support\DataTables\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /** تصفّح السوق — منتجات كل المستوردين، ومعها عدّادات عمود التصفية. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $sort = match ($request->query('sort')) {
            'price_asc' => ['base_price', 'asc'],
            'price_desc' => ['base_price', 'desc'],
            'popular' => ['views_count', 'desc'],
            default => ['created_at', 'desc'],
        };

        $products = $this->catalog($request)
            ->with(['category', 'tenant.supplierProfile', 'priceTiers', 'media'])
            ->orderBy($sort[0], $sort[1])
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 24), 100));

        return ProductResource::collection($products)->additional([
            'facets' => $this->facets($request),
        ]);
    }

    /**
     * عشرة المنتجات الأكثر مبيعاً في الرئيسية.
     *
     * المبيع هنا كمية سُلّمت في طلبٍ قائم، لا مشاهدات الصفحة: المسودّة
     * وما ينتظر الموافقة والملغى لا تُحسب، والمرتجع المستلم يُخصم.
     * والتعادل يُحسم بالأحدث بيعاً.
     */
    public function bestsellers(): AnonymousResourceCollection
    {
        $sold = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', ['confirmed', 'in_production', 'shipped', 'delivered', 'closed'])
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id, SUM(order_items.quantity) as sold_qty, MAX(COALESCE(orders.delivered_at, orders.shipped_at, orders.confirmed_at, orders.created_at)) as last_sale')
            ->get()
            ->keyBy('product_id');

        $returned = OrderItem::query()
            ->join('return_request_items', 'return_request_items.order_item_id', '=', 'order_items.id')
            ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
            ->whereIn('return_requests.status', ['received', 'completed'])
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id, SUM(CASE WHEN return_request_items.received_quantity > 0 THEN return_request_items.received_quantity ELSE return_request_items.approved_quantity END) as returned_qty')
            ->pluck('returned_qty', 'product_id');

        $ranking = $sold
            ->map(function ($row) use ($returned) {
                $net = (int) $row->sold_qty - (int) ($returned[$row->product_id] ?? 0);

                return [
                    'product_id' => (int) $row->product_id,
                    'net' => $net,
                    'last_sale' => (string) $row->last_sale,
                ];
            })
            ->filter(fn (array $row) => $row['net'] > 0)
            ->sort(fn (array $a, array $b) => [$b['net'], $b['last_sale']] <=> [$a['net'], $a['last_sale']])
            ->take(10)
            ->values();

        $ids = $ranking->pluck('product_id')->all();

        $products = Product::query()
            ->active()
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->with(['category', 'tenant.supplierProfile', 'priceTiers', 'media'])
            ->get()
            ->sortBy(fn (Product $product) => array_search($product->id, $ids, true))
            ->values();

        return ProductResource::collection($products);
    }

    /**
     * المنتجات المعروضة بعد التصفية.
     *
     * كل عمود في الشريط يُحسب بلا قيده هو: اختيار ماركة لا يُخفي سائر
     * الماركات، واختيار صنف لا يُصفر عدّاد إخوته.
     *
     * @param  list<string>  $except
     */
    private function catalog(Request $request, array $except = []): Builder
    {
        $query = Product::query()->active();

        if (! in_array('q', $except, true)) {
            $term = trim((string) $request->query('q', ''));

            if ($term !== '') {
                $query->where(function ($inner) use ($term) {
                    $inner->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar')) LIKE ?", ["%{$term}%"])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) LIKE ?", ["%{$term}%"])
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhere('brand', 'like', "%{$term}%");
                });
            }
        }

        if (! in_array('category', $except, true)) {
            $this->whereCategories($query, $this->terms($request->query('category')));
        }

        if (! in_array('brand', $except, true)) {
            $brands = $this->terms($request->query('brand'));

            if ($brands !== []) {
                $query->whereIn('brand', $brands);
            }
        }

        if (! in_array('city', $except, true)) {
            $cities = $this->terms($request->query('city'));

            if ($cities !== []) {
                $query->whereHas('tenant', fn ($tenant) => $tenant->whereIn('city', $cities));
            }
        }

        if (! in_array('price', $except, true)) {
            if ($request->filled('price_min')) {
                $query->where('base_price', '>=', max(0, (int) $request->query('price_min')));
            }

            if ($request->filled('price_max')) {
                $query->where('base_price', '<=', max(0, (int) $request->query('price_max')));
            }
        }

        if ($supplierId = $request->query('supplier_id')) {
            $query->where('tenant_id', $supplierId);
        }

        $this->whereAttributes($query, $request, $except);

        return $query;
    }

    /** @param  list<string>  $slugs */
    private function whereCategories(Builder $query, array $slugs): void
    {
        if ($slugs === []) {
            return;
        }

        $ids = [];

        foreach (Category::query()->whereIn('slug', $slugs)->get() as $category) {
            $ids = array_merge($ids, $category->descendantIds());
        }

        $query->whereIn('category_id', array_values(array_unique($ids)) ?: [0]);
    }

    /** @return list<string> */
    private function terms(mixed $value): array
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(
            fn ($part) => trim((string) $part),
            $parts,
        ), fn ($part) => $part !== ''));
    }

    private function facets(Request $request): array
    {
        $counts = $this->catalog($request, ['category'])
            ->selectRaw('category_id, COUNT(*) as aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id');

        $roots = Category::query()
            ->active()
            ->roots()
            ->with(['children' => fn ($query) => $query->active()->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $categories = $roots->map(function (Category $root) use ($counts) {
            $children = $root->children->map(fn (Category $child) => [
                'slug' => $child->slug,
                'name' => $child->t('name'),
                'count' => (int) ($counts[$child->id] ?? 0),
            ])->values();

            return [
                'slug' => $root->slug,
                'name' => $root->t('name'),
                'count' => (int) ($counts[$root->id] ?? 0) + (int) $children->sum('count'),
                'children' => $children,
            ];
        })->values();

        $brands = $this->catalog($request, ['brand'])
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->selectRaw('brand, COUNT(*) as aggregate')
            ->groupBy('brand')
            ->orderByDesc('aggregate')
            ->orderBy('brand')
            ->get()
            ->map(fn (Product $row) => ['name' => $row->brand, 'count' => (int) $row->aggregate])
            ->values();

        $byTenant = $this->catalog($request, ['city'])
            ->selectRaw('tenant_id, COUNT(*) as aggregate')
            ->groupBy('tenant_id')
            ->pluck('aggregate', 'tenant_id');

        $cities = Tenant::query()
            ->whereIn('id', $byTenant->keys())
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->get(['id', 'city'])
            ->groupBy('city')
            ->map(fn ($group, $city) => [
                'name' => $city,
                'count' => (int) $group->sum(fn (Tenant $tenant) => (int) ($byTenant[$tenant->id] ?? 0)),
            ])
            ->sortByDesc('count')
            ->values();

        $span = $this->catalog($request, ['price'])
            ->selectRaw('MIN(base_price) as lo, MAX(base_price) as hi')
            ->first();

        $currency = Currency::query()->where('code', 'SYP')->first();

        return [
            'categories' => $categories,
            'brands' => $brands,
            'cities' => $cities,
            'attributes' => $this->attributeFacets($request),
            'price' => [
                'min' => (int) ($span->lo ?? 0),
                'max' => (int) ($span->hi ?? 0),
                'currency' => $currency->code ?? 'SYP',
                'decimals' => (int) ($currency->decimal_places ?? 2),
            ],
        ];
    }

    /**
     * الخصائص المرئية في الشريط.
     *
     * المفتاح ثابت من النص العربي (أو الإنجليزي إن غاب) بعد الطي، فاختيار
     * «12 فولت» يبقى مختاراً حين تُقلب الصفحة إلى Voltage / 12V.
     * والقيم داخل الخاصية الواحدة تُجمع (أو)، والخصائص المختلفة تُضيَّق (و).
     * وعدّاد كل خاصية يُحسب بلا قيمها هي حتى لا يختفي الخيار المجاور.
     *
     * @return list<array{name: string, key: string, values: list<array{name: string, key: string, count: int}>}>
     */
    private function attributeFacets(Request $request): array
    {
        $selected = $this->attributeSelections($request);
        $products = $this->catalog($request, ['attr'])
            ->get(['id', 'specifications'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'pairs' => $this->specPairs($product),
            ]);

        $groups = [];

        foreach ($products as $product) {
            foreach ($product['pairs'] as $pair) {
                $label = $pair['label_key'];
                $value = $pair['value_key'];
                $groups[$label]['name'] ??= $pair['label'];
                $groups[$label]['values'][$value]['name'] ??= $pair['value'];
                $groups[$label]['values'][$value]['ids'][$product['id']] = true;
            }
        }

        $facets = [];

        foreach ($groups as $label => $group) {
            $others = $selected;
            unset($others[$label]);
            $eligible = [];

            foreach ($products as $product) {
                if ($this->matchesAttributes($product['pairs'], $others)) {
                    $eligible[$product['id']] = true;
                }
            }

            $values = [];

            foreach ($group['values'] as $valueKey => $value) {
                $count = 0;

                foreach ($value['ids'] as $id => $_) {
                    if (isset($eligible[$id])) {
                        $count++;
                    }
                }

                $chosen = in_array($valueKey, $selected[$label] ?? [], true);

                if ($count === 0 && ! $chosen) {
                    continue;
                }

                $values[] = ['name' => $value['name'], 'key' => $valueKey, 'count' => $count];
            }

            if ($values === []) {
                continue;
            }

            usort($values, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

            $facets[] = [
                'name' => $group['name'],
                'key' => $label,
                'values' => $values,
                'reach' => array_sum(array_column($values, 'count')),
            ];
        }

        usort($facets, fn ($a, $b) => $b['reach'] <=> $a['reach'] ?: strcmp($a['name'], $b['name']));

        return array_map(function (array $facet) {
            unset($facet['reach']);

            return $facet;
        }, $facets);
    }

    /** @param  list<string>  $except */
    private function whereAttributes(Builder $query, Request $request, array $except): void
    {
        if (in_array('attr', $except, true)) {
            return;
        }

        $selected = $this->attributeSelections($request);

        if ($selected === []) {
            return;
        }

        $ids = (clone $query)
            ->get(['id', 'specifications'])
            ->filter(fn (Product $product) => $this->matchesAttributes($this->specPairs($product), $selected))
            ->pluck('id')
            ->all();

        $query->whereIn('id', $ids === [] ? [0] : $ids);
    }

    /**
     * الاختيارات القادمة من الواجهة: مفتاح الخاصية ثم قيمها.
     *
     * السجلات مفصولة بمحرف السجل، والخاصية عن قيمتها بمحرف الوحدة،
     * لأن النص الحر قد يحوي فاصلة أو نقطتين.
     *
     * @return array<string, list<string>>
     */
    private function attributeSelections(Request $request): array
    {
        $raw = trim((string) $request->query('attr', ''));

        if ($raw === '') {
            return [];
        }

        $selected = [];

        foreach (explode("\x1e", $raw) as $record) {
            $parts = explode("\x1f", $record, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $label = $this->specKey($parts[0]);
            $value = $this->specKey($parts[1]);

            if ($label === '' || $value === '') {
                continue;
            }

            $selected[$label][] = $value;
        }

        return array_map(
            fn (array $values) => array_values(array_unique($values)),
            $selected,
        );
    }

    /**
     * @param  list<array{label_key: string, value_key: string}>  $pairs
     * @param  array<string, list<string>>  $selected
     */
    private function matchesAttributes(array $pairs, array $selected): bool
    {
        foreach ($selected as $label => $values) {
            $hit = false;

            foreach ($pairs as $pair) {
                if ($pair['label_key'] === $label && in_array($pair['value_key'], $values, true)) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{label_key: string, value_key: string, label: string, value: string}>
     */
    private function specPairs(Product $product): array
    {
        $locale = app()->getLocale();
        $fallback = (string) config('app.fallback_locale');
        $pairs = [];
        $seen = [];

        foreach ($product->specifications ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $labelKey = $this->specKey($row['label'] ?? null);
            $valueKey = $this->specKey($row['value'] ?? null);

            if ($labelKey === '' || $valueKey === '') {
                continue;
            }

            $token = $labelKey."\x1f".$valueKey;

            if (isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $pairs[] = [
                'label_key' => $labelKey,
                'value_key' => $valueKey,
                'label' => $this->translatedText($row['label'] ?? null, $locale, $fallback),
                'value' => $this->translatedText($row['value'] ?? null, $locale, $fallback),
            ];
        }

        return $pairs;
    }

    private function specKey(mixed $value): string
    {
        if (is_string($value)) {
            $text = $value;
        } elseif (is_array($value)) {
            $arabic = trim((string) ($value['ar'] ?? ''));
            $english = trim((string) ($value['en'] ?? ''));
            $text = $arabic !== '' ? $arabic : $english;
        } else {
            $text = '';
        }

        $key = preg_replace('/[\x00-\x1F\x7F]/u', '', $this->fold($text)) ?? '';

        return trim($key);
    }

    private function translatedText(mixed $value, string $locale, string $fallback): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value) || $value === []) {
            return '';
        }

        $picked = $value[$locale] ?? $value[$fallback] ?? reset($value);

        return is_string($picked) ? $picked : '';
    }

    public function show(string $slug): ProductResource
    {
        $product = Product::active()
            ->where('slug', $slug)
            ->with(['category', 'tenant.supplierProfile', 'priceTiers', 'variants', 'media'])
            ->firstOrFail();

        $product->increment('views_count');

        return new ProductResource($product);
    }

    /** منتجاتي — لوحة المستورد. */
    public function mine(Request $request): JsonResponse
    {
        $query = Product::query()->owned()->with(['category', 'priceTiers', 'media']);

        return response()->json(
            DataTable::make($request, $query)
                ->translated(['name'])
                // كل أعمدة السجل ما عدا الصور: الرمز والاسم والعلامة،
                // والوحدة الظاهرة بجانب الحدّ الأدنى. السعر والحالة
                // يُبحثان أسفله لأنّ المخزَّن ليس ما يُكتب في مربع البحث.
                ->searchable(['sku', 'brand', 'unit_of_measure'])
                ->alsoSearch(function ($query, string $term) {
                    $query->orWhereRaw('CAST(min_order_quantity AS CHAR) LIKE ?', ["%{$term}%"]);
                    $query->orWhereRaw(
                        "CONCAT(min_order_quantity, ' ', COALESCE(unit_of_measure, '')) LIKE ?",
                        ["%{$term}%"],
                    );
                    $this->wherePrice($query, $term, or: true);
                    $statuses = $this->statusesMatching($term);
                    if ($statuses !== []) {
                        $query->orWhereIn('status', $statuses);
                    }
                })
                ->sortable([
                    'id' => 'id',
                    'sku' => 'sku',
                    'brand' => 'brand',
                    'status' => 'status',
                    'price' => 'base_price',
                    'min_order_quantity' => 'min_order_quantity',
                    'created_at' => 'created_at',
                ])
                ->filter('status', fn ($q, $value) => $q->where('status', $value))
                ->filter('category', fn ($q, $value) => $q->where('category_id', $value))
                ->filter('price', fn ($q, $value) => $this->wherePrice($q, $value, or: false))
                ->filter('min_order_quantity', function ($q, $value) {
                    $q->where(function ($inner) use ($value) {
                        $inner->where('unit_of_measure', 'like', "%{$value}%")
                            ->orWhereRaw('CAST(min_order_quantity AS CHAR) LIKE ?', ["%{$value}%"])
                            ->orWhereRaw(
                                "CONCAT(min_order_quantity, ' ', COALESCE(unit_of_measure, '')) LIKE ?",
                                ["%{$value}%"],
                            );
                    });
                })
                ->defaultOrder('id')
                ->maxLength(200)
                ->transform(fn (Product $product) => (new ProductResource($product))->resolve($request))
                ->toArray()
        );
    }

    public function store(Request $request): ProductResource
    {
        $data = $this->validateProduct($request);

        $product = Product::create([
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'slug' => Str::slug($data['name']['en'] ?? $data['name']['ar']).'-'.Str::lower(Str::random(6)),
            'sku' => $data['sku'] ?? null,
            'brand' => $data['brand'] ?? null,
            'hs_code' => $data['hs_code'] ?? null,
            'country_of_origin' => $data['country_of_origin'] ?? null,
            'unit_of_measure' => $data['unit_of_measure'] ?? 'piece',
            'min_order_quantity' => $data['min_order_quantity'] ?? 1,
            'lead_time_days' => $data['lead_time_days'] ?? 0,
            'price_visible' => $data['price_visible'] ?? true,
            'base_price' => $data['base_price'] ?? 0,
            'currency' => $data['currency'] ?? $request->user()->tenant->base_currency,
            'status' => $data['status'] ?? 'active',
            'specifications' => $this->specifications($data['specifications'] ?? []),
        ]);

        foreach ($data['price_tiers'] ?? [] as $tier) {
            $product->priceTiers()->create([
                'min_qty' => $tier['min_qty'],
                'max_qty' => $tier['max_qty'] ?? null,
                'unit_price' => $tier['unit_price'],
                'currency' => $product->currency,
            ]);
        }

        return new ProductResource($product->load(['category', 'priceTiers']));
    }

    public function update(Request $request, Product $product): ProductResource
    {
        abort_unless($product->tenant_id === $request->user()->tenant_id, 403);

        $data = $this->validateProduct($request, partial: true);

        if (array_key_exists('specifications', $data)) {
            $data['specifications'] = $this->specifications($data['specifications'] ?? []);
        }

        $product->fill($data)->save();

        return new ProductResource($product->load(['category', 'priceTiers']));
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->tenant_id === $request->user()->tenant_id, 403);

        $product->delete();

        return response()->json(['message' => __('تم حذف المنتج.')]);
    }

    private function validateProduct(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'category_id' => [$required, 'integer', 'exists:categories,id'],
            'name' => [$required, 'array'],
            'name.ar' => [$required, 'string', 'max:191'],
            'name.en' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string'],
            'description.en' => ['nullable', 'string'],
            'sku' => ['nullable', 'string', 'max:60'],
            'brand' => ['nullable', 'string', 'max:100'],
            'hs_code' => ['nullable', 'string', 'max:20'],
            'country_of_origin' => ['nullable', 'string', 'size:2'],
            'unit_of_measure' => ['nullable', 'string', 'max:20', 'exists:units,code'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'price_visible' => ['nullable', 'boolean'],
            'base_price' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'in:draft,active,out_of_stock,archived'],
            'price_tiers' => ['nullable', 'array'],
            'price_tiers.*.min_qty' => ['required_with:price_tiers', 'integer', 'min:1'],
            'price_tiers.*.max_qty' => ['nullable', 'integer', 'gt:price_tiers.*.min_qty'],
            'price_tiers.*.unit_price' => ['required_with:price_tiers', 'integer', 'min:0'],
            'specifications' => ['nullable', 'array', 'max:40'],
            'specifications.*.label.ar' => ['required', 'string', 'max:120'],
            'specifications.*.label.en' => ['nullable', 'string', 'max:120'],
            'specifications.*.value.ar' => ['required', 'string', 'max:255'],
            'specifications.*.value.en' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * السعر يُخزَّن بالوحدة الصغرى ويُعرَض بالوحدة الكبرى مع رمز العملة.
     * البحث يطابق ما يُرى: «300» تجد 300.00، و«ل.س» تجد الليرة.
     */
    private function wherePrice(Builder $query, string $term, bool $or): void
    {
        $folded = $this->fold($term);
        $number = $this->numberIn($folded);
        $codes = $this->currencyCodesMatching($folded);

        if ($number === null && $codes === []) {
            if (! $or) {
                $query->whereRaw('0 = 1');
            }

            return;
        }

        $method = $or ? 'orWhere' : 'where';
        $table = $query->getModel()->getTable();

        $query->{$method}(function ($inner) use ($number, $codes, $table) {
            $started = false;

            if ($number !== null) {
                $decimals = "COALESCE((SELECT decimal_places FROM currencies WHERE currencies.code = {$table}.currency), 2)";
                $inner->whereRaw(
                    "CAST({$table}.base_price / POW(10, {$decimals}) AS CHAR) LIKE ?",
                    ["%{$number}%"],
                );
                $inner->orWhereRaw(
                    "{$table}.base_price = ROUND(? * POW(10, {$decimals}))",
                    [(float) $number],
                );
                $started = true;
            }

            if ($codes !== []) {
                $started
                    ? $inner->orWhereIn("{$table}.currency", $codes)
                    : $inner->whereIn("{$table}.currency", $codes);
            }
        });
    }

    /** الحالة كما تُكتب في الواجهة، لا مفتاحها في القاعدة. */
    private function statusesMatching(string $term): array
    {
        $needle = $this->fold($term);

        if ($needle === '') {
            return [];
        }

        $labels = [
            'active' => ['active', 'listed', 'معروض'],
            'draft' => ['draft', 'مسودة'],
            'out_of_stock' => ['out_of_stock', 'out of stock', 'نفد'],
            'archived' => ['archived', 'مؤرشف'],
        ];

        // التطابق الجزئي من ثلاثة أحرف: «مع» وحدها لا تسحب كل المعروض
        $matched = [];

        foreach ($labels as $status => $names) {
            foreach ($names as $name) {
                $label = $this->fold($name);

                if ($label === $needle || (mb_strlen($needle) >= 3 && str_contains($label, $needle))) {
                    $matched[] = $status;
                    break;
                }
            }
        }

        return $matched;
    }

    /** @return array<int, string> */
    private function currencyCodesMatching(string $foldedTerm): array
    {
        $needle = rtrim($foldedTerm, '.');

        if ($needle === '') {
            return [];
        }

        return Currency::query()->get()->filter(function (Currency $currency) use ($needle) {
            $code = $this->fold($currency->code);
            $symbol = rtrim($this->fold((string) $currency->symbol), '.');
            $name = $currency->name;
            $wide = mb_strlen($needle) >= 3;

            return $code === $needle
                || ($wide && (str_contains($code, $needle) || str_contains($needle, $code)))
                || ($symbol !== '' && ($symbol === $needle || ($wide && (str_contains($symbol, $needle) || str_contains($needle, $symbol)))))
                || ($wide && str_contains($this->fold((string) ($name['ar'] ?? '')), $needle))
                || ($wide && str_contains($this->fold((string) ($name['en'] ?? '')), $needle));
        })->pluck('code')->all();
    }

    /** أول عدد في النص بعد توحيد الأرقام وإزالة فواصل الآلاف. */
    private function numberIn(string $folded): ?string
    {
        $latin = strtr($folded, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.',
        ]);
        $latin = str_replace([' ', '٬', '،'], '', $latin);
        $latin = preg_replace('/,(?=\d{3}(?:\D|$))/', '', $latin) ?? $latin;

        return preg_match('/\d+(?:\.\d+)?/', $latin, $match) ? $match[0] : null;
    }

    /** بلا تشكيل وبلا همزات شكلية، فالمستخدم لا يكتبها كما خُزِّنت. */
    private function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);

        return preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $value) ?? $value;
    }

    /**
     * الإنجليزية تتراجع إلى العربية كما في الاسم: المستورد السوري يكتب
     * خصائصه بالعربية، ولا يُعقل أن تختفي عن مشترٍ قرأ الصفحة بالإنجليزية.
     */
    private function specifications(array $rows): array
    {
        return collect($rows)
            ->map(fn ($row) => [
                'label' => [
                    'ar' => $row['label']['ar'],
                    'en' => ($row['label']['en'] ?? null) ?: $row['label']['ar'],
                ],
                'value' => [
                    'ar' => $row['value']['ar'],
                    'en' => ($row['value']['en'] ?? null) ?: $row['value']['ar'],
                ],
            ])
            ->values()
            ->all();
    }
}
