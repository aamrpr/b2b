<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\SupplierResource;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * دليل المستوردين — المسار (أ) في السيناريو:
 * تاجر الجملة يدخل فئة "قطع سيارات" فيرى المستوردين المسجّلين فيها.
 */
class SupplierDirectoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'category' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'verified_only' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Tenant::query()
            ->where('type', Tenant::TYPE_SUPPLIER)
            ->where('status', 'active')
            ->with(['supplierProfile', 'categories.category'])
            ->withCount(['products' => fn ($q) => $q->where('status', 'active')]);

        if ($slug = $request->query('category')) {
            $category = Category::where('slug', $slug)->firstOrFail();
            $ids = $category->descendantIds();

            $query->whereHas('categories', fn ($q) => $q->whereIn('category_id', $ids));
        }

        if ($city = $request->query('city')) {
            $query->where('city', $city);
        }

        if ($request->boolean('verified_only')) {
            $query->whereHas('supplierProfile', fn ($q) => $q->where('verification_status', 'verified'));
        }

        if ($term = $request->query('q')) {
            $query->where('name', 'like', "%{$term}%");
        }

        $suppliers = $query
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return SupplierResource::collection($suppliers);
    }

    public function show(string $slug): SupplierResource
    {
        $supplier = Tenant::query()
            ->where('type', Tenant::TYPE_SUPPLIER)
            ->where('slug', $slug)
            ->with(['supplierProfile', 'categories.category'])
            ->withCount(['products' => fn ($q) => $q->where('status', 'active')])
            ->firstOrFail();

        return new SupplierResource($supplier);
    }

    /** منتجات مستورد معيّن — ما يتصفّحه تاجر الجملة قبل مراسلته. */
    public function products(Request $request, string $slug): AnonymousResourceCollection
    {
        $supplier = Tenant::where('type', Tenant::TYPE_SUPPLIER)->where('slug', $slug)->firstOrFail();

        $products = Product::query()
            ->where('tenant_id', $supplier->id)
            ->active()
            ->with(['category', 'priceTiers', 'media'])
            ->paginate(min((int) $request->query('per_page', 24), 100));

        return ProductResource::collection($products);
    }
}
