<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accounting\CommissionRule;
use App\Models\Catalog\Category;
use App\Models\Commerce\SubscriptionPlan;
use App\Models\Tenancy\Tenant;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * قواعد العمولة. الأخص يفوز: مورد ← خطة ← فئة ← عام.
 */
class CommissionRuleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $rules = CommissionRule::orderByRaw("FIELD(scope, 'supplier', 'plan', 'category', 'global')")
            ->orderByDesc('priority')
            ->get();

        return response()->json([
            'rules' => $rules->map(fn (CommissionRule $rule) => $this->present($rule)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRule($request);

        $rule = CommissionRule::create($data);

        $this->audit->record('commission_rule.created', $rule, [], $data);

        return response()->json(['rule' => $this->present($rule)], 201);
    }

    public function update(Request $request, CommissionRule $commissionRule): JsonResponse
    {
        $data = $this->validateRule($request, partial: true);
        $old = $commissionRule->only(array_keys($data));

        $commissionRule->update($data);

        $this->audit->record('commission_rule.updated', $commissionRule, $old, $data);

        return response()->json(['rule' => $this->present($commissionRule->fresh())]);
    }

    public function destroy(CommissionRule $commissionRule): JsonResponse
    {
        abort_if(
            $commissionRule->scope === 'global',
            422,
            __('لا يمكن حذف القاعدة العامة — عدّلها بدل حذفها.')
        );

        $this->audit->record('commission_rule.deleted', $commissionRule, $commissionRule->toArray());

        $commissionRule->delete();

        return response()->json(['message' => __('تم حذف القاعدة.')]);
    }

    private function validateRule(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'scope' => [$required, 'in:global,category,plan,supplier'],
            'scope_id' => ['nullable', 'integer'],
            'type' => [$required, 'in:percentage,fixed,tiered'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'tiers' => ['nullable', 'array'],
            'tiers.*.up_to' => ['nullable', 'integer', 'min:1'],
            'tiers.*.rate' => ['required_with:tiers', 'numeric', 'min:0', 'max:100'],
            'accrual_trigger' => ['nullable', 'in:confirmed,shipped,delivered'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // النسبة المئوية لا تتجاوز 100 — خطأ شائع يُفسد كل القيود لاحقاً
        if (($data['type'] ?? null) === 'percentage' && ($data['value'] ?? 0) > 100) {
            abort(422, __('نسبة العمولة لا يمكن أن تتجاوز 100%.'));
        }

        if (($data['scope'] ?? null) !== 'global' && empty($data['scope_id'])) {
            abort(422, __('يجب تحديد الجهة التي تنطبق عليها القاعدة.'));
        }

        return $data;
    }

    private function present(CommissionRule $rule): array
    {
        $target = match ($rule->scope) {
            'category' => Category::find($rule->scope_id)?->t('name'),
            'supplier' => Tenant::find($rule->scope_id)?->name,
            'plan' => SubscriptionPlan::find($rule->scope_id)?->t('name'),
            default => null,
        };

        return [
            'id' => $rule->id,
            'scope' => $rule->scope,
            'scope_id' => $rule->scope_id,
            'target' => $target,
            'type' => $rule->type,
            'value' => $rule->value,
            'tiers' => $rule->tiers,
            'accrual_trigger' => $rule->accrual_trigger,
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
        ];
    }
}
