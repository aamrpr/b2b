<?php

namespace App\Models\Tenancy;

use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProfile extends Model
{
    use HasFactory, HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['display_name', 'description'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'rating_avg' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
