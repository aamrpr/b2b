<?php

namespace App\Models\Support;

use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * رسالة يرسلها المنصّة للعميل، ونصّها بيد السوبر أدمن.
 */
class EmailTemplate extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['subject', 'body'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
