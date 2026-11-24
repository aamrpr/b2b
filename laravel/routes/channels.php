<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/** إشعارات المستخدم الواحد — قناة Laravel الافتراضية للإشعارات. */
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

/**
 * قناة المستأجر: الرسائل تصل لكل موظفي الشركة المتصلين،
 * فلا تضيع رسالة لأن الموظف الذي فتح المحادثة غير متصل.
 */
Broadcast::channel('tenant.{tenantId}', function (User $user, int $tenantId) {
    return $user->tenant_id === $tenantId;
});
