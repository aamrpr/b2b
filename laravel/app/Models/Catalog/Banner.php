<?php

namespace App\Models\Catalog;

use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * بنر دعائي في الصفحة الرئيسية. النص يُترجم، والرابط مسار داخل المنصة.
 */
class Banner extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['eyebrow', 'title', 'text'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
