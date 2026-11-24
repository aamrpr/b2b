<?php

namespace App\Services\Admin;

use App\Models\Support\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * تسجيل العمليات الحسّاسة: من فعل، ماذا، متى، ومن أي عنوان.
 * يُستدعى صراحةً عند الأفعال الإدارية بدل المراقبة الضمنية،
 * فيبقى السجل مقروءاً ومقصوداً لا ضجيجاً.
 */
class AuditLogger
{
    public function record(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?int $tenantId = null,
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => $tenantId ?? auth()->user()?->tenant_id,
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
