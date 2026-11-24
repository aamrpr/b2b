import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { DayPicker } from 'react-day-picker'
import { ar, enUS } from 'react-day-picker/locale'
import { CalendarDays } from 'lucide-react'
import 'react-day-picker/style.css'
import { useI18n, type Locale } from '@/i18n'
import { controlClass } from '@/components/ui'

/** YYYY-MM-DD بالتوقيت المحليّ: toISOString يزيحه يوماً كاملاً شرق غرينتش */
function toIso(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

/** تحليل اليوم محليّاً. new Date('YYYY-MM-DD') يقرؤه غرينتش فيرجع لليوم السابق غربها */
function fromIso(value: string): Date | undefined {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return undefined

  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
}

/**
 * زرٌّ يفتح تقويم react-day-picker.
 *
 * `<input type="date">` يترك الصيغة للمتصفّح ونظام التشغيل: يظهر
 * بالميلادي هنا وبالهجري هناك. فالتقويم هنا واحدٌ للجميع، يتبع لغة
 * الواجهة واتجاهها، والقيمة YYYY-MM-DD دائماً.
 *
 * القائمة تُرسَم على document.body: حقلٌ داخل جدولٍ يتمرّر أفقياً
 * أو نافذةٍ تُمرَّر كان يقصّ التقويم لو بقي ابناً للحقل.
 */
export function DatePicker({
  locale,
  dir,
  value,
  onChange,
  placeholder = 'YYYY-MM-DD',
  clearLabel,
  ariaLabel,
  id,
  compact = false,
}: {
  locale: Locale
  dir: 'rtl' | 'ltr'
  value: string
  onChange: (value: string) => void
  placeholder?: string
  clearLabel: string
  ariaLabel?: string
  id?: string
  compact?: boolean
}) {
  const [open, setOpen] = useState(false)
  const [coords, setCoords] = useState<{ top: number; left: number } | null>(null)
  const wrapRef = useRef<HTMLDivElement>(null)
  const popRef = useRef<HTMLDivElement>(null)
  const selected = fromIso(value)

  useLayoutEffect(() => {
    if (!open) return

    const place = () => {
      const anchor = wrapRef.current
      const pop = popRef.current
      if (!anchor || !pop) return

      const rect = anchor.getBoundingClientRect()
      const margin = 8
      const width = pop.offsetWidth
      const height = pop.offsetHeight
      let top = rect.bottom + 4

      if (top + height > window.innerHeight - margin && rect.top - height - 4 > margin) {
        top = rect.top - height - 4
      }

      let left = dir === 'rtl' ? rect.right - width : rect.left
      left = Math.min(Math.max(margin, left), window.innerWidth - width - margin)

      setCoords((current) =>
        current && Math.abs(current.top - top) < 1 && Math.abs(current.left - left) < 1
          ? current
          : { top, left },
      )
    }

    place()

    const observer = new ResizeObserver(place)
    if (popRef.current) observer.observe(popRef.current)

    window.addEventListener('scroll', place, true)
    window.addEventListener('resize', place)

    return () => {
      observer.disconnect()
      window.removeEventListener('scroll', place, true)
      window.removeEventListener('resize', place)
    }
  }, [open, dir])

  useEffect(() => {
    if (!open) return

    function onPointerDown(event: MouseEvent) {
      const target = event.target as Node
      if (wrapRef.current?.contains(target) || popRef.current?.contains(target)) return
      setOpen(false)
    }

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  return (
    <div ref={wrapRef} className={compact ? 'dt-date-trigger' : `${controlClass} flex items-center gap-2`}>
      <button
        type="button"
        id={id}
        onClick={() => setOpen((current) => !current)}
        aria-expanded={open}
        aria-label={ariaLabel}
        className="flex min-w-0 flex-1 items-center justify-between gap-2 border-0 bg-transparent p-0 text-start"
      >
        <span className={value ? 'tabular' : 'muted'} dir="ltr">
          {value || placeholder}
        </span>
        <CalendarDays size={16} className="muted shrink-0" />
      </button>

      {value && (
        <button
          type="button"
          aria-label={clearLabel}
          onClick={() => {
            onChange('')
            setOpen(false)
          }}
          className="muted shrink-0 border-0 bg-transparent p-0 text-base leading-none"
        >
          ×
        </button>
      )}

      {open &&
        createPortal(
          <div
            ref={popRef}
            className="date-popover"
            style={{
              top: coords?.top ?? 0,
              left: coords?.left ?? 0,
              visibility: coords ? 'visible' : 'hidden',
            }}
          >
            <DayPicker
              mode="single"
              dir={dir}
              locale={locale === 'ar' ? ar : enUS}
              selected={selected}
              defaultMonth={selected}
              onSelect={(date) => {
                onChange(date ? toIso(date) : '')
                setOpen(false)
              }}
            />
          </div>,
          document.body,
        )}
    </div>
  )
}

/**
 * حقل تاريخ في نموذج. القيمة تُرسَل في حقلٍ مخفيّ لأن النماذج تُقرأ بـFormData.
 */
export function DateField({
  name,
  defaultValue = '',
  required = false,
  id,
}: {
  name: string
  defaultValue?: string
  required?: boolean
  id?: string
}) {
  const { locale, dir, t } = useI18n()
  const [value, setValue] = useState(defaultValue)

  return (
    <div>
      <DatePicker
        locale={locale}
        dir={dir}
        value={value}
        onChange={setValue}
        clearLabel={t.common.clear}
        id={id}
      />
      <input type="hidden" name={name} value={value} required={required} readOnly />
    </div>
  )
}

/**
 * طرفا مدى التاريخ في صفّ تصفية الجدول. القيمة المرسلة «من|إلى».
 * يُركَّب في جذر React مستقلّ لأن DataTables تملك صفّ التصفية.
 */
export function DateRangeFilter({
  locale,
  dir,
  title,
  fromLabel,
  toLabel,
  clearLabel,
  onChange,
}: {
  locale: Locale
  dir: 'rtl' | 'ltr'
  title: string
  fromLabel: string
  toLabel: string
  clearLabel: string
  onChange: (value: string) => void
}) {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const publish = (nextFrom: string, nextTo: string) => {
    onChange(!nextFrom && !nextTo ? '' : `${nextFrom}|${nextTo}`)
  }

  return (
    <>
      <DatePicker
        compact
        locale={locale}
        dir={dir}
        value={from}
        placeholder={fromLabel}
        clearLabel={clearLabel}
        ariaLabel={`${title}: ${fromLabel}`}
        onChange={(next) => {
          setFrom(next)
          publish(next, to)
        }}
      />
      <DatePicker
        compact
        locale={locale}
        dir={dir}
        value={to}
        placeholder={toLabel}
        clearLabel={clearLabel}
        ariaLabel={`${title}: ${toLabel}`}
        onChange={(next) => {
          setTo(next)
          publish(from, next)
        }}
      />
    </>
  )
}
