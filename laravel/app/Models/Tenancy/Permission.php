<?php

namespace App\Models\Tenancy;

use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['label'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
