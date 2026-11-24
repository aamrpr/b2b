import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { Badge, Card, Empty, ErrorState, Loading, Pager } from '@/components/ui'
import type { JournalEntry, Paged } from '@/types'

export function Journal() {
  const { t, locale } = useI18n()

  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['journal', page],
    queryFn: async () => (await api.get<Paged<JournalEntry>>('/accounting/journal', { params: { page } })).data,
  })

  const rows = data?.data ?? []

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />
  if (!rows.length) return <Empty message={t.accounting.noEntries} />

  return (
    <div className="space-y-4">
      {rows.map((entry) => {
        const money = (value: number) => formatAmount(value, entry.currency, 2, locale)

        return (
          <Card key={entry.id}>
            <div className="flex flex-wrap items-start justify-between gap-3 mb-4">
              <div>
                <div className="flex items-center gap-2">
                  <span className="font-semibold tabular">{entry.entry_no}</span>
                  <Badge tone={entry.status === 'posted' ? 'success' : entry.status === 'reversed' ? 'danger' : 'neutral'}>
                    {entry.status}
                  </Badge>
                  <Badge tone={entry.is_balanced ? 'success' : 'danger'}>
                    {entry.is_balanced ? t.accounting.balanced : t.accounting.notBalanced}
                  </Badge>
                </div>
                <div className="muted text-sm mt-1">{entry.description}</div>
              </div>
              <div className="text-sm muted tabular text-end">
                <div>{entry.entry_date}</div>
                {entry.source && <div className="text-xs">{entry.source}</div>}
              </div>
            </div>

            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)]">
                  <th className="text-start font-medium muted py-2">{t.accounting.account}</th>
                  <th className="text-end font-medium muted py-2 w-40">{t.accounting.debit}</th>
                  <th className="text-end font-medium muted py-2 w-40">{t.accounting.credit}</th>
                </tr>
              </thead>
              <tbody>
                {entry.lines.map((line, index) => (
                  <tr key={index} className="border-b border-[var(--border)] last:border-0">
                    <td className="py-2">
                      <span className="tabular muted me-2">{line.account_code}</span>
                      {line.account_name}
                    </td>
                    <td className="py-2 text-end tabular">{line.debit ? money(line.debit) : '—'}</td>
                    <td className="py-2 text-end tabular">{line.credit ? money(line.credit) : '—'}</td>
                  </tr>
                ))}
                <tr className="font-bold">
                  <td className="py-2">{t.common.total}</td>
                  <td className="py-2 text-end tabular">{money(entry.total_debit)}</td>
                  <td className="py-2 text-end tabular">{money(entry.total_credit)}</td>
                </tr>
              </tbody>
            </table>
          </Card>
        )
      })}

      <Pager meta={data?.meta} page={page} onChange={setPage} />
    </div>
  )
}
