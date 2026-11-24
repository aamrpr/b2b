import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import type { ReturnSummary } from '@/types'

const TONE: Record<string, string> = {
  completed: 'success',
  received: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
  requested: 'warning',
}

/** مرتجعاتك: ما طلبتَه إن كنت مشترياً، وما طُلب منك إن كنت مستورداً. */
export function Returns() {
  const { t, locale } = useI18n()

  const statuses = t.returns.statuses as Record<string, string>
  const reasons = t.returns.reasons as Record<string, string>

  const columns: DataTableColumn<ReturnSummary>[] = [
    {
      data: 'reference_no',
      title: t.returns.reference,
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        `<a class="dt-link font-medium dt-num" href="/returns/${row.id}" dir="ltr">${esc(row.reference_no)}</a>` +
        `<div class="muted text-xs mt-0.5 dt-num">${esc(row.items_count)} · ${esc(row.is_mine ? t.auth.asBuyer : t.auth.asSupplier)}</div>`,
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
      data: 'counterpart',
      title: t.returns.counterpart,
      orderable: false,
      priority: 4,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.counterpart)}</span>`,
    },
    {
      data: 'reason',
      title: t.returns.reason,
      className: 'muted text-sm',
      priority: 5,
      filter: {
        type: 'select',
        options: Object.entries(reasons).map(([value, label]) => ({ value, label })),
      },
      render: (row) => esc(reasons[row.reason] ?? row.reason),
    },
    {
      data: 'requested_amount',
      title: t.returns.requestedAmount,
      orderable: false,
      className: 'dt-num font-medium',
      priority: 2,
      filter: { type: 'text' },
      render: (row) =>
        esc(formatAmount(row.requested_amount, row.currency, 2, locale)) +
        // المعتمَد يُذكر وحده حين يخالف المطلوب: اعتمادٌ جزئيّ لا يُقرأ من رقمٍ واحد
        (row.approved_amount > 0 && row.approved_amount !== row.requested_amount
          ? `<div class="muted text-xs dt-num">${esc(t.returns.approved)}: ${esc(formatAmount(row.approved_amount, row.currency, 2, locale))}</div>`
          : ''),
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
    <DataTable<ReturnSummary>
      url="/returns"
      exportName="returns"
      columns={columns}
      order={['id', 'desc']}
      emptyMessage={t.returns.empty}
    />
  )
}
