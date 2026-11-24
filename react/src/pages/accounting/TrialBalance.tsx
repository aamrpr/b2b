import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Badge, ErrorState, Loading, Stat } from '@/components/ui'
import type { TrialBalance as TrialBalanceData } from '@/types'

type Row = TrialBalanceData['accounts'][number]

/** ميزان المراجعة: مجموع المدين يساوي مجموع الدائن، وإلّا فثمّة خلل. */
export function TrialBalance() {
  const { t, locale, pick } = useI18n()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['trial-balance'],
    queryFn: async () => (await api.get<TrialBalanceData>('/accounting/reports/trial-balance')).data,
  })

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!data) return <Loading message={t.common.loading} />

  const money = (value: number) => formatAmount(value, data.currency, 2, locale)

  const columns: DataTableColumn<Row>[] = [
    {
      data: 'code',
      title: t.accounting.code,
      className: 'dt-num dt-ltr muted',
      priority: 1,
      render: (row) => esc(row.code),
    },
    {
      data: 'name',
      title: t.accounting.account,
      priority: 1,
      render: (row) => `<span dir="auto">${esc(pick(row.name))}</span>`,
    },
    {
      data: 'debit',
      title: t.accounting.debit,
      className: 'dt-num',
      priority: 2,
      render: (row) => esc(row.debit ? money(row.debit) : '—'),
    },
    {
      data: 'credit',
      title: t.accounting.credit,
      className: 'dt-num',
      priority: 2,
      render: (row) => esc(row.credit ? money(row.credit) : '—'),
    },
    {
      data: 'balance',
      title: t.accounting.balance,
      className: 'dt-num font-medium',
      priority: 1,
      render: (row) => esc(money(row.balance)),
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3 text-sm muted tabular">
        <span>
          {t.accounting.from} {data.from} {t.accounting.to} {data.to}
        </span>
        <Badge tone={data.totals.is_balanced ? 'success' : 'danger'}>
          {data.totals.is_balanced ? t.accounting.balanced : t.accounting.notBalanced}
        </Badge>
      </div>

      {/*
        * المجاميع فوق الجدول لا في ذيله: الجدول يُفرَز ويُصفَّح، وسطرُ
        * مجموعٍ يسبح بين صفحاته يُقرأ على أنّه مجموع ما ظهر منها.
        */}
      <div className="grid gap-3 sm:grid-cols-3">
        <Stat label={t.accounting.debit} value={money(data.totals.debit)} />
        <Stat label={t.accounting.credit} value={money(data.totals.credit)} />
        <Stat
          label={t.accounting.balance}
          value={money(data.totals.difference)}
          tone={data.totals.is_balanced ? 'success' : 'danger'}
        />
      </div>

      <DataTable<Row>
        rows={data.accounts}
        exportName="trial-balance"
        columns={columns}
        order={['code', 'asc']}
        pageLength={50}
        emptyMessage={t.accounting.noEntries}
      />
    </div>
  )
}
