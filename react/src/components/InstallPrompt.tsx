import { useEffect, useState } from 'react'
import { X } from 'lucide-react'
import { useI18n } from '@/i18n'
import { Button } from '@/components/ui'

const LATER_KEY = 'b2b.pwa-later'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

/**
 * دعوة التثبيت تظهر عندما يعرض المتصفح ذلك فقط،
 * وتختفي إن كان التطبيق مفتوحاً من الشاشة أو أُجِّلت في هذه الزيارة.
 */
export function InstallPrompt() {
  const { t } = useI18n()
  const [offer, setOffer] = useState<BeforeInstallPromptEvent | null>(null)
  const [hidden, setHidden] = useState(false)

  useEffect(() => {
    if (window.matchMedia('(display-mode: standalone)').matches) return

    try {
      if (sessionStorage.getItem(LATER_KEY) === '1') setHidden(true)
    } catch {
      /* التخزين محجوب — الدعوة تبقى ظاهرة */
    }

    const onOffer = (event: Event) => {
      event.preventDefault()
      setOffer(event as BeforeInstallPromptEvent)
    }
    const onInstalled = () => setOffer(null)

    window.addEventListener('beforeinstallprompt', onOffer)
    window.addEventListener('appinstalled', onInstalled)

    return () => {
      window.removeEventListener('beforeinstallprompt', onOffer)
      window.removeEventListener('appinstalled', onInstalled)
    }
  }, [])

  if (!offer || hidden) return null

  function later() {
    setHidden(true)
    try {
      sessionStorage.setItem(LATER_KEY, '1')
    } catch {
      /* الإخفاء يبقى لهذه الرسمة على الأقل */
    }
  }

  async function install() {
    await offer?.prompt()
    const choice = await offer?.userChoice
    if (choice?.outcome === 'accepted') setOffer(null)
    else later()
  }

  return (
    <div className="fixed inset-x-3 bottom-[calc(env(safe-area-inset-bottom)+0.75rem)] z-[60] mx-auto flex max-w-md items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3 shadow-[var(--shadow)] sm:inset-x-auto sm:end-4 sm:w-[22rem]">
      <img src="/brand/pwa-192.png" alt="" className="h-12 w-12 shrink-0 rounded-xl" />
      <div className="min-w-0 flex-1">
        <div className="text-sm font-semibold">{t.pwa.installTitle}</div>
        <p className="muted mt-0.5 text-xs">{t.pwa.installHint}</p>
        <div className="mt-2 flex gap-2">
          <Button size="sm" onClick={() => void install()}>
            {t.pwa.install}
          </Button>
          <Button size="sm" variant="ghost" onClick={later}>
            {t.pwa.later}
          </Button>
        </div>
      </div>
      <button
        type="button"
        onClick={later}
        aria-label={t.pwa.later}
        className="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-[var(--muted)] hover:bg-[var(--surface)] hover:text-[var(--text)]"
      >
        <X size={16} />
      </button>
    </div>
  )
}
