<?php

namespace App\Models\Inventory;

use App\Support\Concerns\BelongsToTenant;
use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use BelongsToTenant, HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /** قيمة المخزون في هذا المستودع بعملة الدفتر. */
    public function value(): int
    {
        return (int) $this->items()
            ->selectRaw('COALESCE(SUM(total_value), 0) AS v')
            ->value('v');
    }
}
