<?php

namespace App\Models\Shipping;

use App\Models\User;
use App\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** حدث تتبّع. السلسلة تُضاف إليها ولا تُعدَّل — هي سجل الشحنة. */
class ShipmentEvent extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected array $translatable = ['description'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('حدث التتبّع لا يُعدَّل — أضف حدثاً جديداً.'));
        static::deleting(fn () => throw new RuntimeException('حدث التتبّع لا يُحذف.'));
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
