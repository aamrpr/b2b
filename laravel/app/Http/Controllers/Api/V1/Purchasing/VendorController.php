<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Purchasing\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $vendors = Vendor::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->withCount('purchaseOrders')
            ->orderBy('name')
            ->get();

        return response()->json([
            'currency' => $request->user()->tenant->base_currency,
            'vendors' => $vendors->map(fn (Vendor $vendor) => $this->present($vendor)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateVendor($request);

        abort_if(
            Vendor::where('code', $data['code'])->exists(),
            422,
            __('رمز المورّد مستخدم مسبقاً.')
        );

        return response()->json(['vendor' => $this->present(Vendor::create($data))], 201);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);

        $vendor->update($this->validateVendor($request, partial: true));

        return response()->json(['vendor' => $this->present($vendor->fresh())]);
    }

    public function destroy(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorizeVendor($request, $vendor);

        abort_if(
            $vendor->outstanding() > 0,
            422,
            __('لا يُعطَّل مورّد عليه رصيد مستحق.')
        );

        $vendor->update(['is_active' => false]);

        return response()->json(['message' => __('تم تعطيل المورّد.')]);
    }

    private function validateVendor(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:191'],
            'code' => [$required, 'string', 'max:30'],
            'contact_person' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeVendor(Request $request, Vendor $vendor): void
    {
        abort_unless($vendor->tenant_id === $request->user()->tenant_id, 403);
    }

    private function present(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'code' => $vendor->code,
            'contact_person' => $vendor->contact_person,
            'phone' => $vendor->phone,
            'email' => $vendor->email,
            'country_code' => $vendor->country_code,
            'city' => $vendor->city,
            'default_currency' => $vendor->default_currency,
            'payment_terms_days' => $vendor->payment_terms_days,
            'is_active' => $vendor->is_active,
            'orders_count' => $vendor->purchase_orders_count ?? null,
            'outstanding' => $vendor->outstanding(),
        ];
    }
}
