<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Banner;
use Illuminate\Http\JsonResponse;

/** بنرات الدعاية الفعّالة، بلغة الطلب، للصفحة الرئيسية. */
class BannerController extends Controller
{
    public function index(): JsonResponse
    {
        $banners = Banner::query()
            ->where('is_active', true)
            ->whereNotNull('image_path')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $banners->map(fn (Banner $banner) => [
                'id' => $banner->id,
                'eyebrow' => $banner->t('eyebrow'),
                'title' => $banner->t('title'),
                'text' => $banner->t('text'),
                'href' => $banner->href,
                'image_url' => asset('storage/'.$banner->image_path),
            ])->filter(fn (array $banner) => filled($banner['title']) && str_starts_with($banner['href'], '/') && ! str_starts_with($banner['href'], '//'))
                ->values(),
        ]);
    }
}
