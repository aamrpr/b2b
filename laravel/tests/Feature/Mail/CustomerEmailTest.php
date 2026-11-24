<?php

namespace Tests\Feature\Mail;

use App\Mail\TransactionalMessage;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Support\EmailTemplate;
use App\Models\User;
use App\Notifications\SupplierVerificationNotification;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * رسائل العميل الأربع تخرج من القالب الذي يحرّره السوبر أدمن،
 * وبلغته، ولا تُرسَل إن أوقفها. وكلمة المرور لا تُكتب في الرسالة.
 */
class CustomerEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_queues_the_template_in_the_customer_language(): void
    {
        Mail::fake();

        $user = $this->customer('en');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        Mail::assertQueued(TransactionalMessage::class, function (TransactionalMessage $mail) use ($user) {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && $mail->messageLocale === 'en'
                && str_contains($mail->htmlBody, 'was just signed in')
                && str_contains($mail->htmlBody, $user->name)
                && str_contains($html, '/brand/logo-dark.png')
                && str_contains($html, 'Your goods start here')
                && str_contains($html, '/account');
        });
    }

    public function test_a_wrong_password_and_a_disabled_template_send_nothing(): void
    {
        Mail::fake();

        $user = $this->customer('ar');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'nope',
        ])->assertUnprocessable();

        EmailTemplate::where('key', 'login')->update(['enabled' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_register_and_password_change_use_their_templates(): void
    {
        Mail::fake();

        $registered = $this->postJson('/api/v1/auth/register/buyer', [
            'company_name' => 'تاجر الجملة',
            'contact_name' => 'سامر',
            'email' => 'samer@test.local',
            'phone' => '0999000000',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        Mail::assertQueued(TransactionalMessage::class, function (TransactionalMessage $mail) {
            return $mail->hasTo('samer@test.local')
                && str_contains($mail->htmlBody, 'أُنشئ حسابك');
        });

        $this->withHeader('Authorization', 'Bearer '.$registered->json('token'))
            ->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        Mail::assertQueued(TransactionalMessage::class, function (TransactionalMessage $mail) {
            return $mail->hasTo('samer@test.local')
                && str_contains($mail->htmlBody, 'تم تغيير كلمة مرور')
                && ! str_contains($mail->htmlBody, 'new-password');
        });
    }

    public function test_a_new_price_request_confirms_to_the_buyer(): void
    {
        Mail::fake();

        $supplier = $this->makeSupplier();
        $category = Category::create([
            'name' => ['ar' => 'قطع', 'en' => 'Parts'],
            'slug' => 'parts-mail',
        ]);
        $product = Product::create([
            'tenant_id' => $supplier->id,
            'category_id' => $category->id,
            'name' => ['ar' => 'فلتر', 'en' => 'Filter'],
            'slug' => 'filter-mail',
            'status' => 'active',
            'unit_of_measure' => 'piece',
        ]);

        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر جملة']);
        $user = User::create([
            'tenant_id' => $buyer->id,
            'name' => 'مشترٍ',
            'email' => 'buyer-mail@test.local',
            'password' => 'password',
            'locale' => 'ar',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/rfqs', [
            'title' => 'مطلوب فلاتر',
            'category_id' => $category->id,
            'items' => [[
                'description' => 'فلتر',
                'quantity' => 10,
                'unit' => 'piece',
                'product_id' => $product->id,
            ]],
        ])->assertCreated();

        Mail::assertQueued(TransactionalMessage::class, function (TransactionalMessage $mail) {
            return $mail->hasTo('buyer-mail@test.local')
                && str_contains($mail->subjectLine, 'RFQ-')
                && str_contains($mail->htmlBody, 'مطلوب فلاتر');
        });
    }

    public function test_only_the_platform_can_edit_a_template_and_scripts_are_stripped(): void
    {
        $supplier = $this->makeSupplier();
        $supplierUser = User::create([
            'tenant_id' => $supplier->id,
            'name' => 'مستورد',
            'email' => 'importer@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($supplierUser, 'sanctum')
            ->getJson('/api/v1/admin/email-templates')
            ->assertForbidden();

        $admin = User::create([
            'tenant_id' => $this->platform()->id,
            'name' => 'مدير',
            'email' => 'platform-mail@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/email-templates/login', [
            'enabled' => true,
            'subject' => ['ar' => 'دخول جديد', 'en' => 'New sign-in'],
            'body' => [
                'ar' => '<p>مرحباً {{name}}</p><script>alert(1)</script><p onclick="evil()">تم الدخول</p>',
                'en' => '<p>Hello {{name}}</p><a href="javascript:alert(1)">bad</a><a href="https://example.com">ok</a>',
            ],
        ])->assertOk();

        $template = EmailTemplate::where('key', 'login')->firstOrFail();
        $arabic = $template->t('body', 'ar');
        $english = $template->t('body', 'en');

        $this->assertStringNotContainsString('script', $arabic);
        $this->assertStringNotContainsString('onclick', $arabic);
        $this->assertStringContainsString('{{name}}', $arabic);
        $this->assertStringNotContainsString('javascript:', $english);
        $this->assertStringContainsString('https://example.com', $english);

        Mail::fake();

        $user = $this->customer('ar');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        Mail::assertQueued(TransactionalMessage::class, function (TransactionalMessage $mail) {
            return str_contains($mail->subjectLine, 'دخول جديد')
                && str_contains($mail->htmlBody, 'تم الدخول');
        });
    }

    public function test_verification_contact_and_suspension_email_the_customer(): void
    {
        Mail::fake();

        $user = $this->customer('ar');
        $user->notify(new SupplierVerificationNotification(true));

        $this->postJson('/api/v1/contact', [
            'name' => 'ليلى',
            'email' => 'layla@test.local',
            'subject' => 'استفسار عن التوريد',
            'message' => 'أريد معرفة آلية العمل.',
        ])->assertCreated();

        $admin = User::create([
            'tenant_id' => $this->platform()->id,
            'name' => 'مدير',
            'email' => 'status-admin@test.local',
            'password' => 'password',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$user->id}", ['status' => 'suspended'])
            ->assertOk();

        Mail::assertQueued(TransactionalMessage::class, function (TransactionalMessage $mail) use ($user) {
            return $mail->hasTo($user->email) && str_contains($mail->htmlBody, 'تم الاعتماد');
        });
        Mail::assertQueued(TransactionalMessage::class, function (TransactionalMessage $mail) {
            return $mail->hasTo('layla@test.local') && str_contains($mail->htmlBody, 'استفسار عن التوريد');
        });
        Mail::assertQueued(TransactionalMessage::class, function (TransactionalMessage $mail) use ($user) {
            return $mail->hasTo($user->email) && str_contains($mail->htmlBody, 'موقوف');
        });
    }

    private function customer(string $locale): User
    {
        $buyer = app(TenantProvisioner::class)->createBuyer(['name' => 'تاجر '.$locale]);

        return User::create([
            'tenant_id' => $buyer->id,
            'name' => $locale === 'en' ? 'Samer' : 'سامر',
            'email' => $locale.'-customer@test.local',
            'password' => 'password',
            'locale' => $locale,
            'status' => 'active',
        ]);
    }
}
