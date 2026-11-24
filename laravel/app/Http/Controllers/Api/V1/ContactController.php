<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Support\ContactMessage;
use App\Services\Mail\TransactionalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * رسائل التواصل: من الزائر ومن المسجَّل إلى إدارة المنصة.
 */
class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'subject' => ['required', 'string', 'max:191'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $user = $request->user();

        ContactMessage::create([
            ...$data,
            // المسجَّل يُعرَف من رمزه لا ممّا كتبه: الادّعاء سهل والرمز لا يُدّعى
            'user_id' => $user?->id,
            'tenant_id' => $user?->tenant_id,
            'ip_address' => $request->ip(),
        ]);

        app(TransactionalMail::class)->sendAddress(
            $data['email'],
            $data['name'],
            $user?->locale ?? app()->getLocale(),
            'contact',
            ['subject' => $data['subject']],
            '/',
        );

        return response()->json([
            'message' => __('وصلت رسالتك، وسنردّ عليك قريباً.'),
        ], 201);
    }

    /** صندوق الإدارة. */
    public function index(Request $request): JsonResponse
    {
        $messages = ContactMessage::query()
            ->with(['tenant', 'user'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $messages->getCollection()->map(fn (ContactMessage $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'phone' => $row->phone,
                'subject' => $row->subject,
                'message' => $row->message,
                'status' => $row->status,
                'sender' => $row->tenant?->name,
                'created_at' => $row->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    public function update(Request $request, ContactMessage $contactMessage): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:new,read,archived'],
        ]);

        $contactMessage->update($data);

        return response()->json(['message' => __('تم تحديث الرسالة.'), 'status' => $contactMessage->status]);
    }
}
