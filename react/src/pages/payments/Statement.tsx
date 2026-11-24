import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { ErrorState, Loading, PageTitle, Stat } from '@/components/ui'
import type { CustomerStatement } from '@/types'

/** كشف حساب العميل برصيد جارٍ — الفواتير مدينة والمدفوعات دائنة. */
export function Statement() {
  const { customerId = '' } = useParams()
  const { t, locale } = useI18n()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['statement', customerId],
    queryFn: async () => (await api.get<CustomerStatement>(`/customers/${customerId}/statement`)).data,
  })

  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const money = (value: number) => formatAmount(value, data.currency, 2, locale)

  const entryLabel = (type: string) =>
    type === 'invoice'
      ? t.payments.invoiceEntry
      : type === 'credit_note'
        ? t.payments.creditEntry
        : t.payments.paymentEntry

  const columns: DataTableColumn<CustomerStatement['lines'][number]>[] = [
    {
      data: 'date',
      title: t.common.date,
      className: 'dt-num muted',
      priority: 2,
      render: (row) => esc(row.date),
    },
    {
      data: 'type',
      title: t.payments.entryType,
      priority: 1,
      render: (row) =>
        `<span class="dt-chip ${row.type === 'invoice' ? 'dt-chip-accent' : row.type === 'credit_note' ? 'dt-chip-warning' : 'dt-chip-success'}">${esc(entryLabel(row.type))}</span>`,
    },
    {
      data: 'reference',
      title: t.payments.reference,
      className: 'dt-num dt-ltr',
      priority: 3,
      render: (row) => esc(row.reference),
    },
    {
      data: 'debit',
      title: t.accounting.debit,
      className: 'dt-num',
      priority: 4,
      render: (row) => esc(row.debit ? money(row.debit) : '\u2014'),
    },
    {
      data: 'credit',
      title: t.accounting.credit,
      className: 'dt-num',
      priority: 4,
      render: (row) => esc(row.credit ? money(row.credit) : '\u2014'),
    },
    {
      data: 'balance',
      title: t.payments.runningBalance,
      className: 'dt-num font-medium',
      priority: 1,
      // الرصيد المدين أحمر: ما على العميل يُقرأ قبل أن يُحسَب
      render: (row) => `<span class="${row.balance > 0 ? 'text-danger' : ''}">${esc(money(row.balance))}</span>`,
    },
  ]

  return (
    <div className="space-y-5">
      <PageTitle title={t.payments.statement} subtitle={data.contact.name ?? undefined} />

      <div className="grid gap-3 sm:grid-cols-2">
        <Stat label={t.payments.runningBalance} value={money(data.balance)} />
        <Stat
          label={t.payments.outstanding}
          value={money(data.outstanding)}
          tone={data.outstanding > 0 ? 'danger' : 'success'}
        />
      </div>

      <DataTable<CustomerStatement['lines'][number]>
        rows={data.lines}
        exportName="statement"
        columns={columns}
        order={['date', 'asc']}
        paging={data.lines.length > 25}
        info={data.lines.length > 25}
      />
    </div>
  )
}
