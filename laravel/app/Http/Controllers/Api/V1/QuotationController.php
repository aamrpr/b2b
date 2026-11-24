<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\QuotationResource;
use App\Models\Rfq\Quotation;
use App\Models\Rfq\Rfq;
use App\Services\Accounting\DocumentSequencer;
use App\Services\Rfq\QuotationService;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use App\Support\Media\SignatureImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $service,
        private readonly DocumentSequencer $sequencer,
    ) {}

    /** المستورد يرسل عرض سعر رداً على طلب. */
    public function store(Request $request, Rfq $rfq): JsonResponse
    {
        abort_unless($rfq->isOpen(), 422, __('هذا الطلب مغلق أو منتهي الصلاحية.'));

        $data = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'shipping_amount' => ['nullable', 'integer', 'min:0'],
            'discount_amount' => ['nullable', 'integer', 'min:0'],
            'incoterm' => ['nullable', 'string', 'max:10'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'validity_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.rfq_item_id' => ['nullable', 'integer', 'exists:rfq_items,id'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        $currency = $data['currency'] ?? $user->tenant->base_currency;

        $quotation = DB::transaction(function () use ($data, $rfq, $user, $currency) {
            $subtotal = 0;
            $tax = 0;

            foreach ($data['items'] as $item) {
                $subtotal += $item['unit_price'] * $item['quantity'];
                $tax += $item['tax_amount'] ?? 0;
            }

            $discount = $data['discount_amount'] ?? 0;
            $shipping = $data['shipping_amount'] ?? 0;

            $quotation = Quotation::create([
                'rfq_id' => $rfq->id,
                'supplier_tenant_id' => $user->tenant_id,
                'buyer_tenant_id' => $rfq->buyer_tenant_id,
                'created_by' => $user->id,
                'quotation_no' => $this->sequencer->next($user->tenant_id, 'quotation', 'QT'),
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'shipping_amount' => $shipping,
                'total' => $subtotal - $discount + $tax + $shipping,
                'currency' => $currency,
                'incoterm' => $data['incoterm'] ?? null,
                'lead_time_days' => $data['lead_time_days'] ?? 0,
                'payment_terms_days' => $data['payment_terms_days'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'valid_until' => now()->addHours($data['validity_hours'] ?? 72),
                'status' => 'draft',
            ]);

            foreach ($data['items'] as $item) {
                $quotation->items()->create([
                    'rfq_item_id' => $item['rfq_item_id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? 'piece',
                    'unit_price' => $item['unit_price'],
                    'tax_amount' => $item['tax_amount'] ?? 0,
                    'line_total' => $item['unit_price'] * $item['quantity'] + ($item['tax_amount'] ?? 0),
                ]);
            }

            return $this->service->send($quotation);
        });

        return response()->json([
            'message' => __('تم إرسال عرض السعر.'),
            'quotation' => new QuotationResource($quotation->load(['items', 'supplier.supplierProfile'])),
        ], 201);
    }

    /** العروض الواردة على طلب — جدول المقارنة لتاجر الجملة. */
    public function forRfq(Request $request, Rfq $rfq): AnonymousResourceCollection
    {
        abort_unless($rfq->buyer_tenant_id === $request->user()->tenant_id, 403);

        $quotations = $rfq->quotations()
            ->whereIn('status', ['sent', 'countered', 'accepted'])
            ->with(['items', 'supplier.supplierProfile'])
            ->orderBy('total')
            ->get();

        return QuotationResource::collection($quotations);
    }

    /** عروضي المرسلة — لوحة المستورد. */
    public function mine(Request $request): JsonResponse
    {
        $query = Quotation::query()
            ->where('supplier_tenant_id', $request->user()->tenant_id)
            ->with(['items', 'rfq.category']);

        return response()->json(
            DataTable::make($request, $query)
                ->searchable(['quotation_no'])
                ->sortable([
                    'id' => 'id',
                    'quotation_no' => 'quotation_no',
                    'status' => 'status',
                    'total' => 'total',
                    'lead_time_days' => 'lead_time_days',
                    'valid_until' => 'valid_until',
                    'created_at' => 'created_at',
                ])
                ->filter('status', fn ($q, $value) => $q->where('status', $value))
                ->filter('total', fn ($q, $value) => ColumnMatch::money($q, 'total', $value))
                ->filter('lead_time_days', fn ($q, $value) => ColumnMatch::number($q, 'lead_time_days', $value))
                ->filter('valid_until', fn ($q, $value) => ColumnMatch::date($q, 'valid_until', $value))
                ->defaultOrder('id')
                ->transform(fn (Quotation $quotation) => (new QuotationResource($quotation))->resolve($request))
                ->toArray()
        );
    }

    /** عرضٌ واحد بتفصيله — لطرفيه وحدهما، فهو وثيقة بينهما لا إعلان. */
    public function show(Request $request, Quotation $quotation): QuotationResource
    {
        $this->authorizeParty($request, $quotation);

        return new QuotationResource(
            $quotation->load(['items', 'supplier.supplierProfile', 'buyer.supplierProfile', 'rfq'])
        );
    }

    /** المستورد يوقّع عرضه قبل أن يقرأه المشتري. */
    public function sign(Request $request, Quotation $quotation): JsonResponse
    {
        abort_unless($quotation->supplier_tenant_id === $request->user()->tenant_id, 403);
        abort_if($quotation->supplier_signed_at !== null, 422, __('العرض موقّع أصلاً.'));

        $data = $request->validate([
            'signature' => ['required', 'string'],
            'signed_by' => ['required', 'string', 'max:191'],
        ]);

        $quotation->forceFill([
            'supplier_signature_path' => SignatureImage::store(
                $data['signature'],
                "signatures/quotation-{$quotation->id}-supplier.png",
            ),
            'supplier_signed_by' => $data['signed_by'],
            'supplier_signed_at' => now(),
        ])->save();

        return response()->json([
            'message' => __('تم توقيع العرض.'),
            'quotation' => new QuotationResource($quotation->load(['items', 'supplier.supplierProfile'])),
        ]);
    }

    /** قبول العرض ⟵ يتحوّل آلياً إلى أمر شراء. */
    public function accept(Request $request, Quotation $quotation): JsonResponse
    {
        abort_unless($quotation->buyer_tenant_id === $request->user()->tenant_id, 403);

        $data = $request->validate([
            'signature' => ['nullable', 'string'],
            'signed_by' => ['nullable', 'string', 'max:191'],
        ]);

        /*
         * التوقيع اختياري في الواجهة البرمجية ومطلوب في صفحة العرض: جدول
         * المقارنة يقبل بنقرة، ومن فتح الوثيقة ليقرأها يوقّعها.
         */
        if (filled($data['signature'] ?? null)) {
            $quotation->forceFill([
                'buyer_signature_path' => SignatureImage::store(
                    $data['signature'],
                    "signatures/quotation-{$quotation->id}-buyer.png",
                ),
                'buyer_signed_by' => $data['signed_by'] ?? $request->user()->name,
                'buyer_signed_at' => now(),
            ])->save();
        }

        $order = $this->service->accept($quotation);

        return response()->json([
            'message' => __('تم قبول العرض وإنشاء أمر الشراء.'),
            'order' => new OrderResource($order->load(['items', 'supplier.supplierProfile', 'buyer'])),
        ]);
    }

    private function authorizeParty(Request $request, Quotation $quotation): void
    {
        // المنصة طرفٌ ثالث في كل صفقة: تحكّم بينهما عند الخلاف فتقرأ وثيقتهما
        if ($request->user()->tenant?->isPlatform()) {
            return;
        }

        abort_unless(
            in_array(
                $request->user()->tenant_id,
                [$quotation->supplier_tenant_id, $quotation->buyer_tenant_id],
                true,
            ),
            403,
        );
    }
}
