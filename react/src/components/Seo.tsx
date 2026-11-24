import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { useI18n } from '@/i18n'
import { apiOrigin } from '@/lib/api'
import { applySeo, type SeoDocument } from '@/lib/seo'

/** يجلب بيانات الصفحة الحالية حتى يتطابق العنوان والوصف مع ما يُشارك. */
export function Seo() {
  const { pathname, search } = useLocation()
  const { locale } = useI18n()

  useEffect(() => {
    const controller = new AbortController()
    const path = `${pathname}${search}`

    void fetch(`${apiOrigin}/seo.json?path=${encodeURIComponent(path)}`, {
      headers: { Accept: 'application/json', 'X-Locale': locale },
      signal: controller.signal,
    })
      .then(async (response) => {
        if (!response.ok) return
        applySeo((await response.json()) as SeoDocument)
      })
      .catch(() => {
        /* انقطاع الشبكة لا يُسقط الصفحة */
      })

    return () => controller.abort()
  }, [pathname, search, locale])

  return null
}
