<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Models\Shipping\Carrier;
use App\Models\Shipping\CarrierRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarrierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $carriers = Carrier::availableTo($request->user()->tenant_id)
            ->with('rates')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'carriers' => $carriers->map(fn (Carrier $carrier) => $this->present($carrier)),
        ]);
    }

    /** شركة شحن خاصة بالمستورد — السوق المحلي مليء بشركات صغيرة. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateCarrier($request);

        abort_if(
            Carrier::where('tenant_id', $request->user()->tenant_id)->where('code', $data['code'])->exists(),
            422,
            __('رمز شركة الشحن مستخدم مسبقاً.')
        );

        $carrier = Carrier::create([...$data, 'tenant_id' => $request->user()->tenant_id]);

        return response()->json(['carrier' => $this->present($carrier)], 201);
    }

    public function update(Request $request, Carrier $carrier): JsonResponse
    {
        $this->authorizeOwn($request, $carrier);

        $carrier->update($this->validateCarrier($request, partial: true));

        return response()->json(['carrier' => $this->present($carrier->fresh())]);
    }

    public function destroy(Request $request, Carrier $carrier): JsonResponse
    {
        $this->authorizeOwn($request, $carrier);

        abort_if(
            $carrier->shipments()->whereNotIn('status', ['delivered', 'cancelled', 'returned'])->exists(),
            422,
            __('لا يمكن تعطيل شركة لديها شحنات جارية.')
        );

        $carrier->update(['is_active' => false]);

        return response()->json(['message' => __('تم تعطيل شركة الشحن.')]);
    }

    public function storeRate(Request $request, Carrier $carrier): JsonResponse
    {
        $this->authorizeOwn($request, $carrier);

        $data = $request->validate([
            'from_city' => ['nullable', 'string', 'max:100'],
            'to_city' => ['nullable', 'string', 'max:100'],
            'min_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'max_weight_kg' => ['nullable', 'numeric', 'gt:min_weight_kg'],
            'base_price' => ['required', 'integer', 'min:0'],
            'price_per_kg' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'transit_days' => ['nullable', 'integer', 'min:0'],
        ]);

        $rate = $carrier->rates()->create($data);

        return response()->json(['rate' => $rate], 201);
    }

    /** تسعيرة مقترحة لمسار ووزن — تملأ حقل التكلفة في شاشة الشحنة. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_city' => ['nullable', 'string', 'max:100'],
            'to_city' => ['nullable', 'string', 'max:100'],
            'weight_kg' => ['required', 'numeric', 'min:0'],
        ]);

        $carriers = Carrier::availableTo($request->user()->tenant_id)->with('rates')->get();
        $quotes = [];

        foreach ($carriers as $carrier) {
            if (! $carrier->servesCity($data['to_city'] ?? null)) {
                continue;
            }

            $rate = $carrier->rates->first(
                fn (CarrierRate $r) => $r->appliesTo($data['from_city'] ?? null, $data['to_city'] ?? null, (float) $data['weight_kg'])
            );

            if (! $rate) {
                continue;
            }

            $price = $rate->quote((float) $data['weight_kg']);

            $quotes[] = [
                'carrier_id' => $carrier->id,
                'carrier' => $carrier->t('name'),
                'amount' => $price->amount,
                'currency' => $price->currency,
                'transit_days' => $rate->transit_days,
                'supports_cod' => $carrier->supports_cod,
            ];
        }

        usort($quotes, fn ($a, $b) => $a['amount'] <=> $b['amount']);

        return response()->json(['quotes' => $quotes]);
    }

    private function validateCarrier(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'array'],
            'name.ar' => [$required, 'string', 'max:191'],
            'name.en' => ['nullable', 'string', 'max:191'],
            'code' => [$required, 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:255'],
            'tracking_url_template' => ['nullable', 'string', 'max:255'],
            'coverage' => ['nullable', 'array'],
            'coverage.*' => ['string', 'max:100'],
            'supports_cod' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeOwn(Request $request, Carrier $carrier): void
    {
        abort_if(
            $carrier->isPlatformWide(),
            403,
            __('شركات الشحن العامة تديرها المنصة.')
        );

        abort_unless($carrier->tenant_id === $request->user()->tenant_id, 403);
    }

    private function present(Carrier $carrier): array
    {
        return [
            'id' => $carrier->id,
            'name' => $carrier->t('name'),
            'name_translations' => $carrier->name,
            'code' => $carrier->code,
            'phone' => $carrier->phone,
            'website' => $carrier->website,
            'coverage' => $carrier->coverage ?? [],
            'supports_cod' => $carrier->supports_cod,
            'is_platform_wide' => $carrier->isPlatformWide(),
            'is_active' => $carrier->is_active,
            'rates' => $carrier->relationLoaded('rates')
                ? $carrier->rates->map(fn (CarrierRate $rate) => [
                    'id' => $rate->id,
                    'from_city' => $rate->from_city,
                    'to_city' => $rate->to_city,
                    'min_weight_kg' => $rate->min_weight_kg,
                    'max_weight_kg' => $rate->max_weight_kg,
                    'base_price' => $rate->base_price,
                    'price_per_kg' => $rate->price_per_kg,
                    'currency' => $rate->currency,
                    'transit_days' => $rate->transit_days,
                ])
                : [],
        ];
    }
}
