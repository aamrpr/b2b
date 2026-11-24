<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\JournalEntry;
use App\Services\Accounting\JournalDraft;
use App\Services\Accounting\JournalPoster;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function __construct(private readonly JournalPoster $poster) {}

    public function index(Request $request): JsonResponse
    {
        $entries = JournalEntry::query()
            ->with('lines.account')
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('entry_date', '<=', $d))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $entries->getCollection()->map(fn (JournalEntry $e) => $this->present($e)),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        return response()->json($this->present($journalEntry->load('lines.account')));
    }

    /** قيد يدوي — للتسويات التي لا يولّدها النظام. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'currency' => ['nullable', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account' => ['required', 'string'],
            'lines.*.debit' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit' => ['nullable', 'integer', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = $request->user()->tenant;
        $currency = $data['currency'] ?? $tenant->base_currency;

        $draft = JournalDraft::for($tenant, $currency)
            ->on($data['entry_date'])
            ->ofType('manual')
            ->describe($data['description']);

        foreach ($data['lines'] as $line) {
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);

            abort_if($debit > 0 && $credit > 0, 422, __('السطر لا يحمل مديناً ودائناً معاً.'));

            if ($debit > 0) {
                $draft->debit($line['account'], Money::of($debit, $currency), $line['description'] ?? null);
            } elseif ($credit > 0) {
                $draft->credit($line['account'], Money::of($credit, $currency), $line['description'] ?? null);
            }
        }

        abort_unless($draft->isBalanced(), 422, __('القيد غير متوازن: مجموع المدين لا يساوي مجموع الدائن.'));

        $entry = $this->poster->post($draft);

        return response()->json(['entry' => $this->present($entry->load('lines.account'))], 201);
    }

    /** عكس قيد — التصحيح الوحيد المسموح به. */
    public function reverse(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $reversal = $this->poster->reverse($journalEntry, $data['reason'] ?? null);

        return response()->json([
            'message' => __('تم إنشاء القيد العكسي.'),
            'entry' => $this->present($reversal->load('lines.account')),
        ]);
    }

    private function present(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'entry_no' => $entry->entry_no,
            'entry_date' => $entry->entry_date->toDateString(),
            'type' => $entry->type,
            'description' => $entry->description,
            'currency' => $entry->currency,
            'exchange_rate' => $entry->exchange_rate,
            'total_debit' => $entry->total_debit,
            'total_credit' => $entry->total_credit,
            'is_balanced' => $entry->isBalanced(),
            'status' => $entry->status,
            'source' => $entry->source_type ? class_basename($entry->source_type).'#'.$entry->source_id : null,
            'lines' => $entry->relationLoaded('lines') ? $entry->lines->map(fn ($l) => [
                'account_code' => $l->account?->code,
                'account_name' => $l->account?->t('name'),
                'debit' => $l->debit,
                'credit' => $l->credit,
                'base_debit' => $l->base_debit,
                'base_credit' => $l->base_credit,
                'description' => $l->description,
            ]) : [],
        ];
    }
}
