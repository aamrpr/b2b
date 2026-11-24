import { Link, useLocation } from 'react-router-dom'
import { Compass } from 'lucide-react'
import { useI18n } from '@/i18n'
import { Button, Card } from '@/components/ui'

/**
 * مسار لا وجود له.
 *
 * كان كل رابطٍ خاطئ يُعاد إلى الرئيسية صامتاً، فمن أخطأ حرفاً في العنوان
 * — أو فتح رابطاً قديماً — يظنّ أنّه فقد مكانه ولا يدري أنّ المكان نفسه
 * لم يكن. والصفحة تقول ذلك، وتعرض الرابط الذي حاول فتحه ليراجعه.
 */
export function NotFound() {
  const { t } = useI18n()
  const { pathname } = useLocation()

  return (
    <Card className="mx-auto max-w-lg py-12 text-center">
      <span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-[var(--surface)] text-accent">
        <Compass size={26} />
      </span>

      <h1 className="mt-5 text-xl font-bold">{t.common.notFoundTitle}</h1>
      <p className="muted mt-2 text-sm">{t.common.notFoundBody}</p>

      <p className="muted mt-3 break-all text-xs" dir="ltr">
        {pathname}
      </p>

      <div className="mt-6 flex flex-wrap justify-center gap-3">
        <Link to="/">
          <Button>{t.nav.home}</Button>
        </Link>
        <Link to="/products">
          <Button variant="outline">{t.nav.products}</Button>
        </Link>
      </div>
    </Card>
  )
}
