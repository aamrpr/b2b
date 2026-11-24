<?php

namespace App\Http\Resources\Concerns;

/**
 * يُخرج كل حقل مترجم مرتين: النص بلغة الطلب، وكائن الترجمات الكامل
 * ليتمكن المستورد من تحرير النسختين العربية والإنجليزية في الواجهة.
 */
trait TranslatesFields
{
    protected function translated(string $field): array
    {
        $model = $this->resource;

        return [
            $field => $model->t($field),
            $field.'_translations' => $model->getAttribute($field) ?? new \stdClass,
        ];
    }
}
