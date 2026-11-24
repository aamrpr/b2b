<?php

namespace App\Models\Catalog;

use App\Models\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تخصّص المستورد بصنف معيّن — أساس دليل المستوردين وبثّ طلبات عروض الأسعار.
 */
class SupplierCategory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'brands' => 'array',
            'is_primary' => 'boolean',
            'receives_rfq' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
