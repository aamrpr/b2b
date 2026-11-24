import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatMoney } from '@/lib/money'
import { isSupplier, useAuth } from '@/store/auth'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { PageTitle } from '@/components/ui'
import type { Order } from '@/types'

const STATUSES = [
  'draft',
  'awaiting_approval',
  'confirmed',
  'in_production',
  'shipped',
  'delivered',
  'closed',
  'cancelled',
] as const

/**
 * أوامر الشراء — المسار واحد والطرفان يقرؤانه.
 *
 * والعمود الأخير يختلف بالدور لا الصفحة: المستورد يشحن ويسلّم، وتاجر
 * الجملة يفتح مرتجعاً على ما استلمه.
 */
export function Orders() {
  const { t, locale } = useI18n()
  const user = useAuth((s) => s.user)
  const queryClient = useQueryClient()
  const [reloadKey, setReloadKey] = useState(0)

  const updateStatus = useMutation({
    mutationFn: async (orderId: string) =>
      (await api.patch(`/orders/${orderId}/status`, { status: 'delivered' })).data,
    onSuccess: () => {
      setReloadKey((key) => key + 1)
      queryClient.invalidateQueries({ queryKey: ['journal'] })
      queryClient.invalidateQueries({ queryKey: ['trial-balance'] })
    },
  })

  const statuses = t.order.statuses as Record<string, string>
  const supplier = isSupplier(user)

  const columns: DataTableColumn<Order>[] = [
    {
      data: 'order_no',
      title: t.order.orderNo,
      className: 'dt-num dt-ltr font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => esc(row.order_no),
    },
    {
      data: 'counterpart',
      title: supplier ? t.auth.asBuyer : t.auth.asSupplier,
      orderable: false,
      priority: 3,
      filter: { type: 'text' },
      render: (row) =>
        `<span dir="auto">${esc((supplier ? row.buyer?.name : row.supplier?.name) ?? '—')}</span>`,
    },
    {
      data: 'total',
      title: t.common.total,
      className: 'dt-num font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => esc(formatMoney(row.total, locale)),
    },
    {
      data: 'commission',
      title: t.order.commission,
      orderable: false,
      className: 'dt-num muted',
      priority: 4,
      filter: { type: 'text' },
      render: (row) => esc(formatMoney(row.commission, locale)),
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 1,
      filter: {
        type: 'select',
        options: STATUSES.map((value) => ({ value, label: statuses[value] ?? value })),
      },
      render: (row) =>
        `<span class="dt-chip ${row.status === 'delivered' || row.status === 'closed' ? 'dt-chip-success' : 'dt-chip-accent'}">${esc(
          statuses[row.status] ?? row.status,
        )}</span>`,
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 2,
      render: (row) => {
        /* الشحن هو المسار الطبيعي: حالة الطلب تُشتق من شحناته.
           التسليم المباشر يبقى متاحاً للتسليم باليد بلا ناقل. */
        if (supplier && !['delivered', 'closed', 'cancelled'].includes(row.status)) {
          return (
            `<a class="dt-btn" href="/orders/${row.id}/ship">${esc(t.shipping.newShipment)}</a>` +
            `<button type="button" class="dt-btn" data-action="deliver" data-id="${row.id}">${esc(t.order.markDelivered)}</button>`
          )
        }

        // المرتجع يفتحه المشتري على طلب مُسلَّم فقط
        if (!supplier && ['delivered', 'closed'].includes(row.status)) {
          return `<a class="dt-btn" href="/orders/${row.id}/return">${esc(t.returns.newReturn)}</a>`
        }

        return ''
      },
    },
  ]

  return (
    <div>
      <PageTitle title={t.order.title} />

      <DataTable<Order>
        url="/orders"
        exportName="orders"
        columns={columns}
        order={['id', 'desc']}
        pageLength={20}
        reloadKey={reloadKey}
        onAction={(action, id) => {
          if (action === 'deliver') updateStatus.mutate(id)
        }}
      />
    </div>
  )
}
