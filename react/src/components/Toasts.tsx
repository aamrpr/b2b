import { CheckCircle2, TriangleAlert, X } from 'lucide-react'
import clsx from 'clsx'
import { useToast } from '@/store/toast'

/**
 * رفّ الإشعارات.
 *
 * يُركَّب مرّة في الإطار فيخدم التطبيق كلّه. وموضعه أسفل الشاشة في
 * الوسط: أعلى اليمين موضع القائمة والإشعارات الدائمة، والأسفل أقرب
 * إلى الإبهام على الهاتف وأبعد عن تغطية ما يقرأه المستخدم.
 */
export function Toasts() {
  const toasts = useToast((s) => s.toasts)
  const dismiss = useToast((s) => s.dismiss)

  if (!toasts.length) return null

  return (
    <div
      className="pointer-events-none fixed inset-x-0 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-[60] flex flex-col items-center gap-2 px-4 lg:bottom-4"
      // الإشعار خبرٌ لا تنبيه عاجل: polite لا يقطع قارئ الشاشة في منتصف جملته
      role="status"
      aria-live="polite"
    >
      {toasts.map((item) => (
        <div
          key={item.id}
          className={clsx(
            'pointer-events-auto flex max-w-md items-center gap-2.5 rounded-xl border px-4 py-3 text-sm shadow-lg',
            item.tone === 'danger'
              ? 'border-[var(--danger)] bg-[var(--danger-soft)] text-danger'
              : 'border-[var(--border)] bg-[var(--surface-raised)] text-[var(--text)]',
          )}
        >
          {item.tone === 'danger' ? (
            <TriangleAlert size={16} className="shrink-0" />
          ) : (
            <CheckCircle2 size={16} className="shrink-0 text-success" />
          )}

          <span className="flex-1">{item.message}</span>

          <button
            type="button"
            onClick={() => dismiss(item.id)}
            aria-label="×"
            className="muted shrink-0 transition hover:text-[var(--text)]"
          >
            <X size={14} />
          </button>
        </div>
      ))}
    </div>
  )
}
