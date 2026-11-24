<?php

namespace App\Models\Catalog;

use App\Support\Concerns\HasTranslations;
use App\Support\Concerns\OwnedByTenant;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasTranslations, OwnedByTenant, SoftDeletes;

    protected $guarded = ['id'];

    protected array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'price_visible' => 'boolean',
            'base_price' => 'integer',
            'stock_quantity' => 'integer',
            'specifications' => 'array',
        ];
    }

    /**
     * الخصائص بلغة واحدة: [{label, value}] بترتيب إدخال المستورد.
     *
     * التراجع هنا تراجع الحقول المترجمة نفسه — لغة الطلب، فالاحتياطية،
     * فأوّل قيمة — كي لا تختفي خاصيّة كُتبت بالعربية وحدها عن قارئ
     * إنجليزيّ، فغيابها يُقرأ نقصاً في المنتج لا في الترجمة.
     */
    public function localizedSpecifications(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $fallback = config('app.fallback_locale');

        $pick = function ($value) use ($locale, $fallback) {
            if (is_string($value)) {
                return $value;
            }

            if (! is_array($value) || $value === []) {
                return null;
            }

            return $value[$locale] ?? $value[$fallback] ?? reset($value);
        };

        return collect($this->specifications ?? [])
            ->map(fn ($row) => [
                'label' => $pick($row['label'] ?? null),
                'value' => $pick($row['value'] ?? null),
            ])
            ->filter(fn ($row) => filled($row['label']) && filled($row['value']))
            ->values()
            ->all();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_qty');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort_order');
    }

    public function price(): Money
    {
        return Money::of($this->base_price, $this->currency);
    }

    /** سعر الوحدة حسب الكمية المطلوبة — جوهر تسعير الجملة. */
    public function priceForQuantity(int $quantity): Money
    {
        $tier = $this->priceTiers
            ->first(fn ($t) => $quantity >= $t->min_qty && ($t->max_qty === null || $quantity <= $t->max_qty));

        return $tier
            ? Money::of($tier->unit_price, $tier->currency)
            : $this->price();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
