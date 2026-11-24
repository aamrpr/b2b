<?php

namespace App\Support\Concerns;

/**
 * حقول مترجمة مخزَّنة JSON: {"ar": "...", "en": "..."}
 * إضافة لغة جديدة لاحقاً لا تتطلب أي تعديل على الجداول.
 */
trait HasTranslations
{
    public function initializeHasTranslations(): void
    {
        foreach ($this->translatable ?? [] as $field) {
            $this->casts[$field] = 'array';
        }
    }

    /** النص بلغة معيّنة مع التراجع إلى اللغة الاحتياطية ثم أول قيمة متاحة. */
    public function t(string $field, ?string $locale = null): ?string
    {
        $value = $this->getAttribute($field);

        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        $locale ??= app()->getLocale();

        return $value[$locale]
            ?? $value[config('app.fallback_locale')]
            ?? reset($value);
    }

    /** ضبط ترجمة واحدة دون المساس بالباقي. */
    public function setTranslation(string $field, string $locale, ?string $value): static
    {
        $translations = (array) ($this->getAttribute($field) ?? []);

        if ($value === null) {
            unset($translations[$locale]);
        } else {
            $translations[$locale] = $value;
        }

        $this->setAttribute($field, $translations);

        return $this;
    }

    public function isTranslatable(string $field): bool
    {
        return in_array($field, $this->translatable ?? [], true);
    }
}
