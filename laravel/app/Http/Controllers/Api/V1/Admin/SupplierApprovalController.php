<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use App\Notifications\SupplierVerificationNotification;
use App\Services\Admin\AuditLogger;
use App\Services\Mail\TransactionalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * اعتماد المستوردين: المستورد لا يظهر في الدليل ولا تصله طلبات
 * عروض الأسعار قبل أن يُعتمد حسابه هنا.
 */
class SupplierApprovalController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $query = Tenant::query()
            ->where('type', Tenant::TYPE_SUPPLIER)
            ->with(['supplierProfile', 'categories.category'])
            ->withCount('users');

        if ($status !== 'all') {
            $query->whereHas('supplierProfile', fn ($q) => $q->where('verification_status', $status));
        }

        $suppliers = $query->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => $suppliers->getCollection()->map(fn (Tenant $tenant) => $this->present($tenant)),
            'meta' => [
                'current_page' => $suppliers->currentPage(),
                'last_page' => $suppliers->lastPage(),
                'total' => $suppliers->total(),
            ],
        ]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        abort_unless($tenant->isSupplier(), 404);

        $tenant->load(['supplierProfile', 'categories.category', 'users']);

        $documents = DB::table('verification_documents')
            ->where('tenant_id', $tenant->id)
            ->get()
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'type' => $doc->type,
                'url' => asset('storage/'.$doc->file_path),
                'status' => $doc->status,
                'rejection_reason' => $doc->rejection_reason,
            ]);

        return response()->json([
            'supplier' => $this->present($tenant),
            'documents' => $documents,
            'users' => $tenant->users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
            ]),
        ]);
    }

    public function verify(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($tenant->isSupplier(), 404);

        $profile = $tenant->supplierProfile;
        abort_unless($profile, 422, __('لا يوجد ملف مستورد لهذا الحساب.'));

        $previous = $profile->verification_status;

        DB::transaction(function () use ($tenant, $profile) {
            $profile->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);

            $tenant->update(['status' => 'active']);
        });

        $this->audit->record('supplier.verified', $tenant,
            ['verification_status' => $previous],
            ['verification_status' => 'verified'],
        );

        $this->notifyUsers($tenant, true, null);

        return response()->json([
            'message' => __('تم اعتماد المستورد.'),
            'supplier' => $this->present($tenant->fresh()->load('supplierProfile', 'categories.category')),
        ]);
    }

    public function reject(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($tenant->isSupplier(), 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $profile = $tenant->supplierProfile;
        abort_unless($profile, 422, __('لا يوجد ملف مستورد لهذا الحساب.'));

        $previous = $profile->verification_status;

        DB::transaction(function () use ($tenant, $profile, $data) {
            $profile->update([
                'verification_status' => 'rejected',
                'rejection_reason' => $data['reason'],
                'verified_at' => null,
            ]);

            $tenant->update(['status' => 'rejected']);
        });

        $this->audit->record('supplier.rejected', $tenant,
            ['verification_status' => $previous],
            ['verification_status' => 'rejected', 'reason' => $data['reason']],
        );

        $this->notifyUsers($tenant, false, $data['reason']);

        return response()->json(['message' => __('تم رفض الطلب وإبلاغ المستورد.')]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        abort_unless($tenant->isSupplier(), 404);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $previous = $tenant->status;

        $tenant->update(['status' => $tenant->status === 'suspended' ? 'active' : 'suspended']);

        $this->audit->record(
            $tenant->status === 'suspended' ? 'supplier.suspended' : 'supplier.reactivated',
            $tenant,
            ['status' => $previous],
            ['status' => $tenant->status, 'reason' => $data['reason'] ?? null],
        );

        User::where('tenant_id', $tenant->id)->get()->each(function (User $user) use ($tenant, $data) {
            $en = $user->locale === 'en';

            app(TransactionalMail::class)->send($user, 'account', [
                'status' => $tenant->status === 'suspended'
                    ? ($en ? 'Suspended' : 'موقوف')
                    : ($en ? 'Active' : 'فعّال'),
                'reason' => $data['reason'] ?? '',
            ], '/account');
        });

        return response()->json([
            'message' => $tenant->status === 'suspended'
                ? __('تم تعليق الحساب.')
                : __('تم تفعيل الحساب.'),
            'status' => $tenant->status,
        ]);
    }

    private function notifyUsers(Tenant $tenant, bool $approved, ?string $reason): void
    {
        User::where('tenant_id', $tenant->id)
            ->get()
            ->each(fn (User $user) => $user->notify(new SupplierVerificationNotification($approved, $reason)));
    }

    private function present(Tenant $tenant): array
    {
        $profile = $tenant->supplierProfile;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'city' => $tenant->city,
            'country_code' => $tenant->country_code,
            'base_currency' => $tenant->base_currency,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'users_count' => $tenant->users_count ?? null,
            'profile' => $profile ? [
                'legal_name' => $profile->legal_name,
                'display_name' => $profile->t('display_name'),
                'commercial_register_no' => $profile->commercial_register_no,
                'tax_number' => $profile->tax_number,
                'phone' => $profile->phone,
                'verification_status' => $profile->verification_status,
                'verified_at' => $profile->verified_at?->toIso8601String(),
                'rejection_reason' => $profile->rejection_reason,
                'rating' => (float) $profile->rating_avg,
                'completed_orders' => (int) $profile->completed_orders_count,
            ] : null,
            'categories' => $tenant->relationLoaded('categories')
                ? $tenant->categories->map(fn ($link) => [
                    'id' => $link->category_id,
                    'name' => $link->category?->t('name'),
                    'is_primary' => $link->is_primary,
                ])
                : [],
        ];
    }
}
