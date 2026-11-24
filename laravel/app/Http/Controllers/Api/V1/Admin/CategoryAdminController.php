<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Services\Admin\AuditLogger;
use App\Support\Media\ImageThumbnail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * شجرة الأصناف.
 *
 * الأصناف ملك المنصة لا المستوردين: بها يُصنَّف الكتالوج، وإليها تُرسل
 * طلبات الأسعار، وعليها يُبنى تسجيل المستورد. فتعديلها هنا، وحذفها
 * مشروطٌ بخلوّها — صنفٌ يُحذف تحته منتجات يترك كتالوجاً بلا باب.
 */
class CategoryAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * الشجرة كاملةً للإدارة: المعطَّل يظهر هنا وإن غاب عن السوق.
     *
     * القائمة العامة تُرجع الفعّال وحده — وهو الصواب للمتصفّح — فلو
     * قرأت الإدارة منها لاختفى عنها ما عطّلته بنفسها فلم تستطع إعادته.
     */
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->orderBy('sort_order')])
            ->withCount('products')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (Category $category) => $this->present($category)),
        ]);
    }

    private function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->t('name'),
            'name_translations' => $category->getAttribute('name') ?? new \stdClass,
            'slug' => $category->slug,
            'icon' => $category->icon,
            'image_url' => $category->image_path ? asset('storage/'.$category->image_path) : null,
            'image_thumb_url' => ImageThumbnail::url($category->image_path),
            'is_active' => (bool) $category->is_active,
            'is_featured' => (bool) $category->is_featured,
            'sort_order' => (int) $category->sort_order,
            'products_count' => $category->products_count ?? null,
            'children' => $category->relationLoaded('children')
                ? $category->children->map(fn (Category $child) => $this->present($child))
                : [],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateCategory($request);

        $category = Category::create([
            'parent_id' => $data['parent_id'] ?? null,
            'name' => ['ar' => $data['name']['ar'], 'en' => ($data['name']['en'] ?? null) ?: $data['name']['ar']],
            'slug' => $this->slug($data),
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'is_featured' => $data['is_featured'] ?? false,
        ]);

        $this->audit->record('category.created', $category, [], ['name' => $category->t('name')]);

        return response()->json(['message' => __('تمت إضافة الصنف.'), 'id' => $category->id], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $this->validateCategory($request, partial: true);
        $previous = ['name' => $category->t('name'), 'is_active' => $category->is_active];

        $category->fill(array_filter([
            'icon' => $data['icon'] ?? $category->icon,
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
        ], fn ($value) => $value !== null));

        // الأب يُثبَّت عند الإنشاء. تغييره لاحقاً ينقل منتجات الصنف من فرعٍ إلى آخر بلا أثرٍ واضح.
        unset($data['parent_id']);

        if (isset($data['name'])) {
            $category->name = [
                'ar' => $data['name']['ar'],
                'en' => ($data['name']['en'] ?? null) ?: $data['name']['ar'],
            ];
        }

        // المفاتيح المنطقية تُكتب صراحةً: array_filter يبتلع false
        foreach (['is_active', 'is_featured'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $category->{$flag} = $data[$flag];
            }
        }

        $category->save();

        $this->audit->record('category.updated', $category, $previous,
            ['name' => $category->t('name'), 'is_active' => $category->is_active],
        );

        return response()->json(['message' => __('تم حفظ الصنف.')]);
    }

    public function destroy(Category $category): JsonResponse
    {
        abort_if(
            Category::where('parent_id', $category->id)->exists(),
            422,
            __('لا يمكن حذف صنف تحته فئات فرعية.'),
        );

        abort_if(
            Product::where('category_id', $category->id)->exists(),
            422,
            __('لا يمكن حذف صنف تحته منتجات.'),
        );

        $name = $category->t('name');
        $category->delete();

        $this->audit->record('category.deleted', $category, ['name' => $name], []);

        return response()->json(['message' => __('تم حذف الصنف.')]);
    }

    private function validateCategory(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'array'],
            'name.ar' => [$required, 'string', 'max:120'],
            'name.en' => ['nullable', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
        ]);
    }

    private function slug(array $data): string
    {
        $base = Str::slug($data['name']['en'] ?? $data['name']['ar']) ?: 'category';

        return Category::where('slug', $base)->exists()
            ? $base.'-'.Str::lower(Str::random(5))
            : $base;
    }
}
