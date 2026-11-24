<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commerce\Subscription;
use App\Models\Commerce\SubscriptionPlan;
use App\Services\Admin\AuditLogger;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->get();

        return response()->json([
            'plans' => $plans->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->t('name'),
                'name_translations' => $plan->name,
                'price_monthly' => $plan->price_monthly,
                'price_yearly' => $plan->price_yearly,
                'currency' => $plan->currency,
                'product_limit' => $plan->product_limit,
                'user_limit' => $plan->user_limit,
                'commission_discount' => $plan->commission_discount,
                'accounting_enabled' => $plan->accounting_enabled,
                'is_active' => $plan->is_active,
                'subscribers' => $plan->subscriptions()
                    ->withoutGlobalScope('tenant')
                    ->whereIn('status', ['trial', 'active'])
                    ->count(),
            ]),
        ]);
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $data = $request->validate([
            'name.ar' => ['sometimes', 'string', 'max:191'],
            'name.en' => ['sometimes', 'string', 'max:191'],
            'price_monthly' => ['sometimes', 'integer', 'min:0'],
            'price_yearly' => ['sometimes', 'integer', 'min:0'],
            'product_limit' => ['nullable', 'integer', 'min:0'],
            'user_limit' => ['sometimes', 'integer', 'min:1'],
            'commission_discount' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'accounting_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $old = $plan->only(array_keys($data));
        $plan->update($data);

        $this->audit->record('plan.updated', $plan, $old, $data);

        return response()->json(['message' => __('تم تحديث الخطة.')]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            DataTable::make($request, Subscription::withoutGlobalScope('tenant')->with(['plan', 'tenant']))
                ->sortable([
                    'id' => 'id',
                    'amount' => 'amount',
                    'status' => 'status',
                    'billing_cycle' => 'billing_cycle',
                    'starts_at' => 'starts_at',
                    'ends_at' => 'ends_at',
                ])
                ->filter('status', fn ($query, $value) => $query->where('status', $value))
                ->filter('tenant', fn ($query, $value) => $query->whereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$value}%")))
                ->filter('plan', fn ($query, $value) => $query->whereHas('plan', fn ($p) => $p->where('name', 'like', "%{$value}%")))
                ->filter('amount', fn ($query, $value) => ColumnMatch::money($query, 'amount', $value))
                ->filter('ends_at', fn ($query, $value) => ColumnMatch::date($query, 'ends_at', $value))
                ->defaultOrder('id')
                ->transform(fn (Subscription $subscription) => [
                    'id' => $subscription->id,
                    'tenant' => ['id' => $subscription->tenant_id, 'name' => $subscription->tenant?->name],
                    'plan' => $subscription->plan?->t('name'),
                    'billing_cycle' => $subscription->billing_cycle,
                    'amount' => $subscription->amount,
                    'currency' => $subscription->currency,
                    'status' => $subscription->status,
                    'starts_at' => $subscription->starts_at?->toDateString(),
                    'ends_at' => $subscription->ends_at?->toDateString(),
                    'is_active' => $subscription->isActive(),
                ])
                ->toArray()
        );
    }
}
