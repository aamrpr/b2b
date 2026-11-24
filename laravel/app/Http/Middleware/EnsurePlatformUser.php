<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * لوحة المنصة مقصورة على مستخدمي المستأجر من نوع platform.
 * لا يكفي الدور — نوع المستأجر نفسه هو الحد الفاصل.
 */
class EnsurePlatformUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->tenant?->isPlatform(), 403, __('هذه الصفحة مقصورة على إدارة المنصة.'));

        return $next($request);
    }
}
