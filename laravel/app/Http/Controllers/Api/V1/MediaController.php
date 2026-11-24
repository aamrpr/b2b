<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Support\Media\ImageThumbnail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * صور الأصناف والمنتجات.
 *
 * الأصناف تخصّ المنصة كلها فتديرها إدارتها؛ وصور المنتج يرفعها مالكه.
 * الملف القديم يُحذف عند الاستبدال حتى لا يتراكم التخزين بملفات يتيمة.
 */
class MediaController extends Controller
{
    private const MAX_KB = 4096;

    public function uploadCategoryImage(Request $request, Category $category): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB],
        ]);

        $previous = $category->image_path;
        $path = $request->file('image')->store('categories', 'public');
        ImageThumbnail::make($path);

        $category->update(['image_path' => $path]);

        $this->forget($previous);

        return response()->json([
            'message' => __('تم رفع صورة الصنف.'),
            'image_url' => asset('storage/'.$category->image_path),
            'image_thumb_url' => ImageThumbnail::url($category->image_path),
        ]);
    }

    public function deleteCategoryImage(Category $category): JsonResponse
    {
        $this->forget($category->image_path);

        $category->update(['image_path' => null]);

        return response()->json(['message' => __('حُذفت صورة الصنف.')]);
    }

    public function uploadProductImages(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->tenant_id === $request->user()->tenant_id, 403);

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB],
        ]);

        $sort = (int) $product->media()->max('sort_order');
        $created = [];

        foreach ($request->file('images') as $file) {
            $path = $file->store('products', 'public');
            ImageThumbnail::make($path);

            $media = ProductMedia::create([
                'product_id' => $product->id,
                'path' => $path,
                'type' => 'image',
                'sort_order' => ++$sort,
            ]);

            $created[] = [
                'id' => $media->id,
                'url' => asset('storage/'.$media->path),
                'thumb_url' => ImageThumbnail::url($media->path),
            ];
        }

        return response()->json([
            'message' => __('رُفعت :count صورة.', ['count' => count($created)]),
            'images' => $created,
        ], 201);
    }

    public function deleteProductImage(Request $request, Product $product, ProductMedia $media): JsonResponse
    {
        abort_unless($product->tenant_id === $request->user()->tenant_id, 403);
        abort_unless($media->product_id === $product->id, 404);

        $this->forget($media->path);
        $media->delete();

        return response()->json(['message' => __('حُذفت الصورة.')]);
    }

    /** ترتيب الصور: الأولى هي صورة الغلاف. */
    public function reorderProductImages(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        foreach ($data['ids'] as $index => $id) {
            ProductMedia::where('product_id', $product->id)
                ->where('id', $id)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['message' => __('تم ترتيب الصور.')]);
    }

    private function forget(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        ImageThumbnail::forget($path);
    }
}
