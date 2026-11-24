<?php

namespace App\Models\Purchasing;

use App\Models\Accounting\Invoice;
use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** مورّد المستورد: جهة خارجية لا مستأجر في المنصة. */
class Vendor extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** الرصيد المستحق لهذا المورّد من فواتيره غير المسدَّدة. */
    public function outstanding(): int
    {
        return (int) Invoice::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant_id)
            ->where('vendor_id', $this->id)
            ->where('type', 'purchase')
            ->sum('balance_due');
    }
}
