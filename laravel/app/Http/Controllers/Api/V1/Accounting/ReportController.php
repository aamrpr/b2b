<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\FinancialReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(private readonly FinancialReportService $reports) {}

    public function trialBalance(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($this->reports->trialBalance($request->user()->tenant, $from, $to));
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($this->reports->incomeStatement($request->user()->tenant, $from, $to));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $asOf = $request->query('as_of') ? Carbon::parse($request->query('as_of')) : Carbon::today();

        return response()->json($this->reports->balanceSheet($request->user()->tenant, $asOf));
    }

    public function receivablesAging(Request $request): JsonResponse
    {
        return response()->json($this->reports->receivablesAging($request->user()->tenant));
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
