import { useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Input } from '@/components/ui'
import type { AdminProductRow } from '@/types'

const STATUSES = ['active', 'draft', 'out_of_stock', 'archived'] as const

/**
 * كتالوج المنصة كلّه.
 *
 * المستورد يملك منتجاته، لكن المنصة مسؤولة عمّا يُعرض فيها: منتجٌ مخالف
 * يُرفع من العرض هنا دون انتظار صاحبه. ولذلك يُطلب سببٌ قبل الحذف —
 * يُكتب في سجلّ التدقيق باسم فاعله، فالصلاحية بلا أثرٍ بابُ تعسّف.
 *
 * والبحث والتصفية والفرز والتصفّح كلّها في الجدول لا حوله: DataTables
 * يرسلها إلى الخادم ويقرأ ما يعود، فلا حالةٌ في الصفحة تُحاكي ما تملكه
 * المكتبة أصلاً.
 */
export function AdminProducts() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()

  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  /* السبب يُقرأ لحظة الإرسال لا لحظة بناء الجدول — والجدول يُبنى مرّة */
  const reasonRef = useRef('')
  reasonRef.current = reason

  function done() {
    setReason('')
    setError(null)
    setReloadKey((key) => key + 1)
    queryClient.invalidateQueries({ queryKey: ['admin-summary'] })
  }

  const changeStatus = useMutation({
    mutationFn: async ({ id, next }: { id: string; next: string }) =>
      (await api.patch(`/admin/products/${id}`, { status: next, reason: reasonRef.current || undefined })).data,
    onSuccess: done,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const remove = useMutation({
    mutationFn: async (id: string) =>
      (await api.delete(`/admin/products/${id}`, { data: { reason: reasonRef.current || undefined } })).data,
    onSuccess: done,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const columns: DataTableColumn<AdminProductRow>[] = [
    {
      data: 'name',
      title: t.rfq.itemDescription,
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        `<a class="dt-link font-medium" href="/products/${esc(row.slug)}" dir="auto">${esc(row.name)}</a>` +
        (row.category ? `<div class="muted mt-0.5 text-xs">${esc(row.category)}</div>` : ''),
    },
    {
      data: 'sku',
      title: t.products.sku,
      className: 'dt-ltr dt-num muted',
      filter: { type: 'text' },
      render: (row) => esc(row.sku ?? '—'),
    },
    {
      data: 'supplier',
      title: t.auth.asSupplier,
      orderable: false,
      priority: 3,
      filter: { type: 'text' },
      render: (row) => esc(row.supplier.name),
    },
    {
      data: 'price',
      title: t.common.unitPrice,
      className: 'dt-num',
      priority: 2,
      filter: { type: 'text' },
      render: (row) => esc(formatAmount(row.price, row.currency, 2, locale)),
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 1,
      // الخليّة قائمةٌ لا نصّ: نصّها المنزوع يجمع الخيارات الأربعة
      export: (row) => t.products.statuses[row.status as keyof typeof t.products.statuses] ?? row.status,
      filter: {
        type: 'select',
        options: STATUSES.map((value) => ({ value, label: t.products.statuses[value] })),
      },
      render: (row) =>
        `<select class="dt-cell-select" data-action="status" data-id="${row.id}" aria-label="${esc(t.common.status)}: ${esc(row.name)}">` +
        STATUSES.map(
          (value) =>
            `<option value="${value}"${value === row.status ? ' selected' : ''}>${esc(t.products.statuses[value])}</option>`,
        ).join('') +
        '</select>',
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 1,
      render: (row) =>
        `<button type="button" class="dt-btn dt-btn-danger" data-action="delete" data-id="${row.id}" data-confirm="${esc(t.admin.confirmDelete)}">${esc(t.common.delete)}</button>`,
    },
  ]

  return (
    <div className="space-y-4">
      {/* السبب يرافق الإجراء لا يسبقه: يُكتب مرّة ويُرسل مع أوّل تغيير */}
      <Input
        className="max-w-xs"
        placeholder={t.admin.reason}
        value={reason}
        onChange={(event) => setReason(event.target.value)}
      />

      {error && <div className="card p-4 text-sm text-danger">{error}</div>}

      <DataTable<AdminProductRow>
        url="/admin/products"
        exportName="products"
        columns={columns}
        order={['id', 'desc']}
        reloadKey={reloadKey}
        onAction={(action, id, value) => {
          if (action === 'status') changeStatus.mutate({ id, next: value })
          if (action === 'delete') remove.mutate(id)
        }}
      />
    </div>
  )
}
