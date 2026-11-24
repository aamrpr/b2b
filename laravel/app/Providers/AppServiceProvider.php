<?php

namespace App\Providers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // يرفض الكتابة إلى خاصية غير معرَّفة بدل تجاهلها بصمت
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // كشف مشاكل N+1 — يُفعَّل عند الحاجة فقط لأن خدمات المحاسبة
        // تعتمد التحميل الكسول عمداً على علاقات مفردة (tenant, supplier)
        Model::preventLazyLoading((bool) env('PREVENT_LAZY_LOADING', false));

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
