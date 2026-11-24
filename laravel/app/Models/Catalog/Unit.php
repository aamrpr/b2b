<?php

namespace App\Models\Catalog;

use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * وحدة قياس للمنصة كلها: الرمز يُخزَّن مع المنتج، والاسم يُترجم.
 */
class Unit extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
