<?php

namespace Tests\Feature\Account;

use App\Models\Tenancy\SupplierProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * حساب المستخدم: ما يملك تغييره بنفسه.
 *
 * وأهمّه كلمة المرور: من يغيّرها لأنّها تسرّبت لا ينفعه تغييرٌ تبقى معه
 * الرموز القديمة صالحة — فالاختبار يحرس إنهاء الجلسات الأخرى.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform();
        $tenant = $this->makeSupplier('شركة');

        SupplierProfile::create([
            'tenant_id' => $tenant->id,
            'legal_name' => 'شركة',
            'display_name' => ['ar' => 'شركة', 'en' => 'Company'],
        ]);

        $this->user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'أبو أحمد',
            'email' => 'owner@test.local',
            'password' => 'password',
            'status' => 'active',
        ]);
    }

    public function test_a_user_edits_their_own_information(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/v1/me', [
            'name' => 'أبو محمد',
            'email' => 'new@test.local',
            'phone' => '+963911111111',
            'locale' => 'en',
        ])->assertOk()->assertJsonPath('user.name', 'أبو محمد');

        $this->assertSame('new@test.local', $this->user->fresh()->email);
    }

    public function test_the_current_password_is_required_to_change_it(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/v1/me/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(\Hash::check('password', $this->user->fresh()->password));
    }

    public function test_changing_the_password_ends_the_other_sessions(): void
    {
        $stale = $this->user->createToken('phone')->accessToken;
        $current = $this->user->createToken('api');

        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertOk();

        $this->assertTrue(\Hash::check('new-password', $this->user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $stale->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
    }

    public function test_a_user_replaces_their_avatar_and_the_old_file_goes(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg')])->assertOk();

        $first = $this->user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($first);

        $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('other.jpg')])->assertOk();

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($this->user->fresh()->avatar_path);
    }

    public function test_the_company_name_and_logo_are_saved_on_the_profile(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->user);

        // الاسم يعود بلغة الطلب، فالترويسة هنا جزء من المتوقَّع لا زينة
        $this->putJson('/api/v1/my/company', [
            'name' => ['ar' => 'شركة النور', 'en' => 'Al-Noor'],
            'description' => ['ar' => 'مستورد معتمد'],
            'city' => 'حلب',
            'phone' => '+963911222333',
        ], ['X-Locale' => 'ar'])->assertOk()->assertJsonPath('user.tenant.name', 'شركة النور');

        $this->postJson('/api/v1/my/company/logo', ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertOk()
            ->assertJsonPath('user.tenant.city', 'حلب');

        $profile = $this->user->tenant->supplierProfile()->first();

        Storage::disk('public')->assertExists($profile->logo_path);
        $this->assertSame('Al-Noor', $profile->display_name['en']);
    }
}
