<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\Payment;
use App\Models\Tenancy\Tenant;
use App\Services\Payments\CreditNoteService;
use App\Services\Payments\PaymentService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly CreditNoteService $credits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->with('allocations.invoice');

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['payment_no', 'gateway_reference', 'notes'])
                ->sortable([
                    'id' => 'id',
                    'payment_date' => 'payment_date',
                    'amount' => 'amount',
                    'method' => 'method',
                    'direction' => 'direction',
                ])
                ->filter('direction', fn ($q, $value) => $q->where('direction', $value))
                ->filter('method', fn ($q, $value) => $q->where('method', $value))
                ->filter('payment_date', fn ($q, $value) => ColumnMatch::date($q, 'payment_date', $value))
                ->filter('amount', fn ($q, $value) => ColumnMatch::money($q, 'amount', $value))
                ->filter('allocated_amount', fn ($q, $value) => ColumnMatch::money(
                    $q,
                    '(SELECT COALESCE(SUM(payment_allocations.amount), 0) FROM payment_allocations WHERE payment_allocations.payment_id = payments.id)',
                    $value,
                ))
                ->defaultOrder('payment_date')
                ->with(['currency' => $request->user()->tenant->base_currency])
                ->transform(fn (Payment $payment) => $this->present($payment))
                ->toArray()
        );
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizePayment($request, $payment);

        return response()->json(['payment' => $this->present($payment->load('allocations.invoice'))]);
    }

    /** الفواتير المستحقة على عميل — تملأ شاشة التحصيل. */
    public function outstanding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'type' => ['nullable', 'in:sales,purchase'],
        ]);

        $invoices = Invoice::query()
            ->where('type', $data['type'] ?? 'sales')
            ->where('balance_due', '>', 0)
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->when($data['contact_id'] ?? null, fn ($q, $id) => $q->where('contact_id', $id))
            ->orderBy('due_date')
            ->get();

        // أسماء الأطراف تُجلب دفعة واحدة: الشاشة تجمّع الفواتير بالعميل
        $contacts = Tenant::whereIn('id', $invoices->pluck('contact_id')->filter()->unique())
            ->pluck('name', 'id');

        $credit = isset($data['contact_id'])
            ? $this->credits->availableCreditFor(
                $request->user()->tenant_id,
                Tenant::class,
                (int) $data['contact_id'],
            )
            : 0;

        return response()->json([
            'currency' => $request->user()->tenant->base_currency,
            'available_credit' => $credit,
            'customers' => $invoices
                ->groupBy('contact_id')
                ->map(fn ($group, $contactId) => [
                    'id' => (int) $contactId,
                    'name' => $contacts[$contactId] ?? null,
                    'invoices_count' => $group->count(),
                    'outstanding' => (int) $group->sum('balance_due'),
                    'overdue' => (int) $group->filter(fn (Invoice $i) => $i->isOverdue())->sum('balance_due'),
                ])
                ->values(),
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'contact_id' => $invoice->contact_id,
                'contact_name' => $contacts[$invoice->contact_id] ?? null,
                'invoice_no' => $invoice->invoice_no,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'total' => $invoice->total,
                'paid_amount' => $invoice->paid_amount,
                'balance_due' => $invoice->balance_due,
                'status' => $invoice->status,
                'age_days' => $invoice->ageInDays(),
                'bucket' => $invoice->agingBucket(),
            ]),
        ]);
    }

    public function receive(Request $request): JsonResponse
    {
        $data = $this->validatePayment($request);

        $payment = $this->payments->receive(
            $request->user()->tenant,
            $request->user(),
            [...$data, 'contact' => isset($data['contact_id']) ? Tenant::find($data['contact_id']) : null],
            $data['allocations'] ?? [],
        );

        return response()->json([
            'message' => __('تم تسجيل التحصيل.'),
            'payment' => $this->present($payment->load('allocations.invoice')),
        ], 201);
    }

    public function pay(Request $request): JsonResponse
    {
        $data = $this->validatePayment($request);

        $payment = $this->payments->pay(
            $request->user()->tenant,
            $request->user(),
            [...$data, 'contact' => isset($data['contact_id']) ? Tenant::find($data['contact_id']) : null],
            $data['allocations'] ?? [],
        );

        return response()->json([
            'message' => __('تم تسجيل الصرف.'),
            'payment' => $this->present($payment->load('allocations.invoice')),
        ], 201);
    }

    public function allocate(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizePayment($request, $payment);

        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'allocations.*.amount' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $this->payments->allocate($payment, $data['allocations']);

        return response()->json([
            'message' => __('تم توزيع الدفعة.'),
            'payment' => $this->present($updated->load('allocations.invoice')),
        ]);
    }

    public function statement(Request $request, Tenant $tenant): JsonResponse
    {
        return response()->json(
            $this->payments->statement($request->user()->tenant, $tenant)
        );
    }

    private function validatePayment(Request $request): array
    {
        return $request->validate([
            'contact_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'method' => ['required', 'in:cash,bank,gateway,wallet,hawala,cheque'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'fee_amount' => ['nullable', 'integer', 'min:0'],
            'payment_date' => ['nullable', 'date'],
            'gateway_reference' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:500'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoice_id' => ['required_with:allocations', 'integer', 'exists:invoices,id'],
            'allocations.*.amount' => ['required_with:allocations', 'integer', 'min:1'],
        ]);
    }

    private function authorizePayment(Request $request, Payment $payment): void
    {
        abort_unless($payment->tenant_id === $request->user()->tenant_id, 403);
    }

    private function present(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_no' => $payment->payment_no,
            'direction' => $payment->direction,
            'method' => $payment->method,
            'amount' => $payment->amount,
            'fee_amount' => $payment->fee_amount,
            'currency' => $payment->currency,
            'payment_date' => $payment->payment_date->toDateString(),
            'status' => $payment->status,
            'gateway_reference' => $payment->gateway_reference,
            'notes' => $payment->notes,
            'allocated_amount' => $payment->amount - $payment->unallocatedAmount(),
            'unallocated_amount' => $payment->unallocatedAmount(),
            'allocations' => $payment->relationLoaded('allocations')
                ? $payment->allocations->map(fn ($allocation) => [
                    'invoice_id' => $allocation->invoice_id,
                    'invoice_no' => $allocation->invoice?->invoice_no,
                    'amount' => $allocation->amount,
                ])
                : [],
        ];
    }
}
