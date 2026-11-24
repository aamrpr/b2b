<?php

namespace App\Models\Tenancy;

use App\Models\Accounting\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Catalog\Product;
use App\Models\Catalog\SupplierCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_PLATFORM = 'platform';

    public const TYPE_SUPPLIER = 'supplier';

    public const TYPE_BUYER = 'buyer';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fiscal_year_start_month' => 'integer',
            'accounting_enabled' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function supplierProfile(): HasOne
    {
        return $this->hasOne(SupplierProfile::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(SupplierCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function isPlatform(): bool
    {
        return $this->type === self::TYPE_PLATFORM;
    }

    public function isSupplier(): bool
    {
        return $this->type === self::TYPE_SUPPLIER;
    }

    public function isBuyer(): bool
    {
        return $this->type === self::TYPE_BUYER;
    }

    public static function platform(): self
    {
        return once(fn () => self::where('type', self::TYPE_PLATFORM)->firstOrFail());
    }
}
