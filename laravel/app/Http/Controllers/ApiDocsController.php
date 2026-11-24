<?php

namespace App\Http\Controllers;

use App\Support\ApiDocs\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * صفحة الوثيقة ومواصفتها.
 *
 * المواصفة تُبنى عند الطلب من تعريفها في الشيفرة، فلا خطوة بناءٍ تُنسى
 * ولا ملفٌّ يصير قديماً: ما تقرؤه الصفحة هو ما في الشيفرة الآن.
 * و`php artisan api:docs --write` لمن أراد ملفاً ساكناً يُعطيه لمولّد
 * شيفرة أو لبرنامج اختبار.
 *
 * وهي مغلقة في الإنتاج افتراضاً: وثيقةٌ للمطوّرين لا صفحةٌ للسوق.
 */
class ApiDocsController extends Controller
{
    public function page(): View
    {
        $this->ensureEnabled();

        return view('api-docs');
    }

    public function spec(): JsonResponse
    {
        $this->ensureEnabled();

        return response()->json(
            Document::build(),
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('b2b.docs.enabled'), 404);
    }
}
