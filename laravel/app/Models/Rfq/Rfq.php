<?php

namespace App\Models\Rfq;

use App\Models\Catalog\Category;
use App\Models\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * طلب عرض سعر ينشره تاجر الجملة فيصل إلى المستوردين المسجّلين في الفئة.
 */
class Rfq extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'rfqs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'delivery_deadline' => 'date',
            'hide_buyer_identity' => 'boolean',
            'target_price' => 'integer',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'buyer_tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RfqInvitation::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'quoted'], true)
            && $this->expires_at->isFuture();
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'quoted'])
            ->where('expires_at', '>', now());
    }
}
