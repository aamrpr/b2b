<?php

namespace App\Models\Shipping;

use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_weight_kg' => 'float',
            'max_weight_kg' => 'float',
            'base_price' => 'integer',
            'price_per_kg' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function appliesTo(?string $from, ?string $to, float $weight): bool
    {
        $matchesRoute = ($this->from_city === null || $this->from_city === $from)
            && ($this->to_city === null || $this->to_city === $to);

        $matchesWeight = $weight >= $this->min_weight_kg
            && ($this->max_weight_kg === null || $weight <= $this->max_weight_kg);

        return $this->is_active && $matchesRoute && $matchesWeight;
    }

    public function quote(float $weight): Money
    {
        $billableWeight = max(0, $weight - $this->min_weight_kg);

        return Money::of(
            $this->base_price + (int) round($billableWeight * $this->price_per_kg),
            $this->currency
        );
    }
}
