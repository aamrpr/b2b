import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { ErrorState, Loading } from '@/components/ui'
import type { Account } from '@/types'

const TONE: Record<string, string> = {
  asset: 'accent',
  liability: 'warning',
  equity: 'neutral',
  revenue: 'success',
  expense: 'danger',
}

/** دليل الحسابات: شجرةٌ كاملة تأتي دفعةً واحدة، فالفرز والبحث في المتصفّح. */
export function ChartOfAccounts() {
  const { t, locale } = useI18n()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['accounts'],
    queryFn: async () => (await api.get<{ currency: string; accounts: Account[] }>('/accounting/accounts')).data,
  })

  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (isLoading || !data) return <Loading message={t.common.loading} />

  const columns: DataTableColumn<Account>[] = [
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
      // الإزاحة تُظهر الشجرة: الحساب الورقة مزاحٌ، والأب غليظ
      render: (row) =>
        `<span class="${row.is_leaf ? 'ps-4' : 'font-semibold'}" dir="auto">${esc(row.name)}</span>`,
    },
    {
      data: 'type',
      title: t.common.status,
      priority: 2,
      filter: {
        type: 'select',
        options: Object.keys(TONE).map((value) => ({ value, label: value })),
      },
      render: (row) => `<span class="dt-chip dt-chip-${TONE[row.type] ?? 'neutral'} dt-ltr">${esc(row.type)}</span>`,
    },
    {
      data: 'balance',
      title: t.accounting.balance,
      className: 'dt-num',
      priority: 1,
      render: (row) =>
        esc(row.balance !== null ? formatAmount(row.balance, data.currency, 2, locale) : '—'),
    },
  ]

  return (
    <DataTable<Account>
      rows={data.accounts}
      exportName="chart-of-accounts"
      columns={columns}
      order={['code', 'asc']}
      pageLength={50}
    />
  )
}
