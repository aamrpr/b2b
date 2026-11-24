import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import { ChevronDown, type LucideIcon } from 'lucide-react'
import clsx from 'clsx'

/**
 * قائمة منسدلة في شريط التنقّل.
 *
 * تُغلق بالنقر خارجها وبمفتاح Escape — وبمفتاح Escape يعود التركيز إلى
 * الزرّ، وإلّا ضاع مكان المتنقّل بلوحة المفاتيح في أوّل الصفحة.
 * وتُغلق أيضاً عند تبدّل المسار، لأنّ الانتقال عبر رابط داخلها لا يُعيد
 * بناء الشريط فتبقى مفتوحة فوق الصفحة الجديدة.
 */
export function NavMenu({
  label,
  icon: Icon,
  active = false,
  align = 'start',
  ariaLabel,
  children,
}: {
  label: ReactNode
  icon?: LucideIcon
  /** مضيء حين يكون المسار الحالي داخل هذه المجموعة */
  active?: boolean
  align?: 'start' | 'end'
  ariaLabel?: string
  children: ReactNode
}) {
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const buttonRef = useRef<HTMLButtonElement>(null)
  const { pathname } = useLocation()
  const panelId = useId()

  useEffect(() => setOpen(false), [pathname])

  useEffect(() => {
    if (!open) return

    function onPointerDown(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }

    function onKeyDown(event: KeyboardEvent) {
      if (event.key !== 'Escape') return
      setOpen(false)
      buttonRef.current?.focus()
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  return (
    <div ref={containerRef} className="relative">
      <button
        ref={buttonRef}
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-haspopup="true"
        aria-controls={open ? panelId : undefined}
        aria-label={ariaLabel}
        className={clsx('nav-link', (active || open) && 'nav-link-active')}
      >
        {Icon && <Icon size={16} className="shrink-0" />}
        {label}
        <ChevronDown size={14} className={clsx('shrink-0 transition', open && 'rotate-180')} />
      </button>

      {open && (
        <div
          id={panelId}
          // كلّ ما في اللوح روابط، فالنقر في أيّ موضع منه يعني مغادرة القائمة
          onClick={() => setOpen(false)}
          className={clsx(
            'absolute top-full z-30 mt-2 min-w-56 overflow-hidden rounded-xl border border-[var(--border)]',
            'bg-[var(--surface-raised)] p-1.5 shadow-lg shadow-black/10',
            align === 'end' ? 'end-0' : 'start-0',
          )}
        >
          {children}
        </div>
      )}
    </div>
  )
}

/**
 * فرعٌ ينطوي في العمود الجانبي.
 *
 * إدارة المنصة أحد عشر باباً: وضعُها تبويباتٍ فوق الصفحة يسرق سطراً من
 * المحتوى في كل صفحة، ووضعُها كلّها في العمود يُغرق بقية الأقسام. فتُطوى
 * تحت عنوانها وتُفتح عند الحاجة — وتنفتح وحدها إن كان المستخدم داخلها،
 * فلا يبحث عن الباب الذي يقف فيه.
 *
 * وحين يُطوى العمود إلى أيقوناته لا متّسع لفرعٍ ينفتح، فيصير العنوان
 * رابطاً إلى أوّل أبوابه.
 */
export function NavBranch({
  label,
  icon: Icon,
  items,
  collapsed = false,
}: {
  label: string
  icon: LucideIcon
  items: { to: string; label: string; icon: LucideIcon; end?: boolean }[]
  collapsed?: boolean
}) {
  const { pathname } = useLocation()
  const inside = items.some((item) => pathname === item.to || pathname.startsWith(item.to + '/'))
  const [open, setOpen] = useState(inside)

  useEffect(() => {
    if (inside) setOpen(true)
  }, [inside])

  if (collapsed) {
    return <NavMenuItem to={items[0].to} label={label} icon={Icon} collapsed />
  }

  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className={clsx(
          'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition',
          inside ? 'text-accent font-medium' : 'hover:bg-[var(--surface)]',
        )}
      >
        <Icon size={16} className="shrink-0" />
        <span className="flex-1 text-start">{label}</span>
        <ChevronDown size={14} className={clsx('shrink-0 transition', open && 'rotate-180')} />
      </button>

      {open && (
        /* خطٌّ على حافة البدء يربط الأبناء بعنوانهم، ويتبع اتجاه اللغة */
        <div className="ms-4 mt-0.5 grid gap-0.5 border-s border-[var(--border)] ps-2">
          {items.map((item) => (
            <NavMenuItem key={item.to} to={item.to} label={item.label} icon={item.icon} end={item.end} />
          ))}
        </div>
      )}
    </div>
  )
}

/** عنصر داخل قائمة منسدلة أو داخل العمود الجانبي */
export function NavMenuItem({
  to,
  label,
  icon: Icon,
  end,
  badge,
  collapsed = false,
}: {
  to: string
  label: string
  icon: LucideIcon
  end?: boolean
  badge?: number
  /** في العمود المطوي: أيقونة وحدها، والاسم في تلميح الفأرة */
  collapsed?: boolean
}) {
  return (
    <NavLink
      to={to}
      end={end}
      title={collapsed ? label : undefined}
      aria-label={collapsed ? label : undefined}
      className={({ isActive }) =>
        clsx(
          'relative flex items-center rounded-lg py-2 text-sm transition',
          collapsed ? 'justify-center px-2' : 'gap-2.5 px-3',
          isActive ? 'bg-[var(--accent-soft)] text-accent font-medium' : 'hover:bg-[var(--surface)]',
        )
      }
    >
      <Icon size={16} className="shrink-0" />

      {collapsed ? (
        // لا متّسع لرقم، والنقطة تكفي لقول «هنا جديد»
        Boolean(badge) && (
          <span className="absolute end-1.5 top-1.5 h-2 w-2 rounded-full bg-brand-700" aria-hidden />
        )
      ) : (
        <>
          <span className="flex-1">{label}</span>
          {Boolean(badge) && (
            <span className="inline-grid h-4.5 min-w-4.5 place-items-center rounded-full bg-brand-700 px-1 text-[10px] font-bold text-white tabular">
              {badge}
            </span>
          )}
        </>
      )}
    </NavLink>
  )
}

/** فاصل بعنوان بين مجموعتين داخل القائمة نفسها */
export function NavMenuGroup({ label }: { label: string }) {
  return <div className="px-3 pt-2.5 pb-1 text-[11px] font-semibold uppercase tracking-wide muted">{label}</div>
}
