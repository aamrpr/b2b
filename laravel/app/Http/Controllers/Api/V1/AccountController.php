<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Tenancy\SupplierProfile;
use App\Services\Mail\TransactionalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * حساب المستخدم وشركته.
 *
 * كل مستخدم يملك حسابه: يعدّل معلوماته وكلمة مروره وصورته بنفسه، ولا
 * يحتاج إلى من يفعلها عنه. وصاحب الشركة يعدّل معها بيانات الشركة
 * وشعارها — والشعار هو ما يراه المشتري في الدليل وفي كل عرض سعر.
 */
class AccountController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        $user->fill($data)->save();

        return response()->json([
            'message' => __('تم حفظ المعلومات.'),
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('كلمة المرور الحالية غير صحيحة.'),
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        /*
         * تبديل كلمة المرور يُنهي الجلسات الأخرى: من غيّرها لأنّها تسرّبت
         * لا يريد للرمز القديم أن يظلّ صالحاً. وجلسته الحالية تبقى.
         */
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        app(TransactionalMail::class)->send($user, 'password', [], '/account');

        return response()->json(['message' => __('تم تغيير كلمة المرور.')]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'],
        ]);

        $user = $request->user();

        $this->forget($user->avatar_path);
        $user->forceFill(['avatar_path' => $request->file('avatar')->store('avatars', 'public')])->save();

        return response()->json([
            'message' => __('تم حفظ الصورة.'),
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->forget($user->avatar_path);
        $user->forceFill(['avatar_path' => null])->save();

        return response()->json([
            'message' => __('تم حذف الصورة.'),
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function updateCompany(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);

        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:191'],
            'name.en' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:2000'],
            'description.en' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
        ]);

        $tenant->forceFill(['city' => $data['city'] ?? $tenant->city])->save();

        $profile = $this->profile($tenant);

        $profile->forceFill([
            'display_name' => [
                'ar' => $data['name']['ar'],
                'en' => ($data['name']['en'] ?? null) ?: $data['name']['ar'],
            ],
            'description' => array_filter([
                'ar' => $data['description']['ar'] ?? null,
                'en' => $data['description']['en'] ?? null,
            ]) ?: null,
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
        ])->save();

        return response()->json([
            'message' => __('تم حفظ بيانات الشركة.'),
            'user' => new UserResource($request->user()->fresh()),
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $profile = $this->profile($this->tenant($request));

        $this->forget($profile->logo_path);
        $profile->forceFill(['logo_path' => $request->file('logo')->store('logos', 'public')])->save();

        return response()->json([
            'message' => __('تم حفظ الشعار.'),
            'user' => new UserResource($request->user()->fresh()),
        ]);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $profile = $this->profile($this->tenant($request));

        $this->forget($profile->logo_path);
        $profile->forceFill(['logo_path' => null])->save();

        return response()->json([
            'message' => __('تم حذف الشعار.'),
            'user' => new UserResource($request->user()->fresh()),
        ]);
    }

    private function tenant(Request $request)
    {
        $tenant = $request->user()->tenant;

        abort_unless($tenant && ! $tenant->isPlatform(), 403);

        return $tenant;
    }

    /** الملفّ التعريفي يُنشأ عند أوّل حفظ: حساب المشتري يبدأ بلا ملفّ. */
    private function profile($tenant): SupplierProfile
    {
        return $tenant->supplierProfile
            ?? SupplierProfile::create([
                'tenant_id' => $tenant->id,
                'legal_name' => $tenant->name,
                'display_name' => ['ar' => $tenant->name, 'en' => $tenant->name],
            ]);
    }

    private function forget(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
