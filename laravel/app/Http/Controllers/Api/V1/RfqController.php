<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RfqResource;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Rfq\Rfq;
use App\Models\Rfq\RfqInvitation;
use App\Services\Accounting\DocumentSequencer;
use App\Services\Mail\TransactionalMail;
use App\Services\Rfq\RfqBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RfqController extends Controller
{
    public function __construct(
        private readonly RfqBroadcaster $broadcaster,
        private readonly DocumentSequencer $sequencer,
        private readonly TransactionalMail $mail,
    ) {}

    /**
     * نشر طلب عرض سعر.
     * ينشئ الطلب ثم يبثّه فوراً لكل المستوردين المسجّلين في الفئة.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'target_price' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'delivery_city' => ['nullable', 'string', 'max:100'],
            'delivery_deadline' => ['nullable', 'date', 'after:today'],
            'validity_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'visibility' => ['nullable', 'in:public,targeted'],
            'hide_buyer_identity' => ['nullable', 'boolean'],
            'supplier_ids' => ['nullable', 'array'],
            'supplier_ids.*' => ['integer', 'exists:tenants,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['nullable', 'string', 'max:20', 'exists:units,code'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.specifications' => ['nullable', 'array'],
        ]);

        $allowed = Category::findOrFail($data['category_id'])->descendantIds();
        $productIds = array_column($data['items'], 'product_id');

        abort_if(
            count($productIds) !== count(array_unique($productIds)),
            422,
            __('لا يمكن تكرار المنتج في الطلب نفسه.'),
        );

        foreach ($data['items'] as $item) {
            $product = Product::active()->find($item['product_id']);

            // البند منتجٌ من صنف الطلب، لا اسماً يُكتَب. والمنتجات تحته تُقبَل معه.
            abort_unless(
                $product && in_array($product->category_id, $allowed, true),
                422,
                __('المنتج لا ينتمي إلى صنف الطلب.'),
            );
        }

        $user = $request->user();

        $rfq = DB::transaction(function () use ($data, $user) {
            $rfq = Rfq::create([
                'buyer_tenant_id' => $user->tenant_id,
                'created_by' => $user->id,
                'reference_no' => $this->sequencer->next(null, 'rfq', 'RFQ'),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'],
                'target_price' => $data['target_price'] ?? null,
                'currency' => $data['currency'] ?? $user->tenant->base_currency,
                'delivery_city' => $data['delivery_city'] ?? $user->tenant->city,
                'delivery_country' => $user->tenant->country_code,
                'delivery_deadline' => $data['delivery_deadline'] ?? null,
                'expires_at' => now()->addHours($data['validity_hours'] ?? config('b2b.rfq.default_validity_hours')),
                'visibility' => $data['visibility'] ?? 'public',
                'hide_buyer_identity' => $data['hide_buyer_identity'] ?? false,
                'status' => 'draft',
            ]);

            foreach ($data['items'] as $item) {
                $rfq->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? 'piece',
                    'specifications' => $item['specifications'] ?? null,
                ]);
            }

            return $rfq;
        });

        $reached = $this->broadcaster->dispatch($rfq, $data['supplier_ids'] ?? []);

        $this->mail->send($user, 'rfq', [
            'title' => $rfq->title,
            'reference' => $rfq->reference_no,
            'category' => $rfq->category?->t('name', $user->locale === 'en' ? 'en' : 'ar') ?? '',
        ], '/my/rfqs');

        return response()->json([
            'message' => trans_choice(
                '{0}لم يُعثر على مستوردين في هذا الصنف|{1}وصل طلبك إلى مستورد واحد|[2,*]وصل طلبك إلى :count مستورداً',
                $reached,
                ['count' => $reached]
            ),
            'reached_suppliers' => $reached,
            'rfq' => new RfqResource($rfq->fresh()->load(['items.product', 'category', 'buyer'])),
        ], 201);
    }

    /** طلباتي — لوحة تاجر الجملة. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $rfqs = Rfq::query()
            ->where('buyer_tenant_id', $request->user()->tenant_id)
            ->with(['category', 'items.product'])
            ->withCount('quotations')
            ->orderByDesc('id')
            ->paginate(20);

        return RfqResource::collection($rfqs);
    }

    /**
     * الطلبات الواردة — لوحة المستورد.
     * هذه هي الشاشة التي يرى فيها المستورد الطلبات التي وصلته في أصنافه.
     */
    public function inbox(Request $request): AnonymousResourceCollection
    {
        $tenantId = $request->user()->tenant_id;

        $rfqs = Rfq::query()
            ->open()
            ->whereHas('invitations', fn ($q) => $q->where('supplier_tenant_id', $tenantId))
            ->with(['category', 'items.product', 'buyer'])
            ->withExists(['quotations as has_quoted' => fn ($q) => $q->where('supplier_tenant_id', $tenantId)])
            ->orderByDesc('created_at')
            ->paginate(20);

        return RfqResource::collection($rfqs);
    }

    public function show(Request $request, Rfq $rfq): RfqResource
    {
        $tenantId = $request->user()?->tenant_id;

        // تسجيل المشاهدة للمستورد المدعو
        if ($tenantId) {
            $invitation = RfqInvitation::where('rfq_id', $rfq->id)
                ->where('supplier_tenant_id', $tenantId)
                ->whereNull('viewed_at')
                ->first();

            if ($invitation) {
                $invitation->update(['viewed_at' => now()]);
                $rfq->increment('viewed_count');
            }
        }

        return new RfqResource($rfq->load(['category', 'items.product', 'buyer']));
    }

    /** العدّاد الحيّ: وصل إلى N · فتحه M · قدّم عرضاً K */
    public function stats(Request $request, Rfq $rfq): JsonResponse
    {
        abort_unless($rfq->buyer_tenant_id === $request->user()->tenant_id, 403);

        return response()->json($this->broadcaster->stats($rfq));
    }
}
