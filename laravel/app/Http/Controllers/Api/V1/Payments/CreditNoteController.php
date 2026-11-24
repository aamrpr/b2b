<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Models\Accounting\CreditNote;
use App\Models\Accounting\Invoice;
use App\Services\Payments\CreditNoteService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditNoteController extends Controller
{
    public function __construct(private readonly CreditNoteService $service) {}

    public function index(Request $request): JsonResponse
    {
        // «المفتوحة وحدها» مقارنةٌ بين عمودين لا قيمةٌ تُصفّى، فتُقرأ من الطلب
        $query = CreditNote::query()
            ->with(['invoice', 'returnRequest'])
            ->when($request->boolean('open_only'), fn ($q) => $q->whereColumn('applied_amount', '<', 'total'));

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['note_no', 'reason'])
                ->sortable([
                    'id' => 'id',
                    'note_no' => 'note_no',
                    'issue_date' => 'issue_date',
                    'total' => 'total',
                    'applied_amount' => 'applied_amount',
                    'status' => 'status',
                ])
                ->filter('status', fn ($q, $value) => $q->where('status', $value))
                ->filter('invoice_no', fn ($q, $value) => $q->whereHas('invoice', fn ($i) => $i->where('invoice_no', 'like', "%{$value}%")))
                ->filter('total', fn ($q, $value) => ColumnMatch::money($q, 'total', $value))
                ->filter('applied_amount', fn ($q, $value) => ColumnMatch::money($q, 'applied_amount', $value))
                ->filter('unapplied_amount', fn ($q, $value) => ColumnMatch::money(
                    $q,
                    '(credit_notes.total - credit_notes.applied_amount)',
                    $value,
                ))
                ->defaultOrder('id')
                ->with(['currency' => $request->user()->tenant->base_currency])
                ->transform(fn (CreditNote $note) => [
                    'id' => $note->id,
                    'note_no' => $note->note_no,
                    'issue_date' => $note->issue_date->toDateString(),
                    'invoice_no' => $note->invoice?->invoice_no,
                    'return_reference' => $note->returnRequest?->reference_no,
                    'subtotal' => $note->subtotal,
                    'tax_amount' => $note->tax_amount,
                    'total' => $note->total,
                    'applied_amount' => $note->applied_amount,
                    'unapplied_amount' => $note->unappliedAmount(),
                    'currency' => $note->currency,
                    'reason' => $note->reason,
                    'status' => $note->status,
                ])
                ->toArray()
        );
    }

    /** تطبيق رصيد إشعار مفتوح على فاتورة لاحقة. */
    public function apply(Request $request, CreditNote $creditNote): JsonResponse
    {
        abort_unless($creditNote->tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        $invoice = Invoice::findOrFail($data['invoice_id']);

        $applied = $this->service->applyToInvoice($creditNote, $invoice, $data['amount'] ?? null);

        return response()->json([
            'message' => __('طُبِّق :amount من الإشعار.', ['amount' => $applied]),
            'applied' => $applied,
            'unapplied' => $creditNote->fresh()->unappliedAmount(),
            'invoice_balance' => $invoice->fresh()->balance_due,
        ]);
    }
}
