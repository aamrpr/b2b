<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Catalog\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /** شجرة الأصناف: قطع سيارات، منزليات، أثاث، هدايا وألعاب... */
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = Category::active()
            ->roots()
            ->with(['children' => fn ($q) => $q->active()])
            ->orderBy('sort_order')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function show(string $slug): CategoryResource
    {
        $category = Category::active()
            ->where('slug', $slug)
            ->with(['children' => fn ($q) => $q->active()])
            ->firstOrFail();

        return new CategoryResource($category);
    }
}
