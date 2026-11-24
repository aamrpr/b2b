<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChartOfAccountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'parent_id' => $a->parent_id,
                'code' => $a->code,
                'name' => $a->t('name'),
                'name_translations' => $a->name,
                'type' => $a->type,
                'system_key' => $a->system_key,
                'normal_balance' => $a->normal_balance,
                'is_leaf' => $a->is_leaf,
                'is_system' => $a->is_system,
                'balance' => $a->is_leaf ? $a->balance() : null,
            ]);

        return response()->json([
            'currency' => $request->user()->tenant->base_currency,
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'code' => ['required', 'string', 'max:20'],
            'name.ar' => ['required', 'string', 'max:191'],
            'name.en' => ['nullable', 'string', 'max:191'],
            'type' => ['required', 'in:asset,liability,equity,revenue,expense'],
        ]);

        $exists = Account::where('code', $data['code'])->exists();
        abort_if($exists, 422, __('الكود مستخدم مسبقاً في دليل الحسابات.'));

        $account = Account::create([
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'normal_balance' => in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit',
            'is_leaf' => true,
            'is_system' => false,
        ]);

        if ($account->parent_id) {
            Account::where('id', $account->parent_id)->update(['is_leaf' => false]);
        }

        return response()->json(['account' => $account], 201);
    }
}
