import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import clsx from 'clsx'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Button } from '@/components/ui'

interface Banner {
  id: number
  eyebrow: string | null
  title: string
  text: string | null
  href: string
  image_url: string
}

const INTERVAL = 6500

/**
 * بنرات الدعاية تأتي من إدارة المنصة. التبديل يتوقّف تحت المؤشّر
 * وعند طلب تقليل الحركة.
 */
export function PromoBanners() {
  const { t, dir, locale } = useI18n()
  const [index, setIndex] = useState(0)
  const [paused, setPaused] = useState(false)
  const Prev = dir === 'rtl' ? ChevronRight : ChevronLeft
  const Next = dir === 'rtl' ? ChevronLeft : ChevronRight

  const { data, isLoading } = useQuery({
    queryKey: ['banners', locale],
    queryFn: async () => (await api.get<{ data: Banner[] }>('/banners')).data.data,
  })

  const slides = (data ?? []).filter(
    (banner) => banner.image_url && banner.href.startsWith('/') && !banner.href.startsWith('//'),
  )

  const count = slides.length
  const safeIndex = count === 0 ? 0 : index % count

  useEffect(() => {
    if (paused || count < 2) return
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

    const timer = window.setInterval(() => setIndex((current) => current + 1), INTERVAL)

    return () => window.clearInterval(timer)
  }, [paused, count])

  if (isLoading) {
    return <div className="skeleton h-64 w-full rounded-2xl md:h-80" role="status" aria-label={t.common.loading} />
  }

  if (count === 0) return null

  const current = slides[safeIndex]

  function step(delta: number) {
    setIndex((value) => (value + delta + count) % count)
  }

  return (
    <section
      className="relative h-64 overflow-hidden rounded-2xl bg-[var(--color-navy-900)] md:h-80"
      aria-roledescription="carousel"
      aria-label={t.home.banners}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      {slides.map((slide, slideIndex) => (
        <img
          key={slide.id}
          src={slide.image_url}
          alt=""
          className={clsx(
            'absolute inset-0 h-full w-full object-cover transition-opacity duration-700',
            slideIndex === safeIndex ? 'opacity-100' : 'opacity-0',
          )}
        />
      ))}
      <div className="banner-shade pointer-events-none absolute inset-0" />

      <div key={current.id} className="rise relative z-10 flex h-full max-w-xl flex-col justify-end p-6 md:p-10">
        {current.eyebrow && <p className="text-xs font-semibold tracking-wide text-white/80">{current.eyebrow}</p>}
        <h2 className="mt-2 max-w-md text-2xl font-bold leading-snug text-white md:text-4xl">{current.title}</h2>
        {current.text && <p className="mt-2 max-w-md text-sm text-white/85 md:text-base">{current.text}</p>}

        <div className="mt-4 flex flex-col items-start gap-3">
          {count > 1 && (
            <div className="flex w-full items-center justify-between gap-3">
              <div className="flex items-center gap-1.5" role="tablist" aria-label={t.home.banners}>
                {slides.map((slide, slideIndex) => (
                  <button
                    key={slide.id}
                    type="button"
                    role="tab"
                    aria-selected={slideIndex === safeIndex}
                    aria-label={slide.title}
                    className={clsx(
                      'relative h-1.5 overflow-hidden rounded-full transition-all',
                      slideIndex === safeIndex ? 'w-8 bg-white/35' : 'w-2.5 bg-white/45 hover:bg-white/70',
                    )}
                    onClick={() => setIndex(slideIndex)}
                  >
                    {slideIndex === safeIndex && <span key={safeIndex} className="banner-progress block rounded-full" />}
                  </button>
                ))}
              </div>
              <div className="flex gap-2">
                <button
                  type="button"
                  className="grid h-9 w-9 place-items-center rounded-full border border-white/40 text-white hover:bg-white/15"
                  aria-label={t.home.bannerPrev}
                  onClick={() => step(-1)}
                >
                  <Prev size={16} />
                </button>
                <button
                  type="button"
                  className="grid h-9 w-9 place-items-center rounded-full border border-white/40 text-white hover:bg-white/15"
                  aria-label={t.home.bannerNext}
                  onClick={() => step(1)}
                >
                  <Next size={16} />
                </button>
              </div>
            </div>
          )}

          <Link to={current.href}>
            <Button>
              {t.home.bannerCta}
              <Next size={16} />
            </Button>
          </Link>
        </div>
      </div>
    </section>
  )
}

/** أسماء الأصناف تمرّ كسطر دعائي، وتتوقّف تحت المؤشّر. */
export function CategoryTicker({ names }: { names?: string[] }) {
  const { t } = useI18n()
  const viewportRef = useRef<HTMLDivElement>(null)
  const groupRef = useRef<HTMLDivElement>(null)
  const [copies, setCopies] = useState(2)

  useLayoutEffect(() => {
    const viewport = viewportRef.current
    const group = groupRef.current
    if (!viewport || !group || !names?.length) return

    // النسخة التالية يجب أن تملأ ما يظهر، والإزاحة بعرض مجموعة واحدة
    // حتى يعود الشريط إلى حيث بدأ بلا قفزة ولا فراغ.
    const measure = () => {
      const shift = group.scrollWidth
      const view = viewport.clientWidth
      if (shift === 0) return

      const next = Math.max(2, Math.ceil(view / shift) + 1)
      setCopies((current) => (current === next ? current : next))
      viewport.style.setProperty('--ticker-shift', `${shift}px`)
      viewport.style.setProperty('--ticker-duration', `${Math.max(16, Math.round(shift / 48))}s`)
    }

    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(viewport)
    return () => observer.disconnect()
  }, [names])

  if (!names) {
    return <div className="skeleton h-10 rounded-none" aria-label={t.common.loading} />
  }

  if (names.length === 0) return null

  return (
    <div ref={viewportRef} className="ticker" aria-hidden="true">
      <div className="ticker-track">
        {Array.from({ length: copies }, (_, copy) => (
          <div key={copy} ref={copy === 0 ? groupRef : undefined} className="ticker-group" aria-hidden={copy > 0}>
            {names.map((name, index) => (
              <span key={`${name}-${index}`} className="ticker-item text-sm font-medium">
                {name}
                <span className="text-[var(--accent)]">·</span>
              </span>
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}
