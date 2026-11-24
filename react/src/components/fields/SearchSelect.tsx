import { lazy, Suspense } from 'react'
import type { ComponentProps } from 'react'
import type { SearchSelectControl } from './SearchSelectControl'

export type { Option } from './SearchSelectControl'

/*
 * غلافٌ مؤجَّل: react-select نحو 45 كيلوبايت مضغوطة، وأكثر الصفحات لا
 * تعرض قائمة بحثٍ أصلاً. فالمكتبة تصل مع أوّل قائمةٍ تُرسَم لا مع أوّل
 * صفحة — والصفحات تستورد الاسم نفسه ولا تعرف بالتأجيل.
 */
const Control = lazy(() =>
  import('./SearchSelectControl').then((m) => ({ default: m.SearchSelectControl })),
)

export function SearchSelect(props: ComponentProps<typeof SearchSelectControl>) {
  return (
    /* بديلٌ بقياس الحقل كي لا يقفز النموذج ريثما تصل المكتبة */
    <Suspense fallback={<div className="skeleton h-10 w-full" />}>
      <Control {...props} />
    </Suspense>
  )
}
