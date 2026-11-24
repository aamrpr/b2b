<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Banner;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * بنرات الصفحة الرئيسية.
 *
 * النص بالعربية والإنجليزية، والصورة حتى ٤ ميغابايت، والرابط مسار
 * داخل المنصة لا عنواناً خارجياً. التعطيل يخفيها دون حذفها.
 */
class BannerAdminController extends Controller
{
    private const MAX_KB = 4096;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $banners = Banner::query()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'data' => $banners->map(fn (Banner $banner) => $this->present($banner)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBanner($request, creating: true);

        $banner = Banner::create([
            'eyebrow' => $this->translations($data['eyebrow'] ?? null),
            'title' => $this->translations($data['title']),
            'text' => $this->translations($data['text'] ?? null),
            'href' => $data['href'],
            'image_path' => $request->file('image')->store('banners', 'public'),
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->audit->record('banner.created', $banner, [], ['title' => $banner->t('title')]);

        return response()->json(['message' => __('تمت إضافة البنر.'), 'id' => $banner->id], 201);
    }

    public function update(Request $request, Banner $banner): JsonResponse
    {
        $data = $this->validateBanner($request, creating: false);
        $previous = ['title' => $banner->t('title'), 'href' => $banner->href, 'is_active' => $banner->is_active];

        if (array_key_exists('eyebrow', $data)) {
            $banner->eyebrow = $this->translations($data['eyebrow']);
        }

        if (isset($data['title'])) {
            $banner->title = $this->translations($data['title']);
        }

        if (array_key_exists('text', $data)) {
            $banner->text = $this->translations($data['text']);
        }

        if (isset($data['href'])) {
            $banner->href = $data['href'];
        }

        if (array_key_exists('sort_order', $data)) {
            $banner->sort_order = $data['sort_order'] ?? $banner->sort_order;
        }

        if (array_key_exists('is_active', $data)) {
            $banner->is_active = (bool) $data['is_active'];
        }

        $banner->save();

        $this->audit->record('banner.updated', $banner, $previous, [
            'title' => $banner->t('title'),
            'href' => $banner->href,
            'is_active' => $banner->is_active,
        ]);

        return response()->json(['message' => __('تم حفظ البنر.')]);
    }

    public function image(Request $request, Banner $banner): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB],
        ]);

        $previous = $banner->image_path;
        $banner->update([
            'image_path' => $request->file('image')->store('banners', 'public'),
        ]);
        $this->forget($previous);

        return response()->json([
            'message' => __('تم رفع صورة البنر.'),
            'image_url' => asset('storage/'.$banner->image_path),
        ]);
    }

    public function destroy(Banner $banner): JsonResponse
    {
        $title = $banner->t('title');
        $this->forget($banner->image_path);
        $banner->delete();

        $this->audit->record('banner.deleted', $banner, ['title' => $title], []);

        return response()->json(['message' => __('تم حذف البنر.')]);
    }

    private function present(Banner $banner): array
    {
        return [
            'id' => $banner->id,
            'eyebrow' => $banner->t('eyebrow'),
            'eyebrow_translations' => $banner->getAttribute('eyebrow') ?? new \stdClass,
            'title' => $banner->t('title'),
            'title_translations' => $banner->getAttribute('title') ?? new \stdClass,
            'text' => $banner->t('text'),
            'text_translations' => $banner->getAttribute('text') ?? new \stdClass,
            'href' => $banner->href,
            'image_url' => $banner->image_path ? asset('storage/'.$banner->image_path) : null,
            'sort_order' => (int) $banner->sort_order,
            'is_active' => (bool) $banner->is_active,
        ];
    }

    private function validateBanner(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'eyebrow' => ['nullable', 'array'],
            'eyebrow.ar' => ['nullable', 'string', 'max:80'],
            'eyebrow.en' => ['nullable', 'string', 'max:80'],
            'title' => [$required, 'array'],
            'title.ar' => [$required, 'string', 'max:160'],
            'title.en' => ['nullable', 'string', 'max:160'],
            'text' => ['nullable', 'array'],
            'text.ar' => ['nullable', 'string', 'max:300'],
            'text.en' => ['nullable', 'string', 'max:300'],
            'href' => [$required, 'string', 'max:255', 'regex:/^\/(?!\/)\S+$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
            'image' => [$creating ? 'required' : 'exclude', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB],
        ]);
    }

    private function translations(?array $field): ?array
    {
        $arabic = trim((string) ($field['ar'] ?? ''));
        $english = trim((string) ($field['en'] ?? ''));

        if ($arabic === '' && $english === '') {
            return null;
        }

        if ($arabic === '') {
            $arabic = $english;
        }

        if ($english === '') {
            $english = $arabic;
        }

        return ['ar' => $arabic, 'en' => $english];
    }

    private function forget(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
