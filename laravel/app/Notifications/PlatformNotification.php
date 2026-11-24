<?php

namespace App\Notifications;

use App\Models\Communication\NotificationPreference;
use App\Models\User;
use App\Services\Mail\TransactionalMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * أساس كل إشعارات المنصة.
 *
 * النصوص تُخزَّن باللغتين داخل الإشعار لا تُترجَم عند العرض،
 * لأن المستخدم قد يبدّل لغة الواجهة بعد وصول الإشعار بأيام.
 */
abstract class PlatformNotification extends Notification
{
    use Queueable;

    /** مفتاح الحدث — يُستخدم في تفضيلات الإشعارات وفي تصنيف الواجهة. */
    abstract public function event(): string;

    /** @return array{ar: string, en: string} */
    abstract public function title(): array;

    /** @return array{ar: string, en: string} */
    abstract public function body(): array;

    abstract public function actionUrl(): ?string;

    /** بيانات إضافية خاصة بكل نوع إشعار. */
    public function payload(): array
    {
        return [];
    }

    public function icon(): string
    {
        return 'bell';
    }

    /**
     * مفتاح القالب في لوحة السوبر أدمن. فارغ يعني أن الحدث لا يُرسَل بالبريد.
     */
    public function mailKey(): ?string
    {
        return null;
    }

    /** @return array<string, scalar|null> */
    public function mailVariables(User $user): array
    {
        return [];
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = [];

        $preference = $notifiable instanceof User
            ? NotificationPreference::where('user_id', $notifiable->id)->where('event', $this->event())->first()
            : null;

        // الافتراضي مفعَّل — التفضيل يُستخدم للتعطيل فقط
        if ($preference === null || $preference->in_app) {
            $channels[] = 'database';
            $channels[] = 'broadcast';
        }

        // البريد يقرره قالب السوبر أدمن، لا تفضيل الإشعار داخل التطبيق
        if ($notifiable instanceof User && $this->mailKey() !== null) {
            app(TransactionalMail::class)->send(
                $notifiable,
                $this->mailKey(),
                $this->mailVariables($notifiable),
                $this->actionUrl(),
            );
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event(),
            'icon' => $this->icon(),
            'title' => $this->title(),
            'body' => $this->body(),
            'action_url' => $this->actionUrl(),
            ...$this->payload(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'notification.created';
    }
}
