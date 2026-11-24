<?php

namespace App\Http\Controllers\Api\V1\Returns;

use App\Http\Controllers\Controller;
use App\Models\Orders\Order;
use App\Models\Returns\Dispute;
use App\Models\Returns\DisputeEvent;
use App\Services\Returns\DisputeService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isPlatform = $user->isPlatformUser();
        $tenantId = $user->tenant_id;

        $query = Dispute::query()
            // المنصة ترى كل النزاعات لأنها الوسيط؛ الطرفان يريان نزاعاتهما فقط
            ->when(! $isPlatform, fn ($q) => $q->where(fn ($w) => $w
                ->where('buyer_tenant_id', $tenantId)
                ->orWhere('supplier_tenant_id', $tenantId)))
            // «المتأخّر» مقارنةٌ بالوقت الحاضر لا قيمةٌ في عمود، فيُقرأ شرطاً على الطلب
            ->when($request->boolean('overdue'), fn ($q) => $q->whereNotNull('due_at')->where('due_at', '<', now()))
            ->with(['order', 'buyer', 'supplier', 'mediator']);

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['reference_no', 'description'])
                ->sortable([
                    'id' => 'id',
                    'reference_no' => 'reference_no',
                    'status' => 'status',
                    'reason' => 'reason',
                    'claimed_amount' => 'claimed_amount',
                    'due_at' => 'due_at',
                    'created_at' => 'created_at',
                ])
                ->filter('status', fn ($q, $value) => $q->where('status', $value))
                ->filter('reason', fn ($q, $value) => $q->where('reason', $value))
                ->filter('order', fn ($q, $value) => $q->whereHas('order', fn ($o) => $o->where('order_no', 'like', "%{$value}%")))
                ->filter('counterpart', function ($q, $value) {
                    $q->where(function ($q) use ($value) {
                        $q->whereHas('buyer', fn ($b) => $b->where('name', 'like', "%{$value}%"))
                            ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$value}%"));
                    });
                })
                ->filter('claimed_amount', fn ($q, $value) => ColumnMatch::money($q, 'claimed_amount', $value))
                ->filter('mediator', fn ($q, $value) => $q->whereHas('mediator', fn ($u) => $u->where('name', 'like', "%{$value}%")))
                ->defaultOrder('id')
                ->with(['is_mediator' => $isPlatform])
                ->transform(fn (Dispute $row) => $this->summary($row, $tenantId))
                ->toArray()
        );
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'in:'.implode(',', Dispute::REASONS)],
            'description' => ['required', 'string', 'max:2000'],
            'claimed_amount' => ['nullable', 'integer', 'min:0'],
            'return_request_id' => ['nullable', 'integer', 'exists:return_requests,id'],
        ]);

        $dispute = $this->service->open($order, $request->user(), $data);

        return response()->json([
            'message' => __('فُتح النزاع وأُبلغ الطرف الآخر.'),
            'dispute' => $this->detail($dispute->load('order', 'buyer', 'supplier', 'events.user'), $request->user()),
        ], 201);
    }

    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $this->authorizeView($request, $dispute);

        return response()->json([
            'dispute' => $this->detail(
                $dispute->load('order', 'buyer', 'supplier', 'mediator', 'events.user', 'events.tenant', 'returnRequest'),
                $request->user()
            ),
        ]);
    }

    public function addMessage(Request $request, Dispute $dispute): JsonResponse
    {
        $this->authorizeView($request, $dispute);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,xlsx,docx'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        // الملاحظة الداخلية حكر على وسيط المنصة
        $internal = ($data['is_internal'] ?? false) && $request->user()->isPlatformUser();

        $path = $request->hasFile('file')
            ? $request->file('file')->store('disputes', 'public')
            : null;

        $this->service->addMessage($dispute, $request->user(), $data['body'], $path, $internal);

        return response()->json([
            'dispute' => $this->detail(
                $dispute->fresh()->load('order', 'buyer', 'supplier', 'events.user', 'events.tenant'),
                $request->user()
            ),
        ], 201);
    }

    public function assign(Request $request, Dispute $dispute): JsonResponse
    {
        $dispute = $this->service->assign($dispute, $request->user());

        return response()->json([
            'message' => __('بدأت مراجعة النزاع.'),
            'dispute' => $this->detail($dispute->load('order', 'buyer', 'supplier', 'mediator', 'events.user'), $request->user()),
        ]);
    }

    public function requestResponse(Request $request, Dispute $dispute): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:1000']]);

        $dispute = $this->service->requestResponse($dispute, $request->user(), $data['message']);

        return response()->json([
            'message' => __('طُلب الإيضاح وضُبطت المهلة.'),
            'dispute' => $this->detail($dispute->load('order', 'buyer', 'supplier', 'events.user'), $request->user()),
        ]);
    }

    public function resolve(Request $request, Dispute $dispute): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:buyer_favored,supplier_favored,partial,withdrawn'],
            'resolution_amount' => ['nullable', 'integer', 'min:0'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $dispute = $this->service->resolve($dispute, $request->user(), $data);

        return response()->json([
            'message' => __('صدر القرار وتُرجم إلى قيوده.'),
            'dispute' => $this->detail($dispute->load('order', 'buyer', 'supplier', 'events.user'), $request->user()),
        ]);
    }

    public function close(Request $request, Dispute $dispute): JsonResponse
    {
        $this->authorizeView($request, $dispute);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $dispute = $this->service->close($dispute, $request->user(), $data['note'] ?? null);

        return response()->json(['message' => __('أُقفل النزاع.'), 'status' => $dispute->status]);
    }

    private function authorizeView(Request $request, Dispute $dispute): void
    {
        $user = $request->user();

        abort_unless($user->isPlatformUser() || $dispute->involves($user->tenant_id), 403);
    }

    private function summary(Dispute $row, ?int $viewerTenantId): array
    {
        return [
            'id' => $row->id,
            'reference_no' => $row->reference_no,
            'order' => ['id' => $row->order_id, 'order_no' => $row->order?->order_no],
            'buyer' => $row->buyer?->name,
            'supplier' => $row->supplier?->name,
            'opened_by_me' => $row->opened_by_tenant_id === $viewerTenantId,
            'reason' => $row->reason,
            'status' => $row->status,
            'outcome' => $row->outcome,
            'claimed_amount' => $row->claimed_amount,
            'resolution_amount' => $row->resolution_amount,
            'currency' => $row->currency,
            'mediator' => $row->mediator?->name,
            'due_at' => $row->due_at?->toIso8601String(),
            'is_overdue' => $row->isOverdue(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function detail(Dispute $row, $viewer): array
    {
        $isPlatform = $viewer->isPlatformUser();

        return [
            ...$this->summary($row, $viewer->tenant_id),
            'description' => $row->description,
            'resolution_note' => $row->resolution_note,
            'return_request' => $row->returnRequest
                ? ['id' => $row->returnRequest->id, 'reference_no' => $row->returnRequest->reference_no]
                : null,
            'allowed_transitions' => Dispute::FLOW[$row->status] ?? [],
            'resolved_at' => $row->resolved_at?->toIso8601String(),
            'events' => $row->relationLoaded('events')
                ? $row->events
                    // الملاحظات الداخلية لا تظهر للطرفين
                    ->filter(fn (DisputeEvent $event) => $isPlatform || ! $event->is_internal)
                    ->map(fn (DisputeEvent $event) => [
                        'id' => $event->id,
                        'type' => $event->type,
                        'body' => $event->body,
                        'file_url' => $event->url(),
                        'meta' => $event->meta,
                        'is_internal' => $event->is_internal,
                        'from_platform' => $event->isFromPlatform(),
                        'author' => $event->user?->name,
                        'company' => $event->tenant?->name,
                        'created_at' => $event->created_at?->toIso8601String(),
                    ])
                    ->values()
                : [],
        ];
    }
}
