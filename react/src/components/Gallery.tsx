import { useEffect, useRef, useState } from 'react'
import { ChevronLeft, ChevronRight, X, ZoomIn } from 'lucide-react'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { Thumb } from './Thumb'

/**
 * معرض صور المنتج.
 *
 * الصورة الكبيرة تملأ العين والمصغّرات تحتها، وهو الترتيب الذي تعوّده
 * المشتري في كل متجر. والتكبير على نقرة لأنّ تاجر الجملة يشتري بالصندوق
 * لا بالقطعة: يريد أن يرى اللحام والطبعة قبل أن يطلب خمسين منها.
 *
 * وحين لا صورة — وهي حال أغلب الكتالوجات في بدايتها — يسقط إلى شعار
 * المنصة على سطح محايد عبر Thumb، لا إلى مربّع مكسور.
 */
export function Gallery({ images, alt }: { images?: { id: number; url: string; thumb_url?: string | null }[]; alt: string }) {
  const { t, dir } = useI18n()
  const [active, setActive] = useState(0)
  const [zoomed, setZoomed] = useState(false)
  const swipeX = useRef<number | null>(null)
  const swiped = useRef(false)

  const list = images ?? []
  const current = list[active]
  const first = list[0]?.url
  const PrevIcon = dir === 'rtl' ? ChevronRight : ChevronLeft
  const NextIcon = dir === 'rtl' ? ChevronLeft : ChevronRight

  function move(delta: number) {
    setActive((value) => {
      if (list.length < 2) return value
      return (value + delta + list.length) % list.length
    })
  }

  // منتج جديد بصور جديدة: العودة إلى أولاها وإلّا بقي المؤشّر خارج القائمة
  useEffect(() => setActive(0), [first])

  useEffect(() => {
    if (!zoomed) return

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setZoomed(false)
      if (list.length < 2) return
      if (event.key === 'ArrowRight') move(dir === 'rtl' ? -1 : 1)
      if (event.key === 'ArrowLeft') move(dir === 'rtl' ? 1 : -1)
    }

    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.body.style.overflow = previousOverflow
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [zoomed, dir, list.length])

  return (
    <div>
      <button
        type="button"
        onClick={() => current && setZoomed(true)}
        aria-label={current ? t.products.viewImage : undefined}
        // بلا صورة لا تكبير، والزرّ يبقى ليثبت القياس فلا يقفز العمود
        disabled={!current}
        className="group relative block w-full overflow-hidden rounded-xl border border-[var(--border)]"
      >
        <Thumb
          src={current?.url}
          alt={alt}
          rounded="rounded-none"
          markClassName="h-20 w-20"
          className="aspect-[4/3] w-full"
        />

        {current && (
          <span className="absolute bottom-3 end-3 grid h-9 w-9 place-items-center rounded-lg bg-black/45 text-white opacity-0 transition group-hover:opacity-100">
            <ZoomIn size={17} />
          </span>
        )}
      </button>

      {list.length > 1 && (
        <div className="mt-3 flex gap-2 overflow-x-auto pb-1">
          {list.map((image, index) => (
            <button
              key={image.id}
              type="button"
              onClick={() => setActive(index)}
              aria-label={`${t.products.imageOf} ${index + 1}`}
              aria-current={index === active}
              className={clsx(
                'shrink-0 overflow-hidden rounded-lg border-2 transition',
                index === active ? 'border-brand-700' : 'border-transparent hover:border-[var(--border)]',
              )}
            >
              <img src={image.thumb_url || image.url} alt="" loading="lazy" className="h-16 w-20 object-cover" />
            </button>
          ))}
        </div>
      )}

      {zoomed && current && (
        <div
          className="fixed inset-0 z-50 grid place-items-center bg-black/80 p-4"
          onClick={() => {
            if (swiped.current) {
              swiped.current = false
              return
            }
            setZoomed(false)
          }}
          onPointerDown={(event) => {
            if ((event.target as HTMLElement).closest('button')) return
            swipeX.current = event.clientX
          }}
          onPointerUp={(event) => {
            if (swipeX.current == null || list.length < 2) return
            const delta = event.clientX - swipeX.current
            swipeX.current = null
            if (Math.abs(delta) < 48) return
            swiped.current = true
            move(dir === 'rtl' ? (delta > 0 ? 1 : -1) : delta < 0 ? 1 : -1)
          }}
          role="dialog"
          aria-modal="true"
          aria-label={alt}
        >
          <img
            src={current.url}
            alt={alt}
            className="max-h-[82vh] max-w-full rounded-lg object-contain"
            onClick={(event) => event.stopPropagation()}
          />

          {list.length > 1 && (
            <>
              <button
                type="button"
                onClick={(event) => {
                  event.stopPropagation()
                  move(-1)
                }}
                aria-label={t.products.prevImage}
                className="absolute start-3 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white hover:bg-white/20"
              >
                <PrevIcon size={22} />
              </button>
              <button
                type="button"
                onClick={(event) => {
                  event.stopPropagation()
                  move(1)
                }}
                aria-label={t.products.nextImage}
                className="absolute end-3 top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white hover:bg-white/20"
              >
                <NextIcon size={22} />
              </button>
              <div dir="ltr" className="pointer-events-none absolute bottom-4 text-sm text-white/85 tabular">
                {active + 1} / {list.length}
              </div>
            </>
          )}

          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation()
              setZoomed(false)
            }}
            aria-label={t.products.closeImage}
            className="absolute end-4 top-4 grid h-10 w-10 place-items-center rounded-lg bg-white/10 text-white hover:bg-white/20"
          >
            <X size={20} />
          </button>
        </div>
      )}
    </div>
  )
}
