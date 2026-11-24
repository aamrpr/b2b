import { useEffect, useState } from 'react'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { useAuth } from '@/store/auth'
import { useTheme } from '@/store/theme'

const HOLD_MS = 1200
const LEAVE_MS = 820

function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/** يبقى الشعار حتى تُعرف الجلسة، فلا تومض صفحة الزائر قبل لوحة التحكم. */
export function useBootSplash(): 'in' | 'out' | 'done' {
  const loading = useAuth((s) => s.loading)
  const [held, setHeld] = useState(() => prefersReducedMotion())
  const [phase, setPhase] = useState<'in' | 'out' | 'done'>('in')

  useEffect(() => {
    if (held) return
    const timer = window.setTimeout(() => setHeld(true), HOLD_MS)
    return () => window.clearTimeout(timer)
  }, [held])

  useEffect(() => {
    if (loading || !held || phase !== 'in') return
    setPhase(prefersReducedMotion() ? 'done' : 'out')
  }, [loading, held, phase])

  useEffect(() => {
    if (phase !== 'out') return
    const timer = window.setTimeout(() => setPhase('done'), LEAVE_MS)
    return () => window.clearTimeout(timer)
  }, [phase])

  useEffect(() => {
    if (phase === 'done') return
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = previous
    }
  }, [phase])

  return phase
}

export function BootSplash({ leaving }: { leaving: boolean }) {
  const { t } = useI18n()
  const isDark = useTheme((s) => s.resolved()) === 'dark'

  return (
    <div className={clsx('boot', leaving && 'boot-leave')} role="status" aria-live="polite" aria-label={t.common.loading}>
      <div className="boot-stage">
        <div className="boot-orbit">
          <svg className="boot-arc" viewBox="0 0 120 120" aria-hidden="true">
            <circle className="boot-track" cx="60" cy="60" r="52" pathLength={100} />
            <circle className="boot-draw" cx="60" cy="60" r="52" pathLength={100} />
          </svg>
          <img
            src={isDark ? '/brand/mark-dark.png' : '/brand/mark.png'}
            alt=""
            className="boot-logo"
            width={256}
            height={256}
          />
        </div>
        <div className="boot-tag">{t.app.tagline}</div>
      </div>
    </div>
  )
}
