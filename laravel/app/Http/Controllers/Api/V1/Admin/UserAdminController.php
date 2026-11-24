<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use App\Services\Mail\TransactionalMail;
use App\Support\DataTables\ColumnMatch;
use App\Support\DataTables\DataTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * المستخدمون من عين المنصة.
 *
 * إيقاف حساب وإعادة ضبط كلمة مروره صلاحيتان خطرتان، فلهما حدّان:
 * تُسجَّلان في سجلّ التدقيق، ولا يستعملهما المدير على نفسه — من أراد
 * تغيير كلمة مروره فله صفحة حسابه، ومن أوقف نفسه هنا أغلق الباب
 * وأبقى المفتاح في الداخل.
 */
class UserAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            DataTable::make($request, User::query()->with('tenant.supplierProfile'))
                ->searchable(['name', 'email', 'phone'])
                ->sortable([
                    'id' => 'id',
                    'name' => 'name',
                    'email' => 'email',
                    'status' => 'status',
                    'created_at' => 'created_at',
                ])
                ->filter('name', function ($query, $value) {
                    // الخلية تعرض الاسم والبريد معاً، فبحثها يطابق الاثنين
                    $query->where(function ($query) use ($value) {
                        $query->where('name', 'like', "%{$value}%")
                            ->orWhere('email', 'like', "%{$value}%");
                    });
                })
                ->filter('status', fn ($query, $value) => $query->where('status', $value))
                ->filter('tenant', function ($query, $value) {
                    if (ctype_digit($value)) {
                        $query->where('tenant_id', $value);

                        return;
                    }

                    $query->whereHas('tenant', function ($tenant) use ($value) {
                        $tenant->where('name', 'like', "%{$value}%")
                            ->orWhereHas('supplierProfile', fn ($p) => $p->where('display_name', 'like', "%{$value}%"));
                    });
                })
                ->filter('type', fn ($query, $value) => $query->whereHas('tenant', fn ($t) => $t->where('type', $value)))
                ->filter('last_login_at', fn ($query, $value) => ColumnMatch::date($query, 'last_login_at', $value))
                ->defaultOrder('id')
                ->transform(fn (User $user) => $this->present($user))
                ->toArray()
        );
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->refuseSelf($request, $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', 'in:active,inactive,suspended'],
        ]);

        $previous = $user->only(['name', 'phone', 'status']);
        $user->fill($data)->save();

        /*
         * الإيقاف يُنهي جلسات المستخدم: حسابٌ موقوف برمزٍ صالح ما زال
         * يعمل — والإيقاف حينها إعلانٌ لا منع.
         */
        if (($data['status'] ?? null) !== null && $data['status'] !== 'active') {
            $user->tokens()->delete();
        }

        if (array_key_exists('status', $data) && $data['status'] !== $previous['status']) {
            app(TransactionalMail::class)->send($user, 'account', [
                'status' => $this->statusLabel($user),
            ], '/account');
        }

        $this->audit->record('user.updated', $user, $previous, $user->only(['name', 'phone', 'status']));

        return response()->json([
            'message' => __('تم حفظ المستخدم.'),
            'user' => $this->present($user->fresh()->load('tenant.supplierProfile')),
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->refuseSelf($request, $user);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()->delete();

        // كلمة المرور نفسها لا تُسجَّل، وإنّما أنّها بُدِّلت ومن بدّلها
        $this->audit->record('user.password_reset', $user, [], ['by' => $request->user()->id]);

        app(TransactionalMail::class)->send($user, 'password', [], '/account');

        return response()->json(['message' => __('تم تغيير كلمة المرور وإنهاء جلسات المستخدم.')]);
    }

    private function statusLabel(User $user): string
    {
        $en = $user->locale === 'en';

        return match ($user->status) {
            'suspended' => $en ? 'Suspended' : 'موقوف',
            'inactive' => $en ? 'Inactive' : 'غير فعّال',
            default => $en ? 'Active' : 'فعّال',
        };
    }

    private function refuseSelf(Request $request, User $user): void
    {
        abort_if(
            $user->id === $request->user()->id,
            422,
            __('لا يمكنك تطبيق هذا الإجراء على حسابك — استعمل صفحة الحساب.'),
        );
    }

    private function present(User $user): array
    {
        $tenant = $user->tenant;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'locale' => $user->locale,
            'avatar_url' => $user->avatar_path ? asset('storage/'.$user->avatar_path) : null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'type' => $tenant->type,
                'name' => $tenant->supplierProfile?->t('display_name') ?? $tenant->name,
                'status' => $tenant->status,
            ] : null,
        ];
    }

    /** الحسابات لقائمة التصفية: المنصة تختار منها لا تكتب رقماً */
    public function tenants(): JsonResponse
    {
        return response()->json([
            'data' => Tenant::query()
                ->with('supplierProfile')
                ->orderBy('type')
                ->orderBy('name')
                ->get()
                ->map(fn (Tenant $tenant) => [
                    'id' => $tenant->id,
                    'type' => $tenant->type,
                    'name' => $tenant->supplierProfile?->t('display_name') ?? $tenant->name,
                    'status' => $tenant->status,
                ]),
        ]);
    }
}
