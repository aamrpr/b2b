<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Services\Admin\AuditLogger;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * المنتجات من عين المنصة.
 *
 * المستورد يملك كتالوجه، لكن المنصة مسؤولة عمّا يُعرض فيها: منتجٌ
 * مخالف أو مضلّل يُرفع من العرض هنا دون انتظار صاحبه. والرفع يُسجَّل
 * في سجلّ التدقيق باسم من فعله — صلاحيةٌ بلا أثر بابُ تعسّف.
 */
class CatalogAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            DataTable::make($request, Product::query()->with(['tenant.supplierProfile', 'category']))
                ->translated(['name'])
                ->searchable(['sku', 'brand', 'slug'])
                ->sortable([
                    'id' => 'id',
                    'sku' => 'sku',
                    'brand' => 'brand',
                    'status' => 'status',
                    'price' => 'base_price',
                    'created_at' => 'created_at',
                ])
                ->filter('status', fn ($query, $value) => $query->where('status', $value))
                ->filter('price', fn ($query, $value) => ColumnMatch::money($query, 'base_price', $value))
                ->filter('supplier', function ($query, $value) {
                    if (ctype_digit($value)) {
                        $query->where('tenant_id', $value);

                        return;
                    }

                    $query->where(function ($query) use ($value) {
                        $query->whereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$value}%"))
                            ->orWhereHas('tenant.supplierProfile', fn ($p) => $p->where('display_name', 'like', "%{$value}%"));
                    });
                })
                ->defaultOrder('id')
                ->transform(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->t('name'),
                    'slug' => $product->slug,
                    'sku' => $product->sku,
                    'brand' => $product->brand,
                    'status' => $product->status,
                    'price' => $product->base_price,
                    'currency' => $product->currency,
                    'category' => $product->category?->t('name'),
                    'supplier' => [
                        'id' => $product->tenant_id,
                        'name' => $product->tenant?->supplierProfile?->t('display_name') ?? $product->tenant?->name,
                    ],
                    'created_at' => $product->created_at?->toIso8601String(),
                ])
                ->toArray()
        );
    }

    public function updateStatus(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:draft,active,out_of_stock,archived'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $previous = $product->status;
        $product->update(['status' => $data['status']]);

        $this->audit->record('product.status_changed', $product,
            ['status' => $previous],
            ['status' => $data['status'], 'reason' => $data['reason'] ?? null],
        );

        return response()->json([
            'message' => __('تم تحديث حالة المنتج.'),
            'status' => $product->status,
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        // حذف ناعم: الطلبات القديمة تشير إلى المنتج، ومحوُه يقطع سندها
        $product->delete();

        $this->audit->record('product.deleted', $product,
            ['status' => $product->status, 'name' => $product->t('name')],
            ['reason' => $data['reason'] ?? null],
        );

        return response()->json(['message' => __('تم حذف المنتج.')]);
    }
}
