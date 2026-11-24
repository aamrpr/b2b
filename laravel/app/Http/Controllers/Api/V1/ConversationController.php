<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ConversationTyping;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Communication\Conversation;
use App\Models\Orders\Order;
use App\Models\Rfq\Rfq;
use App\Models\Tenancy\Tenant;
use App\Services\Communication\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with(['participants.tenant', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(30);

        // المستأجر المشاهِد يُمرَّر صراحةً: المحادثة تُعرض من منظوره
        // (الطرف الآخر، وعدد غير المقروء له هو)
        return response()->json([
            'data' => $conversations->getCollection()
                ->map(fn (Conversation $conversation) => (new ConversationResource($conversation, $tenantId))->toArray($request))
                ->values(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /** فتح محادثة مع طرف — من صفحة المستورد أو من داخل طلب/عرض. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'context_type' => ['nullable', 'in:rfq,order'],
            'context_id' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        $me = $request->user()->tenant;
        $other = Tenant::findOrFail($data['tenant_id']);

        abort_if($other->id === $me->id, 422, __('لا يمكن بدء محادثة مع نفس الجهة.'));

        $context = match ($data['context_type'] ?? null) {
            'rfq' => Rfq::find($data['context_id'] ?? 0),
            'order' => Order::find($data['context_id'] ?? 0),
            default => null,
        };

        $conversation = $this->service->between($me, $other, $context, $data['subject'] ?? null);

        if (! empty($data['body'])) {
            $this->service->send($conversation, $request->user(), ['body' => $data['body']]);
        }

        $conversation->load(['participants.tenant', 'latestMessage']);

        return response()->json([
            'conversation' => (new ConversationResource($conversation, $me->id))->toArray($request),
        ], 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);

        $conversation->load(['participants.tenant', 'latestMessage']);

        return response()->json([
            'conversation' => (new ConversationResource($conversation, $request->user()->tenant_id))->toArray($request),
        ]);
    }

    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorizeAccess($request, $conversation);

        $conversation->loadMissing('participants');
        $request->attributes->set(
            'counterpart_read_up_to',
            (int) $conversation->participants
                ->first(fn ($participant) => $participant->tenant_id !== $request->user()->tenant_id)
                ?->last_read_message_id,
        );

        $messages = $conversation->messages()
            ->with(['sender', 'senderTenant'])
            ->orderByDesc('id')
            ->paginate(50);

        $this->service->markRead($conversation, $request->user()->tenant_id);

        return MessageResource::collection($messages);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);

        $data = $request->validate([
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:5000'],
            'kind' => ['nullable', 'in:image,audio,video,file'],
            // السقف الأعلى للفيديو. الحد الأدق يُفحص بعد معرفة النوع.
            'attachment' => ['nullable', 'file', 'max:51200'],
            // لقطة يصنعها المتصفح قبل الرفع: إطار الفيديو، أو الصفحة الأولى، أو نسخة مصغّرة من الصورة.
            'thumbnail' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $file = $request->file('attachment');
        $stored = $file ? $this->storeAttachment($file, $data['kind'] ?? null) : null;

        $message = $this->service->send($conversation, $request->user(), [
            'body' => $data['body'] ?? null,
            'type' => $stored['type'] ?? 'text',
            'attachment_path' => $stored['path'] ?? null,
            'attachment_name' => $stored['name'] ?? null,
            'attachment_mime' => $stored['mime'] ?? null,
            'thumbnail_path' => $stored ? $this->storeThumbnail($request->file('thumbnail'), $stored) : null,
        ]);

        return response()->json([
            'message' => new MessageResource($message->load('sender', 'senderTenant')),
        ], 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);

        $this->service->markRead($conversation, $request->user()->tenant_id);

        return response()->json(['ok' => true]);
    }

    /** يبثّ أن المستخدم يكتب أو توقّف. لا يُحفظ شيء. */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeAccess($request, $conversation);

        $data = $request->validate([
            'typing' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        broadcast(new ConversationTyping(
            $conversation,
            (int) $user->tenant_id,
            $user->name,
            (bool) $data['typing'],
        ));

        return response()->json(['ok' => true]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'conversations' => $this->service->unreadConversationCount($request->user()->tenant_id),
        ]);
    }

    private function authorizeAccess(Request $request, Conversation $conversation): void
    {
        $conversation->loadMissing('participants');

        abort_unless($conversation->includes($request->user()->tenant_id), 403);
    }

    /**
     * يحفظ المرفق باسم عشوائي، ويصنّفه صورة أو صوتاً أو فيديو أو ملفاً.
     * الاسم الأصلي للعرض فقط، ولا يُستخدم مساراً على القرص.
     *
     * @return array{type: string, path: string, name: string, mime: string}
     */
    private function storeAttachment(UploadedFile $file, ?string $hint): array
    {
        $kind = $this->classify($file, $hint);
        $kilobytes = (int) ceil($file->getSize() / 1024);

        if ($kilobytes > $this->maxKilobytes($kind)) {
            throw ValidationException::withMessages([
                'attachment' => __('حجم الملف أكبر من المسموح.'),
            ]);
        }

        return [
            'type' => $kind,
            'path' => $file->store('messages', 'public'),
            'name' => $this->originalName($file),
            'mime' => $this->storedMime($file, $kind),
        ];
    }

    /**
     * الصورة المصغّرة اختيارية، وتُقبل مع الصورة والفيديو وPDF فقط.
     *
     * @param  array{type: string, path: string, name: string, mime: string}  $stored
     */
    private function storeThumbnail(?UploadedFile $thumbnail, array $stored): ?string
    {
        if (! $thumbnail) {
            return null;
        }

        $visual = in_array($stored['type'], ['image', 'video'], true) || $stored['mime'] === 'application/pdf';

        if (! $visual) {
            return null;
        }

        $mime = strtolower((string) ($thumbnail->getMimeType() ?: ''));

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                'thumbnail' => __('الصورة المصغّرة يجب أن تكون صورة.'),
            ]);
        }

        return $thumbnail->store('messages/thumbs', 'public');
    }

    private function classify(UploadedFile $file, ?string $hint): string
    {
        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');

        if ($hint !== null && $this->matches($hint, $mime, $extension)) {
            return $hint;
        }

        if ($hint !== null) {
            throw ValidationException::withMessages([
                'attachment' => __('نوع الملف غير مسموح.'),
            ]);
        }

        foreach (['image', 'video', 'audio', 'file'] as $kind) {
            if ($kind === 'audio' && $mime === 'video/webm') {
                continue;
            }

            if ($this->matches($kind, $mime, $extension)) {
                return $kind;
            }
        }

        throw ValidationException::withMessages([
            'attachment' => __('نوع الملف غير مسموح. المسموح: صورة، صوت، فيديو، أو PDF.'),
        ]);
    }

    private function matches(string $kind, string $mime, string $extension): bool
    {
        $mimes = [
            'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'audio' => ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/aac', 'audio/x-m4a', 'video/webm'],
            'video' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/ogg'],
            'file' => [
                'application/pdf',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'text/csv',
                'text/plain',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
        ];

        $extensions = [
            'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            'audio' => ['webm', 'ogg', 'mp3', 'm4a', 'wav', 'aac'],
            'video' => ['mp4', 'webm', 'mov', 'ogg'],
            'file' => ['pdf', 'xlsx', 'xls', 'csv', 'docx'],
        ];

        if (! in_array($extension, $extensions[$kind], true)) {
            return false;
        }

        if ($kind === 'file' && $extension === 'pdf') {
            return in_array($mime, ['application/pdf', 'application/octet-stream', ''], true);
        }

        if (in_array($mime, $mimes[$kind], true)) {
            return true;
        }

        // متصفّح التسجيل قد يغلّف الصوت في حاوية webm يقرأها النظام كفيديو.
        if ($kind === 'audio' && $extension === 'webm' && in_array($mime, ['video/webm', 'application/octet-stream'], true)) {
            return true;
        }

        return $mime === '' || $mime === 'application/octet-stream';
    }

    private function maxKilobytes(string $kind): int
    {
        return match ($kind) {
            'image' => 8192,
            'audio' => 15360,
            'video' => 51200,
            default => 15360,
        };
    }

    private function storedMime(UploadedFile $file, string $kind): string
    {
        $mime = strtolower((string) ($file->getMimeType() ?: ''));

        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        return match ($kind) {
            'image' => str_starts_with($mime, 'image/') ? $mime : 'image/jpeg',
            'audio' => str_starts_with($mime, 'audio/') ? $mime : 'audio/webm',
            'video' => str_starts_with($mime, 'video/') ? $mime : 'video/mp4',
            default => match (true) {
                $mime === 'application/pdf' || $extension === 'pdf' => 'application/pdf',
                $mime !== '' && $mime !== 'application/octet-stream' => $mime,
                $extension === 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $extension === 'xls' => 'application/vnd.ms-excel',
                $extension === 'csv' => 'text/csv',
                $extension === 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                default => 'application/octet-stream',
            },
        };
    }

    private function originalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');

        if ($name === '' || $name === '.' || $name === '..') {
            $extension = $file->extension() ?: 'bin';
            $name = 'file.'.$extension;
        }

        return mb_substr($name, 0, 180);
    }
}
