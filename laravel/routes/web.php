<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\SeoController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * وثيقة الواجهة البرمجية: صفحةٌ تُقرأ ويُجرَّب منها، ومواصفةٌ تُستورَد
 * في Postman أو يُولَّد منها عميل. خارج api/v1 عن قصد — فهي عن الواجهة
 * لا منها، ولا ينبغي أن تظهر في جدول مسارات الواجهة ولا في فحصها.
 */
Route::middleware(SetLocale::class)->group(function () {
    Route::get('seo.json', [SeoController::class, 'document']);
    Route::get('sitemap.xml', [SeoController::class, 'sitemap']);
    Route::get('robots.txt', [SeoController::class, 'robots']);
});

Route::get('api/docs', [ApiDocsController::class, 'page'])->name('api.docs');
Route::get('api/docs/openapi.json', [ApiDocsController::class, 'spec'])->name('api.docs.spec');
