<?php

namespace App\Services\Mail;

use App\Mail\TransactionalMessage;
use App\Models\Support\EmailTemplate;
use App\Models\User;
use App\Support\Mail\EmailHtml;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * يرسل رسالة العميل إن كانت مفعّلة في لوحة السوبر أدمن.
 *
 * لغة الرسالة لغة حسابه. وفشل البريد لا يوقف الدخول ولا إنشاء الطلب:
 * الإرسال يذهب إلى الطابور، وأي خطأ يُسجَّل ويُترك. والإطار — الشعار
 * واللون والزر — ثابت، والنص وحده هو الذي يحرّره السوبر أدمن.
 */
class TransactionalMail
{
    public function send(User $user, string $key, array $vars = [], ?string $actionPath = null): void
    {
        $this->deliver($user->email, $user->name, $user->locale, $key, $vars, $actionPath);
    }

    /** لمن كتب من غير حساب، كرسالة التواصل. */
    public function sendAddress(string $email, string $name, ?string $locale, string $key, array $vars = [], ?string $actionPath = null): void
    {
        $this->deliver($email, $name, $locale, $key, $vars, $actionPath);
    }

    private function deliver(?string $email, ?string $name, ?string $locale, string $key, array $vars, ?string $actionPath): void
    {
        if ($email === null || $email === '') {
            return;
        }

        $template = EmailTemplate::query()->where('key', $key)->first();

        if (! $template || ! $template->enabled) {
            return;
        }

        $locale = in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';

        $vars = array_merge([
            'name' => $name ?? '',
            'email' => $email,
            'app' => 'المستورد',
            'time' => now()->timezone('Asia/Damascus')->format('Y-m-d H:i'),
        ], $vars);

        $subject = $this->fill((string) $template->t('subject', $locale), $vars, escape: false);
        $body = EmailHtml::clean($this->fill((string) $template->t('body', $locale), $vars, escape: true));

        if (trim(strip_tags($body)) === '') {
            return;
        }

        $url = $this->absoluteAction($actionPath);

        if ($url !== null) {
            $body .= $this->button($url, $locale);
        }

        try {
            Mail::to($email)->queue(new TransactionalMessage($subject, $body, $locale));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function absoluteAction(?string $path): ?string
    {
        if ($path === null || $path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    private function button(string $url, string $locale): string
    {
        $label = $locale === 'ar' ? 'فتح في المستورد' : 'Open in Al Mustawred';
        $align = $locale === 'ar' ? 'right' : 'left';

        return '<p style="margin:28px 0 0;text-align:'.$align.'"><a href="'.e($url).'" style="display:inline-block;background:#087f7b;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;line-height:1;padding:14px 22px;border-radius:10px;">'.e($label).'</a></p>';
    }

    private function fill(string $text, array $vars, bool $escape): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $match) use ($vars, $escape): string {
            if (! array_key_exists($match[1], $vars) || $vars[$match[1]] === null) {
                return '';
            }

            $value = trim(strip_tags((string) $vars[$match[1]]));

            return $escape ? e($value) : $value;
        }, $text) ?? $text;
    }
}
