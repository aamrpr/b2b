import { useEffect, useRef, useState } from 'react'
import type { CSSProperties, ReactNode, ButtonHTMLAttributes, InputHTMLAttributes, SelectHTMLAttributes } from 'react'
import { AlertTriangle, ChevronLeft, ChevronRight, RotateCw, Search } from 'lucide-react'
import clsx from 'clsx'
import { Link } from 'react-router-dom'
import { errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'

export function Card({
  children,
  className,
  style,
}: {
  children: ReactNode
  className?: string
  style?: CSSProperties
}) {
  /*
   * clsx يضمّ الصفوف ولا يحسم تعارضها: 'p-5' و'p-0' كلاهما يصل إلى
   * السمة، والفائز يحدّده ترتيبهما في الورقة المولَّدة لا في النصّ —
   * وهو p-5. فكل بطاقة تطلب p-0 (الجداول، لوح المحادثة) كانت تحتفظ
   * بحشوة 20px خفيّة؛ على الهاتف دفعت لوح المحادثة خارج عرض الشاشة.
   */
  const hasOwnPadding = /(^|\s)p-(\d|\[)/.test(className ?? '')

  return (
    <div className={clsx('card', !hasOwnPadding && 'p-5', className)} style={style}>
      {children}
    </div>
  )
}

export function PageTitle({
  title,
  subtitle,
  action,
  back,
}: {
  title: string
  subtitle?: string
  action?: ReactNode
  /** مسار العودة: صفحات التفصيل كانت بلا طريقٍ إلى قائمتها إلّا زرّ المتصفّح */
  back?: string
}) {
  const { t, dir } = useI18n()
  const Back = dir === 'rtl' ? ChevronRight : ChevronLeft

  return (
    <div className="flex flex-wrap items-end justify-between gap-3 mb-6">
      <div className="min-w-0">
        {back && (
          <Link
            to={back}
            className="muted mb-1.5 inline-flex items-center gap-1 text-sm transition hover:text-accent"
          >
            <Back size={15} />
            {t.common.back}
          </Link>
        )}

        <h1 className="text-2xl font-bold">{title}</h1>
        {subtitle && <p className="muted text-sm mt-1">{subtitle}</p>}
      </div>
      {action}
    </div>
  )
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'ghost' | 'danger' | 'outline'
  size?: 'sm' | 'md'
}

export function Button({ variant = 'primary', size = 'md', className, ...props }: ButtonProps) {
  return (
    <button
      {...props}
      className={clsx(
        'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        /*
         * ارتفاعٌ أدنى لا حشوة وحدها: زرّ الأيقونة كان 14×14 بكسل في
         * بعض الجداول — يُخطئه الإصبع على الهاتف. والحدّ هنا 36 و44.
         */
        size === 'sm' ? 'min-h-9 min-w-9 px-3 py-1.5 text-sm' : 'min-h-11 px-4 py-2.5 text-sm',
        variant === 'primary' && 'bg-brand-700 text-white hover:bg-brand-800',
        variant === 'outline' && 'border border-[var(--border)] hover:bg-[var(--surface)]',
        variant === 'ghost' && 'hover:bg-[var(--surface)]',
        variant === 'danger' && 'bg-[var(--danger-solid)] text-white hover:opacity-90',
        className,
      )}
    />
  )
}

/**
 * زرّ حذف يطلب تأكيداً في موضعه.
 *
 * الحذف كان ينفَّذ بنقرة واحدة على أيقونة سلّة — وهي أقرب أيقونةٍ إلى
 * النقر بالخطأ. وبدل نافذةٍ تقطع العمل، يتسلّح الزرّ في مكانه: النقرة
 * الأولى تكشف «تأكيد الحذف»، والثانية تنفّذ.
 *
 * ويعود إلى حاله بعد أربع ثوانٍ، فزرُّ حذفٍ يبقى مسلّحاً فخٌّ لا حارس.
 */
export function ConfirmButton({
  onConfirm,
  label,
  confirmLabel,
  children,
  disabled = false,
}: {
  onConfirm: () => void
  /** اسم الإجراء لقارئ الشاشة، فالأيقونة وحدها لا تُقرأ */
  label: string
  confirmLabel: string
  children: ReactNode
  disabled?: boolean
}) {
  const [armed, setArmed] = useState(false)

  useEffect(() => {
    if (!armed) return

    const id = setTimeout(() => setArmed(false), 4000)

    return () => clearTimeout(id)
  }, [armed])

  if (armed) {
    return (
      <Button
        variant="danger"
        size="sm"
        disabled={disabled}
        onClick={() => {
          setArmed(false)
          onConfirm()
        }}
      >
        {confirmLabel}
      </Button>
    )
  }

  return (
    <Button variant="ghost" size="sm" aria-label={label} disabled={disabled} onClick={() => setArmed(true)}>
      {children}
    </Button>
  )
}

export function Field({
  label,
  hint,
  children,
  className,
}: {
  label: string
  hint?: string
  children: ReactNode
  className?: string
}) {
  return (
    <label className={clsx('block', className)}>
      <span className="block text-sm font-medium mb-1.5">{label}</span>
      {children}
      {hint && <span className="block text-xs muted mt-1">{hint}</span>}
    </label>
  )
}

/**
 * هيئة الحقول كلّها.
 *
 * بلا outline: الحقل يعلن تركيزه بإطارٍ ملوّن وحلقةٍ حوله — وهي أهدأ من
 * الخطّ المرسوم خارج الحدّ وأقرب إلى شكل الحقل نفسه. وتُصدَّر ليستعملها
 * ما يُركَّب من مكتبات خارجية (الهاتف، التاريخ، الاختيار).
 */
export const controlClass =
  'w-full rounded-lg border border-[var(--border)] bg-[var(--surface-raised)] px-3 py-2.5 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 transition'

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={clsx(controlClass, className)} />
}

/**
 * حقل بحث.
 *
 * كان البحث يحتاج Enter ولا يقول ذلك: لا زرّ ولا إشارة، فمن كتب وانتظر
 * ظنّ أنّ البحث معطّل. فصار له زرٌّ ظاهر، ويبحث أثناء الكتابة بتأخير
 * قصير — 400 مللي تكفي ليكمل الكلمة ولا تُرهق الخادم بكل حرف.
 */
export function SearchField({
  defaultValue = '',
  placeholder,
  onSearch,
  className,
}: {
  defaultValue?: string
  placeholder?: string
  onSearch: (term: string) => void
  className?: string
}) {
  const { t } = useI18n()
  const [value, setValue] = useState(defaultValue)

  // مرجعٌ للدالّة: هويتها تتبدّل مع كل رسم، ولا يصحّ أن يُعاد ضبط المؤقّت لذلك
  const latest = useRef(onSearch)
  latest.current = onSearch

  useEffect(() => {
    const id = setTimeout(() => latest.current(value.trim()), 400)

    return () => clearTimeout(id)
  }, [value])

  return (
    <form
      className={clsx('flex gap-2', className)}
      onSubmit={(event) => {
        event.preventDefault()
        latest.current(value.trim())
      }}
    >
      <Input value={value} placeholder={placeholder ?? t.common.search} onChange={(e) => setValue(e.target.value)} />

      <Button type="submit" variant="outline" size="sm" aria-label={t.common.search} className="shrink-0">
        <Search size={16} />
      </Button>
    </form>
  )
}

export function Textarea({ className, ...props }: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea {...props} className={clsx(controlClass, 'min-h-24', className)} />
}

export function Select({ className, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={clsx(controlClass, className)} />
}

export function Badge({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'brand' }) {
  return (
    <span
      className={clsx(
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
        tone === 'neutral' && 'bg-[var(--surface)] muted',
        tone === 'success' && 'bg-[var(--success-soft)] text-success',
        tone === 'warning' && 'bg-[var(--warning-soft)] text-warning',
        tone === 'danger' && 'bg-[var(--danger-soft)] text-danger',
        tone === 'brand' && 'bg-[var(--accent-soft)] text-accent',
      )}
    >
      {children}
    </span>
  )
}

/**
 * حالة الخطأ.
 *
 * كانت الصفحة تبقى على «جارٍ التحميل» أبداً حين يردّ الخادم 403 أو
 * تنقطع الشبكة: لا شيء يصل، ولا شيء يقول إنّ شيئاً لن يصل. وهنا يُقال
 * السبب بلغة المستخدم لا برقم الحالة، ومعه زرّ إعادة — فأكثر الأخطاء
 * عابرة، وإعادةُ المحاولة أقرب من إعادة التحميل.
 */
export function ErrorState({ error, onRetry }: { error?: unknown; onRetry?: () => void }) {
  const { t } = useI18n()
  const status = (error as { response?: { status?: number } })?.response?.status

  const reason =
    status === 403 ? t.common.forbidden
      : status === 404 ? t.common.notFound
        : status === undefined ? t.common.offline
          : errorMessage(error, t.common.error)

  return (
    <div className="card grid place-items-center gap-3 p-10 text-center">
      <span className="grid h-11 w-11 place-items-center rounded-full bg-[var(--danger-soft)] text-danger">
        <AlertTriangle size={20} />
      </span>

      <div>
        <div className="font-semibold">{t.common.errorTitle}</div>
        <p className="muted mt-1 text-sm">{reason}</p>
      </div>

      {/* لا زرّ إعادة لما لا تُجدي فيه الإعادة: الصلاحية لا تتبدّل بنقرة */}
      {onRetry && status !== 403 && status !== 404 && (
        <Button variant="outline" size="sm" onClick={() => onRetry()}>
          <RotateCw size={15} />
          {t.common.retry}
        </Button>
      )}
    </div>
  )
}

/**
 * حالة الفراغ.
 *
 * «لا توجد نتائج» وحدها تترك المستخدم واقفاً: لا تقول له ما الخطوة
 * التالية. فالدعوة إلى فعلٍ اختيارية هنا — «أضف أوّل منتج» لمن كتالوجه
 * فارغ، و«امسح البحث» لمن بحث فلم يجد.
 */
export function Empty({ message, action }: { message: string; action?: ReactNode }) {
  return (
    <div className="card grid place-items-center gap-3 p-10 text-center">
      <p className="muted text-sm">{message}</p>
      {action}
    </div>
  )
}

/**
 * هيكل تحميل.
 *
 * كان سطر «جارٍ التحميل» في وسط الفراغ: الصفحة تبقى خالية ثمّ تقفز
 * دفعةً واحدة حين تصل البيانات. والهيكل يحجز المساحة نفسها، فالقفزة
 * تختفي ويُفهم من شكله أنّ ما سيأتي قائمة.
 *
 * والنصّ يبقى لقارئ الشاشة في aria-label: الرمادي المتحرّك لا يُقرأ.
 */
export function Loading({ message }: { message: string }) {
  return (
    <div className="card space-y-3 p-5" role="status" aria-label={message}>
      <LoadingLabel message={message} />
      {[90, 70, 80, 60].map((width, index) => (
        <div key={index} className="skeleton h-4" style={{ width: `${width}%` }} />
      ))}
    </div>
  )
}

/** نص التحميل تتحرك نقاطه، والمساحة الرمادية تلمَع إلى أن يصل المحتوى. */
export function LoadingLabel({ message }: { message: string }) {
  return (
    <p className="text-sm font-medium">
      {message}
      <span className="loading-dots" aria-hidden="true">
        <span>.</span>
        <span>.</span>
        <span>.</span>
      </span>
    </p>
  )
}

export function Stat({ label, value, tone }: { label: string; value: string; tone?: 'success' | 'danger' }) {
  return (
    <div className="card p-4">
      <div className="text-xs muted mb-1">{label}</div>
      <div
        className={clsx(
          'text-xl font-bold tabular',
          tone === 'success' && 'text-success',
          tone === 'danger' && 'text-danger',
        )}
      >
        {value}
      </div>
    </div>
  )
}

/**
 * تنقّل بين صفحات القائمة.
 *
 * الخادم يُرجع `meta` في كل قائمة منذ البداية، والواجهة كانت تُهملها:
 * فمن له أكثر من خمسة وعشرين منتجاً لا يرى السادس والعشرين أبداً.
 *
 * ولا يظهر حين تكون الصفحة واحدة — زرّان معطّلان تحت كل جدول ضجيج.
 */
export function Pager({
  meta,
  page,
  onChange,
}: {
  meta?: { current_page: number; last_page: number; total: number }
  page: number
  onChange: (page: number) => void
}) {
  const { t, dir } = useI18n()

  if (!meta || meta.last_page <= 1) return null

  const Back = dir === 'rtl' ? ChevronRight : ChevronLeft
  const Next = dir === 'rtl' ? ChevronLeft : ChevronRight

  return (
    <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
      <span className="muted text-sm tabular">
        {meta.current_page} / {meta.last_page} · {meta.total}
      </span>

      <div className="flex gap-2">
        <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => onChange(page - 1)}>
          <Back size={15} />
          {t.common.previous}
        </Button>
        <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => onChange(page + 1)}>
          {t.common.next}
          <Next size={15} />
        </Button>
      </div>
    </div>
  )
}

