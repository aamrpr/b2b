import { useI18n } from '@/i18n'
import { formatMoney } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { PageTitle } from '@/components/ui'
import type { Quotation } from '@/types'

const STATUSES = ['draft', 'sent', 'countered', 'accepted', 'rejected', 'expired'] as const

/** عروض المستورد كلّها، بحالاتها — ومنها تُفتح وثيقة كلّ عرض. */
export function MyQuotations() {
  const { t, locale } = useI18n()

  const tone = (status: string) =>
    status === 'accepted'
      ? 'success'
      : status === 'rejected'
        ? 'danger'
        : status === 'expired'
          ? 'neutral'
          : 'accent'

  const columns: DataTableColumn<Quotation>[] = [
    {
      data: 'quotation_no',
      title: t.accounting.entryNo,
      className: 'dt-num dt-ltr',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => esc(row.quotation_no),
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
      data: 'lead_time_days',
      title: t.quotation.leadTime,
      className: 'dt-num',
      priority: 4,
      filter: { type: 'text' },
      render: (row) => `${esc(row.lead_time_days)} ${esc(t.quotation.days)}`,
    },
    {
      data: 'valid_until',
      title: t.quotation.validUntil,
      className: 'dt-num muted',
      priority: 3,
      filter: { type: 'dates' },
      render: (row) => esc(row.valid_until?.slice(0, 10) ?? '—'),
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 1,
      filter: {
        type: 'select',
        options: STATUSES.map((value) => ({ value, label: t.quotation.statuses[value] })),
      },
      render: (row) =>
        `<span class="dt-chip dt-chip-${tone(row.status)}">${esc(
          t.quotation.statuses[row.status as keyof typeof t.quotation.statuses] ?? row.status,
        )}</span>`,
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 2,
      render: (row) => `<a class="dt-btn" href="/quotations/${row.id}">${esc(t.quotation.view)}</a>`,
    },
  ]

  return (
    <div>
      <PageTitle title={t.nav.myQuotations} />

      <DataTable<Quotation>
        url="/my/quotations"
        exportName="my-quotations"
        columns={columns}
        order={['id', 'desc']}
        pageLength={20}
      />
    </div>
  )
}
