import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import type { ShipmentSummary } from '@/types'

const TONE: Record<string, string> = {
  delivered: 'success',
  failed: 'danger',
  returned: 'danger',
  cancelled: 'neutral',
  draft: 'neutral',
}

/** شحناتك: الصادرة إن كنت مستورداً، والواردة إن كنت تاجر جملة. */
export function Shipments() {
  const { t, locale } = useI18n()

  const statuses = t.shipping.statuses as Record<string, string>

  const columns: DataTableColumn<ShipmentSummary>[] = [
    {
      data: 'shipment_no',
      title: t.shipping.shipmentNo,
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        `<a class="dt-link font-medium dt-num" href="/shipping/${row.id}" dir="ltr">${esc(row.shipment_no)}</a>` +
        `<div class="muted text-xs mt-0.5 dt-num">${esc(row.packages_count)} · ${esc(row.weight_kg ?? '—')} kg</div>`,
    },
    {
      data: 'order',
      title: t.order.orderNo,
      orderable: false,
      className: 'dt-num dt-ltr muted',
      priority: 3,
      filter: { type: 'text' },
      render: (row) => esc(row.order.order_no),
    },
    {
      data: 'carrier',
      title: t.shipping.carrier,
      orderable: false,
      priority: 4,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.carrier ?? '—')}</span>`,
    },
    {
      data: 'tracking_number',
      title: t.shipping.trackingNumber,
      orderable: false,
      className: 'dt-num dt-ltr muted',
      priority: 5,
      filter: { type: 'text' },
      render: (row) => esc(row.tracking_number ?? '—'),
    },
    {
      data: 'shipping_cost',
      title: t.shipping.shippingCost,
      orderable: false,
      className: 'dt-num',
      priority: 4,
      filter: { type: 'text' },
      render: (row) => esc(formatAmount(row.shipping_cost, row.currency, 2, locale)),
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 1,
      filter: {
        type: 'select',
        options: Object.entries(statuses).map(([value, label]) => ({ value, label })),
      },
      render: (row) =>
        `<span class="dt-chip dt-chip-${TONE[row.status] ?? 'accent'}">${esc(statuses[row.status] ?? row.status)}</span>`,
    },
  ]

  return (
    <DataTable<ShipmentSummary>
      url="/shipments"
      exportName="shipments"
      columns={columns}
      order={['id', 'desc']}
    />
  )
}
