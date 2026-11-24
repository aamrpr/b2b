<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * توقيع مرسوم على لوحة في المتصفّح يصل data URL لا ملفاً مرفوعاً.
 *
 * ويُفحص قبل الحفظ فحص الملفات المرفوعة نفسه: الترويسة أوّلاً — بايتات
 * PNG الأولى — لا الامتداد ولا ما يدّعيه الـURL، ثمّ الحجم. وبدونه يصير
 * حقل التوقيع باباً لحفظ أيّ شيء في التخزين العامّ.
 */
class SignatureImage
{
    private const PREFIX = 'data:image/png;base64,';

    private const MAGIC = "\x89PNG\r\n\x1a\n";

    /** توقيع خطّ يد لا صورة فوتوغرافية، فـ400 كيلوبايت سقفٌ سخيّ */
    private const MAX_BYTES = 400 * 1024;

    public static function store(string $dataUrl, string $path): string
    {
        Storage::disk('public')->put($path, self::decode($dataUrl));

        return $path;
    }

    private static function decode(string $dataUrl): string
    {
        $fail = fn () => throw ValidationException::withMessages([
            'signature' => __('التوقيع غير صالح.'),
        ]);

        if (! str_starts_with($dataUrl, self::PREFIX)) {
            $fail();
        }

        $binary = base64_decode(substr($dataUrl, strlen(self::PREFIX)), true);

        if ($binary === false || ! str_starts_with($binary, self::MAGIC) || strlen($binary) > self::MAX_BYTES) {
            $fail();
        }

        return $binary;
    }
}
