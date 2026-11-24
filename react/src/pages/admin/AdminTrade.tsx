import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import type { AdminOrderRow, AdminQuotationRow } from '@/types'

const QUOTATION_STATUSES = ['draft', 'sent', 'countered', 'accepted', 'rejected', 'expired'] as const
const ORDER_STATUSES = [
  'draft',
  'awaiting_approval',
  'confirmed',
  'in_production',
  'shipped',
  'delivered',
  'closed',
  'cancelled',
] as const
const CORRECTABLE = ['in_production', 'shipped', 'delivered', 'closed', 'cancelled'] as const

/**
 * الصفقات من عين المنصة: عروض الأسعار وأوامر الشراء بين كل الأطراف.
 *
 * قراءةٌ لا تدخّل: العرض عقدٌ بين مستوردٍ ومشترٍ، وتعديله من خارجه
 * يُفسد سنده. فالمنصة ترى وتتابع — وإن وقع خلاف فبابه النزاعات، لا
 * تحرير الوثيقة من الخلف.
 *
 * وجدولان مستقلّان: لكلٍّ بحثه وفرزه وتصفّحه، فلا يجرّ أحدهما الآخر
 * كما كان البحث الواحد يفعل.
 */
export function AdminTrade() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  /*
   * تصحيح الحالة يمرّ بخدمة التنفيذ في الخادم لا بكتابةٍ على العمود:
   * الحالة تجرّ وراءها قيوداً ومخزوناً وإشعاراً.
   */
  const changeOrderStatus = useMutation({
    mutationFn: async ({ id, status }: { id: string; status: string }) =>
      (await api.post(`/admin/orders/${id}/status`, { status })).data,
    onSuccess: () => {
      setError(null)
      setReloadKey((key) => key + 1)
      queryClient.invalidateQueries({ queryKey: ['admin-summary'] })
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const pen = (signed: boolean, label: string) =>
    `<span class="${signed ? 'text-success' : 'muted opacity-40'}" title="${esc(label)}" aria-label="${esc(label)}">✎</span>`

  const quotationColumns: DataTableColumn<AdminQuotationRow>[] = [
    {
      data: 'quotation_no',
      title: t.accounting.entryNo,
      className: 'dt-ltr dt-num',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => esc(row.quotation_no),
    },
    {
      data: 'supplier',
      title: t.auth.asSupplier,
      orderable: false,
      priority: 3,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.supplier)}</span>`,
    },
    {
      data: 'buyer',
      title: t.admin.buyers,
      orderable: false,
      priority: 4,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.buyer)}</span>`,
    },
    {
      data: 'total',
      title: t.common.total,
      className: 'dt-num font-medium',
      priority: 2,
      filter: { type: 'text' },
      render: (row) => esc(formatAmount(row.total, row.currency, 2, locale)),
    },
    {
      data: 'signed',
      title: t.quotation.signatures,
      orderable: false,
      searchable: false,
      priority: 5,
      export: (row) =>
        [row.signed.supplier && t.auth.asSupplier, row.signed.buyer && t.admin.buyers]
          .filter(Boolean)
          .join(' · ') || '—',
      // قلمان: توقيع المستورد وتوقيع المشتري، والباهت ما لم يوقَّع بعد
      render: (row) =>
        `<span class="flex items-center gap-1.5">${pen(row.signed.supplier, t.quotation.supplierSignature)}${pen(row.signed.buyer, t.quotation.buyerSignature)}</span>`,
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 2,
      filter: {
        type: 'select',
        options: QUOTATION_STATUSES.map((value) => ({ value, label: t.quotation.statuses[value] })),
      },
      render: (row) =>
        `<span class="dt-chip ${row.status === 'accepted' ? 'dt-chip-success' : 'dt-chip-accent'}">${esc(
          t.quotation.statuses[row.status as keyof typeof t.quotation.statuses] ?? row.status,
        )}</span>`,
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 1,
      render: (row) => `<a class="dt-btn" href="/quotations/${row.id}">${esc(t.quotation.view)}</a>`,
    },
  ]

  const orderColumns: DataTableColumn<AdminOrderRow>[] = [
    {
      data: 'order_no',
      title: t.order.orderNo,
      className: 'dt-ltr dt-num',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => esc(row.order_no),
    },
    {
      data: 'supplier',
      title: t.auth.asSupplier,
      orderable: false,
      priority: 3,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.supplier)}</span>`,
    },
    {
      data: 'buyer',
      title: t.admin.buyers,
      orderable: false,
      priority: 4,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.buyer)}</span>`,
    },
    {
      data: 'total',
      title: t.common.total,
      className: 'dt-num font-medium',
      priority: 2,
      filter: { type: 'text' },
      render: (row) => esc(formatAmount(row.total, row.currency, 2, locale)),
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 2,
      filter: {
        type: 'select',
        options: ORDER_STATUSES.map((value) => ({ value, label: t.order.statuses[value] })),
      },
      render: (row) =>
        `<span class="dt-chip ${row.status === 'delivered' || row.status === 'closed' ? 'dt-chip-success' : 'dt-chip-accent'}">${esc(
          t.order.statuses[row.status as keyof typeof t.order.statuses] ?? row.status,
        )}</span>`,
    },
    {
      data: null,
      title: t.admin.orderStatus,
      orderable: false,
      searchable: false,
      priority: 1,
      // التصحيح لا السير: المراحل التي يملك المستورد نقلها إليها
      render: (row) =>
        `<select class="dt-cell-select" data-action="order-status" data-id="${row.id}" aria-label="${esc(t.admin.orderStatus)}: ${esc(row.order_no)}">` +
        `<option value="" selected disabled>${esc(t.common.edit)}</option>` +
        CORRECTABLE.filter((value) => value !== row.status)
          .map((value) => `<option value="${value}">${esc(t.order.statuses[value])}</option>`)
          .join('') +
        '</select>',
    },
  ]

  return (
    <div className="space-y-8">
      {error && <div className="card p-4 text-sm text-danger">{error}</div>}

      <section>
        <h2 className="mb-3 font-semibold">{t.admin.quotationsTitle}</h2>

        <DataTable<AdminQuotationRow>
          url="/admin/quotations"
          exportName="quotations"
          columns={quotationColumns}
          order={['id', 'desc']}
        />
      </section>

      <section>
        <h2 className="mb-3 font-semibold">{t.admin.ordersTitle}</h2>

        <DataTable<AdminOrderRow>
          url="/admin/orders"
          exportName="orders"
          columns={orderColumns}
          order={['id', 'desc']}
          reloadKey={reloadKey}
          onAction={(action, id, value) => {
            if (action === 'order-status' && value) changeOrderStatus.mutate({ id, status: value })
          }}
        />
      </section>
    </div>
  )
}
