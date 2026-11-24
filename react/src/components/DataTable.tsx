import { lazy, Suspense } from 'react'
import type { DataTableProps } from './DataTableControl'

export type { DataTableColumn, DataTableProps } from './DataTableControl'

/*
 * غلافٌ مؤجَّل: jQuery وDataTables نحو ١٢٠ كيلوبايت مضغوطة، ونصف
 * صفحات المنصة بلا جدول أصلاً. فالمكتبة تصل مع أوّل جدولٍ يُرسَم لا
 * مع أوّل صفحة — والصفحات تستورد الاسم نفسه ولا تعرف بالتأجيل.
 */
const Control = lazy(() =>
  import('./DataTableControl').then((m) => ({ default: m.DataTableControl })),
)

export function DataTable<T>(props: DataTableProps<T>) {
  return (
    <Suspense fallback={<div className="skeleton h-72 w-full rounded-xl" />}>
      {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
      <Control {...(props as any)} />
    </Suspense>
  )
}
