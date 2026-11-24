import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Bell, Check, CheckCircle2, FileSearch, MessageCircle, Package, Receipt } from 'lucide-react'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { useRealtime } from '@/store/realtime'
import type { Notification } from '@/types'

const ICONS: Record<string, typeof Bell> = {
  'file-search': FileSearch,
  receipt: Receipt,
  'check-circle': CheckCircle2,
  package: Package,
  'message-circle': MessageCircle,
  bell: Bell,
}

function relativeTime(iso: string | null, locale: string): string {
  if (!iso) return ''

  const diffMs = Date.now() - new Date(iso).getTime()
  const minutes = Math.round(diffMs / 60_000)
  const formatter = new Intl.RelativeTimeFormat(locale === 'ar' ? 'ar' : 'en', { numeric: 'auto' })

  if (Math.abs(minutes) < 60) return formatter.format(-minutes, 'minute')
  const hours = Math.round(minutes / 60)
  if (Math.abs(hours) < 24) return formatter.format(-hours, 'hour')
  return formatter.format(-Math.round(hours / 24), 'day')
}

export function NotificationBell() {
  const { t, locale, pick } = useI18n()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  const { notifications, unreadNotifications, loadNotifications, markRead, markAllRead } = useRealtime()

  useEffect(() => {
    if (open) void loadNotifications()
  }, [open, loadNotifications])

  // إغلاق القائمة عند النقر خارجها
  useEffect(() => {
    if (!open) return

    function onPointerDown(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }

    document.addEventListener('mousedown', onPointerDown)
    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [open])

  async function openNotification(notification: Notification) {
    if (!notification.read_at) {
      try {
        await markRead(notification.id)
      } catch {
        /* الإشعار قد يكون محذوفاً — نتابع للوجهة على أي حال */
      }
    }

    setOpen(false)

    if (notification.action_url) navigate(notification.action_url)
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="relative grid h-9 w-9 place-items-center rounded-lg hover:bg-[var(--surface)] transition"
        aria-label={t.notifications.title}
      >
        <Bell size={18} />
        {unreadNotifications > 0 && (
          <span className="absolute -top-0.5 -end-0.5 min-w-4.5 h-4.5 px-1 grid place-items-center rounded-full bg-red-600 text-white text-[10px] font-bold tabular">
            {unreadNotifications > 99 ? '99+' : unreadNotifications}
          </span>
        )}
      </button>

      {open && (
        <div
          className={clsx(
            'card fixed inset-x-3 z-50 overflow-hidden p-0 shadow-xl',
            'top-[calc(4rem+env(safe-area-inset-top)+0.5rem)]',
            // على الشاشات الأوسع تلتصق بالزرّ. في الجوال عرضها الشاشة بهامش،
            // في العربية والإنجليزية، فلا تنقطع من أحد الطرفين.
            'sm:absolute sm:inset-x-auto sm:end-0 sm:top-auto sm:mt-2 sm:w-[22rem]',
          )}
        >
          <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-[var(--border)] px-4 py-3">
            <span className="text-sm font-semibold">{t.notifications.title}</span>
            {unreadNotifications > 0 && (
              <button
                type="button"
                onClick={() => void markAllRead()}
                className="flex items-center gap-1 text-xs text-accent hover:underline"
              >
                <Check size={13} />
                {t.notifications.markAllRead}
              </button>
            )}
          </div>

          <div className="max-h-[min(24rem,calc(100dvh-9rem))] overflow-y-auto">
            {notifications.length === 0 ? (
              <p className="muted text-sm text-center py-10">{t.notifications.empty}</p>
            ) : (
              notifications.map((notification) => {
                const Icon = ICONS[notification.icon] ?? Bell

                return (
                  <button
                    key={notification.id}
                    type="button"
                    onClick={() => void openNotification(notification)}
                    className={clsx(
                      'w-full text-start flex gap-3 px-4 py-3 border-b border-[var(--border)] last:border-0 transition',
                      'hover:bg-[var(--surface)]',
                      !notification.read_at && 'bg-brand-500/5',
                    )}
                  >
                    <span
                      className={clsx(
                        'grid h-8 w-8 shrink-0 place-items-center rounded-lg',
                        notification.read_at ? 'bg-[var(--surface)] muted' : 'bg-brand-500/10 text-accent',
                      )}
                    >
                      <Icon size={15} />
                    </span>

                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-medium truncate">{pick(notification.title)}</span>
                      <span className="block text-xs muted line-clamp-2 mt-0.5">{pick(notification.body)}</span>
                      <span className="block text-[11px] muted mt-1 tabular">
                        {relativeTime(notification.created_at, locale)}
                      </span>
                    </span>

                    {!notification.read_at && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-500" />}
                  </button>
                )
              })
            )}
          </div>
        </div>
      )}
    </div>
  )
}
