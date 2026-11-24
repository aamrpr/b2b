<?php

namespace App\Models\Communication;

use App\Models\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * محادثة بين طرفين تجاريين، مرتبطة بسياق: طلب عرض سعر، عرض، أو أمر شراء.
 * الطرف هو المستأجر (الشركة) لا المستخدم، فتبقى المحادثة قائمة لو تغيّر الموظف.
 */
class Conversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function context(): MorphTo
    {
        return $this->morphTo('context');
    }

    public function includes(int $tenantId): bool
    {
        return $this->participants->contains('tenant_id', $tenantId);
    }

    /** الطرف الآخر من وجهة نظر مستأجر معيّن. */
    public function counterpart(int $tenantId): ?Tenant
    {
        // firstWhere لا يقبل مغلقاً — يأخذ مفتاحاً وقيمة
        return $this->participants
            ->first(fn ($participant) => $participant->tenant_id !== $tenantId)
            ?->tenant;
    }

    public function unreadCountFor(int $tenantId): int
    {
        $participant = $this->participants->firstWhere('tenant_id', $tenantId);

        if (! $participant) {
            return 0;
        }

        return $this->messages()
            ->where('sender_tenant_id', '!=', $tenantId)
            ->where('id', '>', (int) $participant->last_read_message_id)
            ->count();
    }
}
