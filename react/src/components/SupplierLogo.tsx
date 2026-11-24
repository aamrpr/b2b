import { useState } from 'react'
import clsx from 'clsx'

const SIZE = {
  xs: 'h-7 w-7 text-xs',
  sm: 'h-9 w-9 text-sm',
  md: 'h-14 w-14 text-lg',
  lg: 'h-16 w-16 text-xl',
} as const

/** صيغ قانونية تسبق الاسم ولا تميّزه: «شركة النور» حرفها ن لا ش */
const LEADING = new Set(['شركة', 'مؤسسة', 'مجموعة', 'بيت', 'دار'])

/** أول حرف من اسم المستورد، بعد إسقاط الصيغة القانونية وألف التعريف */
export function brandInitial(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return '؟'

  let word = words[0]
  if (words.length > 1 && LEADING.has(word)) word = words[1]

  word = word.replace(/^ال/, '').replace(/^Al-/i, '')

  return word.charAt(0) || '؟'
}

/**
 * شعار المستورد.
 *
 * الصورة إن رُفعت، وإلّا الحرف الأول من الاسم. والحرف يميّز المستوردين
 * التجريبيين الذين لم يرفعوا شعاراً، فلا يبقى مكان الشعار فارغاً.
 */
export function SupplierLogo({
  src,
  name,
  size = 'md',
  square = true,
  className,
}: {
  src?: string | null
  name: string
  size?: keyof typeof SIZE
  square?: boolean
  className?: string
}) {
  const [failedSrc, setFailedSrc] = useState<string | null>(null)
  const shape = square ? 'rounded-xl' : 'rounded-full'
  const box = clsx(SIZE[size], shape, 'shrink-0 border border-[var(--border)]', className)

  if (src && failedSrc !== src) {
    return (
      <img src={src} alt="" className={clsx(box, 'object-cover')} onError={() => setFailedSrc(src)} />
    )
  }

  return (
    <div className={clsx(box, 'grid place-items-center bg-[var(--surface)] font-bold text-accent')} aria-hidden>
      {brandInitial(name)}
    </div>
  )
}
