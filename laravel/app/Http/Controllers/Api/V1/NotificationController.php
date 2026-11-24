<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Communication\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications();

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate(min((int) $request->query('per_page', 20), 50));

        return response()->json([
            'data' => $notifications->getCollection()->map(fn (DatabaseNotification $n) => $this->present($n)),
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['notification' => $this->present($notification->fresh())]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => __('تم تعليم كل الإشعارات كمقروءة.')]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return response()->json(['message' => __('تم حذف الإشعار.')]);
    }

    /** تفضيلات الإشعارات لكل حدث. */
    public function preferences(Request $request): JsonResponse
    {
        $events = ['rfq.broadcast', 'quotation.received', 'quotation.accepted', 'order.status', 'message.received'];

        $stored = NotificationPreference::where('user_id', $request->user()->id)
            ->get()
            ->keyBy('event');

        return response()->json([
            'preferences' => collect($events)->map(fn ($event) => [
                'event' => $event,
                'in_app' => $stored[$event]->in_app ?? true,
                'email' => $stored[$event]->email ?? true,
                'push' => $stored[$event]->push ?? true,
            ]),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:60'],
            'in_app' => ['nullable', 'boolean'],
            'email' => ['nullable', 'boolean'],
            'push' => ['nullable', 'boolean'],
        ]);

        $preference = NotificationPreference::updateOrCreate(
            ['user_id' => $request->user()->id, 'event' => $data['event']],
            array_intersect_key($data, array_flip(['in_app', 'email', 'push']))
        );

        return response()->json(['preference' => $preference]);
    }

    private function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'event' => $data['event'] ?? null,
            'icon' => $data['icon'] ?? 'bell',
            'title' => $data['title'] ?? ['ar' => '', 'en' => ''],
            'body' => $data['body'] ?? ['ar' => '', 'en' => ''],
            'action_url' => $data['action_url'] ?? null,
            'data' => collect($data)->except(['event', 'icon', 'title', 'body', 'action_url']),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
