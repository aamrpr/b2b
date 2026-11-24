<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Unit;
use Illuminate\Http\JsonResponse;

/** وحدات القياس الفعّالة، باسمها في لغة الطلب. */
class UnitController extends Controller
{
    public function index(): JsonResponse
    {
        $units = Unit::query()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'data' => $units->map(fn (Unit $unit) => [
                'code' => $unit->code,
                'name' => $unit->t('name'),
                'is_active' => (bool) $unit->is_active,
            ]),
        ]);
    }
}
