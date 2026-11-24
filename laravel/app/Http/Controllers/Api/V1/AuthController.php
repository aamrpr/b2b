<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Catalog\SupplierCategory;
use App\Models\Tenancy\SupplierProfile;
use App\Models\User;
use App\Services\Mail\TransactionalMail;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly TransactionalMail $mail,
    ) {}

    /** تسجيل مستورد: ينشئ المستأجر ودليل حساباته ويربطه بأصنافه. */
    public function registerSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:191'],
            'company_name_en' => ['nullable', 'string', 'max:191'],
            'contact_name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'city' => ['nullable', 'string', 'max:100'],
            'base_currency' => ['nullable', 'string', 'size:3'],
            'locale' => ['nullable', 'in:ar,en'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'brands' => ['nullable', 'array'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $tenant = $this->provisioner->createSupplier([
                'name' => $data['company_name'],
                'city' => $data['city'] ?? null,
                'base_currency' => $data['base_currency'] ?? config('b2b.platform_currency'),
                'locale' => $data['locale'] ?? 'ar',
            ]);

            SupplierProfile::create([
                'tenant_id' => $tenant->id,
                'legal_name' => $data['company_name'],
                'display_name' => [
                    'ar' => $data['company_name'],
                    'en' => $data['company_name_en'] ?? $data['company_name'],
                ],
                'phone' => $data['phone'],
            ]);

            foreach ($data['category_ids'] as $index => $categoryId) {
                SupplierCategory::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $categoryId,
                    'is_primary' => $index === 0,
                    'brands' => $data['brands'] ?? null,
                ]);
            }

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['contact_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'ar',
            ]);

            return compact('tenant', 'user');
        });

        $this->mail->send($result['user'], 'register', [], '/login');

        return response()->json([
            'message' => __('تم إنشاء الحساب. سيُراجَع التوثيق خلال فترة قصيرة.'),
            'token' => $result['user']->createToken('api')->plainTextToken,
            'user' => $this->userPayload($result['user']),
        ], 201);
    }

    /** تسجيل تاجر جملة (مشترٍ). */
    public function registerBuyer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:191'],
            'contact_name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'city' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $tenant = $this->provisioner->createBuyer([
                'name' => $data['company_name'],
                'city' => $data['city'] ?? null,
                'locale' => $data['locale'] ?? 'ar',
            ]);

            return User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['contact_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'ar',
            ]);
        });

        $this->mail->send($user, 'register', [], '/login');

        return response()->json([
            'message' => __('تم إنشاء الحساب بنجاح.'),
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! \Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('بيانات الدخول غير صحيحة.'),
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => __('هذا الحساب موقوف.'),
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $this->mail->send($user, 'login', [], '/account');

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('تم تسجيل الخروج.')]);
    }

    private function userPayload(User $user): UserResource
    {
        return new UserResource($user);
    }
}
