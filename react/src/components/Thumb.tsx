import { useState } from 'react'
import clsx from 'clsx'

/**
 * صورة بسقوط رشيق.
 *
 * الغياب هنا هو الحالة الشائعة لا الاستثناء — أغلب المستوردين يبدؤون
 * بكتالوج بلا صور — فالبديل شعار المنصة على سطح محايد، لا مربع مكسور.
 */
export function Thumb({
  src,
  alt,
  className,
  rounded = 'rounded-xl',
  markClassName = 'h-12 w-12',
}: {
  src?: string | null
  alt?: string
  className?: string
  rounded?: string
  /** حجم الشعار البديل — يصغر في البطاقات الصغيرة */
  markClassName?: string
}) {
  const [failed, setFailed] = useState(false)

  if (!src || failed) {
    return (
      <div className={clsx('thumb-fallback grid place-items-center', rounded, className)}>
        <img
          src="/brand/mark.png"
          alt={alt ?? ''}
          className={clsx(markClassName, 'object-contain opacity-30')}
          loading="lazy"
        />
      </div>
    )
  }

  return (
    <img
      src={src}
      alt={alt ?? ''}
      loading="lazy"
      onError={() => setFailed(true)}
      className={clsx('object-cover', rounded, className)}
    />
  )
}
