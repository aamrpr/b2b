<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsurePlatformUser;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        // التطبيق يستخدم رموز Sanctum لا جلسات الويب، فمسار اعتماد القنوات يتبع نفس المسار
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // تحديد اللغة لكل طلب API قبل أي منطق
        $middleware->api(prepend: [
            SetLocale::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform' => EnsurePlatformUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // خرق قاعدة العمل مُدخَل غير صالح لا عطل خادم
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    ...$e->context(),
                ], 422);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
