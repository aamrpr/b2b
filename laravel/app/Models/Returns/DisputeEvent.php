<?php

namespace App\Models\Returns;

use App\Models\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** سجل النزاع — يُضاف إليه ولا يُعدَّل، فهو مستند القرار. */
class DisputeEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_internal' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('سجل النزاع لا يُعدَّل.'));
        static::deleting(fn () => throw new RuntimeException('سجل النزاع لا يُحذف.'));
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): ?string
    {
        return $this->file_path ? asset('storage/'.$this->file_path) : null;
    }

    public function isFromPlatform(): bool
    {
        return $this->tenant_id === null;
    }
}
