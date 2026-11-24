import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { Card, ErrorState, Loading, Stat } from '@/components/ui'
import type { IncomeStatement as IncomeStatementData, ReportLine } from '@/types'

export function IncomeStatement() {
  const { t, locale, pick } = useI18n()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['income-statement'],
    queryFn: async () => (await api.get<IncomeStatementData>('/accounting/reports/income-statement')).data,
  })

  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const money = (value: number) => formatAmount(value, data.currency, 2, locale)

  const Section = ({ title, lines, total }: { title: string; lines: ReportLine[]; total: number }) => (
    <Card>
      <div className="flex items-center justify-between mb-3">
        <h3 className="font-semibold">{title}</h3>
        <span className="font-bold tabular">{money(total)}</span>
      </div>
      <div className="space-y-1.5 text-sm">
        {lines.length === 0 && <p className="muted">—</p>}
        {lines.map((line) => (
          <div key={line.code} className="flex justify-between gap-3">
            <span className="muted">
              <span className="tabular">{line.code}</span> {pick(line.name)}
            </span>
            <span className="tabular">{money(line.amount)}</span>
          </div>
        ))}
      </div>
    </Card>
  )

  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-3">
        <Stat label={t.accounting.revenue} value={money(data.revenue.total)} />
        <Stat label={t.accounting.grossProfit} value={`${money(data.gross_profit)} (${data.gross_margin_pct}%)`} />
        <Stat
          label={t.accounting.netProfit}
          value={money(data.net_profit)}
          tone={data.net_profit >= 0 ? 'success' : 'danger'}
        />
      </div>

      <Section title={t.accounting.revenue} lines={data.revenue.lines} total={data.revenue.total} />
      <Section title={t.accounting.costOfSales} lines={data.cost_of_sales.lines} total={data.cost_of_sales.total} />
      <Section title={t.accounting.expenses} lines={data.operating_expenses.lines} total={data.operating_expenses.total} />
    </div>
  )
}
