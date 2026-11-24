import { Outlet } from 'react-router-dom'
import { useI18n } from '@/i18n'
import { PageTitle } from '@/components/ui'

/**
 * إطار إدارة المنصة.
 *
 * كانت أبوابها تبويبات فوق كل صفحة، فانتقلت إلى فرعٍ منسدل في العمود
 * الجانبي: القائمة واحدة لكل المنصة لا قائمتان، والمحتوى يبدأ من أعلى
 * الصفحة لا من تحت صفٍّ من الروابط يتكرّر في كل باب.
 */
export function AdminLayout() {
  const { t } = useI18n()

  return (
    <div>
      <PageTitle title={t.admin.title} />
      <Outlet />
    </div>
  )
}
