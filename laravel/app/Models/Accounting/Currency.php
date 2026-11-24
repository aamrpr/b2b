<?php

namespace App\Models\Accounting;

use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasTranslations;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** المعامل بين الوحدة الكبرى والصغرى (100 لعملة بخانتين عشريتين) */
    public function factor(): int
    {
        return 10 ** $this->decimal_places;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
