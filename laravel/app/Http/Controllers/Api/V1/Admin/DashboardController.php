<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\PlatformMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __construct(private readonly PlatformMetricsService $metrics) {}

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($this->metrics->overview($from, $to));
    }

    /** ملخّص المنصة كلّها: ماذا فيها، بالعدد. */
    public function summary(): JsonResponse
    {
        return response()->json($this->metrics->summary());
    }

    public function series(Request $request): JsonResponse
    {
        $months = min(max((int) $request->query('months', 12), 3), 24);

        return response()->json($this->metrics->monthlySeries($months));
    }

    public function topSuppliers(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['suppliers' => $this->metrics->topSuppliers($from, $to)]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        return [
            $request->query('from') ? Carbon::parse($request->query('from')) : Carbon::today()->startOfYear(),
            $request->query('to') ? Carbon::parse($request->query('to')) : Carbon::today(),
        ];
    }
}
