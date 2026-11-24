<?php

namespace App\Services\Localization;

use Illuminate\Http\Request;

class LocaleResolver
{
    /** @return array<int, string> */
    public static function supported(): array
    {
        return array_filter(array_map('trim', explode(',', (string) env('SUPPORTED_LOCALES', 'ar,en'))));
    }

    public static function isRtl(string $locale): bool
    {
        return in_array($locale, ['ar', 'fa', 'he', 'ur'], true);
    }

    /**
     * أولوية تحديد اللغة:
     * 1) ?lang=  2) ترويسة X-Locale  3) تفضيل المستخدم  4) Accept-Language  5) الافتراضية
     */
    public function resolve(Request $request): string
    {
        $supported = self::supported();

        $candidates = [
            $request->query('lang'),
            $request->header('X-Locale'),
            $request->user()?->locale,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        $accepted = $request->getPreferredLanguage(
            array_map(fn ($l) => $l, $supported)
        );

        return in_array($accepted, $supported, true)
            ? $accepted
            : config('app.locale');
    }
}
