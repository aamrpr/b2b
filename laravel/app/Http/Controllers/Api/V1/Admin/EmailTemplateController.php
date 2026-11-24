<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Support\EmailTemplate;
use App\Services\Admin\AuditLogger;
use App\Support\Mail\EmailHtml;
use App\Support\Mail\EmailTemplateDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * رسائل البريد التي تصل العميل.
 *
 * السوبر أدمن يفعّلها ويعطّلها ويعدّل موضوعها ونصّها باللغتين.
 * المفتاح ثابت — أربع رسائل فقط — حتى لا تُختلق رسالة بلا حدث يرسلها.
 */
class EmailTemplateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $templates = EmailTemplate::query()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'data' => $templates->map(fn (EmailTemplate $template) => $this->present($template)),
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'subject' => ['required', 'array'],
            'subject.ar' => ['required', 'string', 'max:191'],
            'subject.en' => ['required', 'string', 'max:191'],
            'body' => ['required', 'array'],
            'body.ar' => ['required', 'string', 'max:20000'],
            'body.en' => ['required', 'string', 'max:20000'],
        ]);

        $body = [
            'ar' => EmailHtml::clean($data['body']['ar']),
            'en' => EmailHtml::clean($data['body']['en']),
        ];

        abort_if(
            trim(strip_tags($body['ar'])) === '' || trim(strip_tags($body['en'])) === '',
            422,
            __('نص الرسالة فارغ.'),
        );

        $previous = [
            'enabled' => $emailTemplate->enabled,
            'subject' => $emailTemplate->subject,
        ];

        $emailTemplate->fill([
            'enabled' => $data['enabled'],
            'subject' => [
                'ar' => trim(strip_tags($data['subject']['ar'])),
                'en' => trim(strip_tags($data['subject']['en'])),
            ],
            'body' => $body,
        ])->save();

        $this->audit->record('email_template.updated', $emailTemplate, $previous, [
            'enabled' => $emailTemplate->enabled,
            'subject' => $emailTemplate->subject,
        ]);

        return response()->json([
            'message' => __('تم حفظ الرسالة.'),
            'template' => $this->present($emailTemplate->fresh()),
        ]);
    }

    private function present(EmailTemplate $template): array
    {
        return [
            'key' => $template->key,
            'enabled' => $template->enabled,
            'subject' => $template->t('subject'),
            'subject_translations' => $template->subject,
            'body' => $template->t('body'),
            'body_translations' => $template->body,
            'placeholders' => EmailTemplateDefaults::placeholders($template->key),
        ];
    }
}
