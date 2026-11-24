import { useState } from 'react'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import type { DisputeSummary } from '@/types'

const TONE: Record<string, string> = {
  resolved: 'success',
  closed: 'neutral',
  awaiting_party: 'warning',
  open: 'danger',
}

/**
 * النزاعات: الطرفان يريان نزاعاتهما، والمنصة ترى الكلّ لأنّها الوسيط.
 *
 * و«المتأخّر عن مهلته» شرطٌ على الصفحة لا عمودٌ يُصفّى — فهو مقارنةٌ
 * بالوقت الحاضر لا قيمةٌ في سطر — ولذلك بقي خارج صفّ التصفية.
 */
export function Disputes() {
  const { t, locale } = useI18n()
  const [overdueOnly, setOverdueOnly] = useState(false)
  const [isMediator, setIsMediator] = useState(false)

  const statuses = t.disputes.statuses as Record<string, string>
  const reasons = t.disputes.reasons as Record<string, string>
  const outcomes = t.disputes.outcomes as Record<string, string>

  const columns: DataTableColumn<DisputeSummary>[] = [
    {
      data: 'reference_no',
      title: t.returns.reference,
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        `<a class="dt-link font-medium dt-num" href="/returns/disputes/${row.id}" dir="ltr">${esc(row.reference_no)}</a>` +
        `<div class="muted text-xs mt-0.5" dir="auto">${esc(reasons[row.reason] ?? row.reason)}</div>`,
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
      className: 'text-sm',
      priority: 4,
      filter: { type: 'text' },
      render: (row) =>
        `<span dir="auto">${esc(row.buyer)}</span><div class="muted text-xs" dir="auto">${esc(row.supplier)}</div>`,
    },
    {
      data: 'claimed_amount',
      title: t.disputes.claimedAmount,
      className: 'dt-num font-medium',
      priority: 2,
      filter: { type: 'text' },
      render: (row) =>
        esc(formatAmount(row.claimed_amount, row.currency, 2, locale)) +
        (row.outcome
          ? `<div class="muted text-xs">${esc(outcomes[row.outcome] ?? row.outcome)}${
              row.resolution_amount > 0
                ? ` · ${esc(formatAmount(row.resolution_amount, row.currency, 2, locale))}`
                : ''
            }</div>`
          : ''),
    },
    {
      data: 'mediator',
      title: t.disputes.mediator,
      orderable: false,
      className: 'muted text-sm',
      priority: 5,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.mediator ?? '—')}</span>`,
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
        `<span class="dt-chip dt-chip-${TONE[row.status] ?? 'accent'}">${esc(statuses[row.status] ?? row.status)}</span>` +
        (row.is_overdue ? `<span class="dt-chip dt-chip-danger ms-1">${esc(t.disputes.overdue)}</span>` : ''),
    },
  ]

  return (
    <div className="space-y-4">
      {isMediator && <p className="muted text-sm">{t.disputes.mediatorView}</p>}

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={overdueOnly} onChange={(e) => setOverdueOnly(e.target.checked)} />
        {t.disputes.overdueOnly}
      </label>

      <DataTable<DisputeSummary>
        url="/disputes"
        exportName="disputes"
        columns={columns}
        order={['id', 'desc']}
        params={{ overdue: overdueOnly ? 1 : undefined }}
        emptyMessage={t.disputes.empty}
        onResponse={(payload) => setIsMediator(payload.is_mediator === true)}
      />
    </div>
  )
}
