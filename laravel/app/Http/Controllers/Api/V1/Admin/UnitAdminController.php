<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Unit;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * وحدات القياس.
 *
 * الرمز لاتينيّ ثابت يُحفظ مع المنتج وطلب السعر. والاسم مترجم، فيُختار
 * من قائمة عند إضافة المنتج، ولا يُعاد كتابته في الطلب.
 */
class UnitAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $units = Unit::query()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'data' => $units->map(fn (Unit $unit) => $this->present($unit)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateUnit($request);
        $code = Str::lower($data['code']);

        abort_if(Unit::where('code', $code)->exists(), 422, __('هذا الرمز مستخدم لوحدة أخرى.'));

        $unit = Unit::create([
            'code' => $code,
            'name' => [
                'ar' => $data['name']['ar'],
                'en' => ($data['name']['en'] ?? null) ?: $data['name']['ar'],
            ],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->audit->record('unit.created', $unit, [], ['code' => $unit->code, 'name' => $unit->t('name')]);

        return response()->json(['message' => __('تمت إضافة الوحدة.'), 'id' => $unit->id], 201);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $data = $this->validateUnit($request, partial: true);
        $previous = ['name' => $unit->t('name'), 'is_active' => $unit->is_active];

        if (isset($data['name'])) {
            $unit->name = [
                'ar' => $data['name']['ar'],
                'en' => ($data['name']['en'] ?? null) ?: $data['name']['ar'],
            ];
        }

        if (array_key_exists('sort_order', $data)) {
            $unit->sort_order = $data['sort_order'] ?? $unit->sort_order;
        }

        if (array_key_exists('is_active', $data)) {
            $unit->is_active = $data['is_active'];
        }

        $unit->save();

        $this->audit->record('unit.updated', $unit, $previous, ['name' => $unit->t('name'), 'is_active' => $unit->is_active]);

        return response()->json(['message' => __('تم حفظ الوحدة.')]);
    }

    public function destroy(Unit $unit): JsonResponse
    {
        abort_if($this->used($unit->code), 422, __('لا يمكن حذف وحدة مستخدمة في منتج أو طلب.'));

        $name = $unit->t('name');
        $unit->delete();

        $this->audit->record('unit.deleted', $unit, ['code' => $unit->code, 'name' => $name], []);

        return response()->json(['message' => __('تم حذف الوحدة.')]);
    }

    private function present(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'code' => $unit->code,
            'name' => $unit->t('name'),
            'name_translations' => $unit->getAttribute('name') ?? new \stdClass,
            'sort_order' => (int) $unit->sort_order,
            'is_active' => (bool) $unit->is_active,
        ];
    }

    private function validateUnit(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $rules = [
            'name' => [$required, 'array'],
            'name.ar' => [$required, 'string', 'max:80'],
            'name.en' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ];

        // الرمز يُثبَّت عند الإنشاء: المنتجات تحفظه، وتغييره يفصلها عن ترجمتها
        if (! $partial) {
            $rules['code'] = ['required', 'string', 'max:20', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/'];
        }

        return $request->validate($rules);
    }

    /** الرمز مربوط بسجلات قديمة، فحذف وحدة مستخدمة يتركها بلا اسم */
    private function used(string $code): bool
    {
        $columns = [
            'products' => 'unit_of_measure',
            'rfq_items' => 'unit',
            'quotation_items' => 'unit',
            'order_items' => 'unit',
            'purchase_order_items' => 'unit',
        ];

        foreach ($columns as $table => $column) {
            if (Schema::hasTable($table) && DB::table($table)->where($column, $code)->exists()) {
                return true;
            }
        }

        return false;
    }
}
