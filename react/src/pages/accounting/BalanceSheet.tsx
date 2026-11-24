import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { Badge, Card, ErrorState, Loading } from '@/components/ui'
import type { BalanceSheet as BalanceSheetData, ReportLine } from '@/types'

export function BalanceSheet() {
  const { t, locale, pick } = useI18n()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['balance-sheet'],
    queryFn: async () => (await api.get<BalanceSheetData>('/accounting/reports/balance-sheet')).data,
  })

  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const money = (value: number) => formatAmount(value, data.currency, 2, locale)

  const Block = ({ title, lines, total, extra }: { title: string; lines: ReportLine[]; total: number; extra?: [string, number] }) => (
    <Card>
      <div className="flex items-center justify-between mb-3">
        <h3 className="font-semibold">{title}</h3>
        <span className="font-bold tabular">{money(total)}</span>
      </div>
      <div className="space-y-1.5 text-sm">
        {lines.map((line) => (
          <div key={line.code} className="flex justify-between gap-3">
            <span className="muted">
              <span className="tabular">{line.code}</span> {pick(line.name)}
            </span>
            <span className="tabular">{money(line.amount)}</span>
          </div>
        ))}
        {extra && (
          <div className="flex justify-between gap-3 pt-1.5 border-t border-[var(--border)]">
            <span className="muted">{extra[0]}</span>
            <span className="tabular">{money(extra[1])}</span>
          </div>
        )}
      </div>
    </Card>
  )

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3 text-sm muted tabular">
        <span>
          {t.accounting.asOf} {data.as_of}
        </span>
        <Badge tone={data.check.is_balanced ? 'success' : 'danger'}>
          {data.check.is_balanced ? t.accounting.balanced : t.accounting.notBalanced}
        </Badge>
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <Block title={t.accounting.assets} lines={data.assets.lines} total={data.assets.total} />
        <div className="space-y-5">
          <Block title={t.accounting.liabilities} lines={data.liabilities.lines} total={data.liabilities.total} />
          <Block
            title={t.accounting.equity}
            lines={data.equity.lines}
            total={data.equity.total}
            extra={[t.accounting.periodResult, data.equity.current_period_result]}
          />
        </div>
      </div>
    </div>
  )
}
