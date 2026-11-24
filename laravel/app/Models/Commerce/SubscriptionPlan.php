<?php

namespace App\Models\Commerce;

use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'accounting_enabled' => 'boolean',
            'is_active' => 'boolean',
            'commission_discount' => 'float',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
