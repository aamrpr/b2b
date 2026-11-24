import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { formatAmount } from '@/lib/money'
import { Card, ErrorState, Loading, Stat } from '@/components/ui'
import type { AdminOverview, AdminSeries, TopSupplier } from '@/types'

export function AdminDashboard() {
  const { t, locale, dir } = useI18n()

  const { data: overview, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin-overview'],
    queryFn: async () => (await api.get<AdminOverview>('/admin/dashboard')).data,
  })

  const { data: series } = useQuery({
    queryKey: ['admin-series'],
    queryFn: async () => (await api.get<AdminSeries>('/admin/dashboard/series')).data,
  })

  const { data: top } = useQuery({
    queryKey: ['admin-top-suppliers'],
    queryFn: async () => (await api.get<{ suppliers: TopSupplier[] }>('/admin/dashboard/top-suppliers')).data.suppliers,
  })

  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />

  if (isLoading || !overview) return <Loading message={t.common.loading} />

  const money = (value: number, currency = overview.currency) => formatAmount(value, currency, 2, locale)

  // الرسوم تعرض المبالغ بالوحدة الكبرى ليقرأها المستخدم
  const chartData = (series?.series ?? []).map((point) => ({
    month: point.month,
    [t.admin.gmv]: Math.round(point.gmv / 100),
    [t.admin.commissionRevenue]: Math.round(point.commission / 100),
    [t.admin.ordersCount]: point.orders,
  }))

  const topColumns: DataTableColumn<TopSupplier>[] = [
    {
      data: 'name',
      title: t.auth.asSupplier,
      className: 'font-medium',
      priority: 1,
      render: (row) => `<span dir="auto">${esc(row.name)}</span>`,
    },
    {
      data: 'orders',
      title: t.admin.ordersCount,
      className: 'dt-num',
      priority: 2,
      render: (row) => esc(row.orders),
    },
    {
      data: 'gmv',
      title: t.admin.gmv,
      className: 'dt-num',
      priority: 1,
      render: (row) => esc(formatAmount(row.gmv, row.currency, 2, locale)),
    },
    {
      data: 'commission',
      title: t.order.commission,
      className: 'dt-num muted',
      priority: 3,
      render: (row) => esc(formatAmount(row.commission, row.currency, 2, locale)),
    },
  ]

  return (
    <div className="space-y-6">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label={t.admin.gmv} value={money(overview.gmv.total)} />
        <Stat label={t.admin.commissionRevenue} value={money(overview.commission.revenue)} tone="success" />
        <Stat label={t.admin.receivable} value={money(overview.commission.receivable)} />
        <Stat label={t.admin.mrr} value={money(overview.subscriptions.mrr, overview.subscriptions.currency)} />
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label={t.admin.ordersCount} value={String(overview.orders.total)} />
        <Stat label={t.admin.activeSuppliers} value={String(overview.suppliers.active)} />
        <Stat
          label={t.admin.pendingReview}
          value={String(overview.suppliers.pending_review)}
          tone={overview.suppliers.pending_review > 0 ? 'danger' : undefined}
        />
        <Stat label={t.admin.buyers} value={String(overview.buyers)} />
      </div>

      <Card>
        <h2 className="font-semibold mb-4">{t.admin.monthlyTrend}</h2>
        <div className="h-72" dir="ltr">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartData} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
              <XAxis dataKey="month" stroke="var(--muted)" fontSize={12} />
              <YAxis stroke="var(--muted)" fontSize={12} width={70} />
              <Tooltip
                contentStyle={{
                  background: 'var(--card)',
                  border: '1px solid var(--border)',
                  borderRadius: 10,
                  color: 'var(--text)',
                }}
              />
              {/* عنوان المفتاح بلون النصّ لا بلون السلسلة: الأخضر الفاتح
                  كان يسقط إلى 2.5:1 كنصّ على أبيض. اللون يبقى في المربّع. */}
              <Legend formatter={(value: string) => <span style={{ color: 'var(--text)' }}>{value}</span>} />
              <Line type="monotone" dataKey={t.admin.gmv} stroke="var(--chart-1)" strokeWidth={2} dot={false} />
              {/* التقطيع يفرّق السلسلتين لمن لا يميّز اللونين */}
              <Line
                type="monotone"
                dataKey={t.admin.commissionRevenue}
                stroke="var(--chart-2)"
                strokeWidth={2}
                strokeDasharray="5 3"
                dot={false}
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </Card>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card>
          <h2 className="font-semibold mb-4">{t.admin.funnel}</h2>
          <div className="h-56" dir="ltr">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart
                data={[
                  { stage: t.admin.published, value: overview.rfq.published },
                  { stage: t.admin.quoted, value: overview.rfq.quoted },
                  { stage: t.admin.awarded, value: overview.rfq.awarded },
                ]}
              >
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                <XAxis dataKey="stage" stroke="var(--muted)" fontSize={12} />
                <YAxis stroke="var(--muted)" fontSize={12} allowDecimals={false} width={40} />
                <Tooltip
                  contentStyle={{
                    background: 'var(--card)',
                    border: '1px solid var(--border)',
                    borderRadius: 10,
                    color: 'var(--text)',
                  }}
                />
                <Bar dataKey="value" fill="var(--chart-1)" radius={[6, 6, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
          <div className="mt-3 text-sm muted" dir={dir}>
            {t.admin.conversionRate}: <span className="tabular font-semibold">{overview.rfq.conversion_rate}%</span>
          </div>
        </Card>

        <Card className="p-0">
          <h2 className="font-semibold p-5 pb-3">{t.admin.byCurrency}</h2>
          <table className="w-full text-sm">
            <tbody>
              {overview.gmv.by_currency.map((row) => (
                <tr key={row.currency} className="border-t border-[var(--border)]">
                  <td className="px-5 py-3 font-medium tabular">{row.currency}</td>
                  <td className="px-5 py-3 tabular">{formatAmount(row.amount, row.currency, 2, locale)}</td>
                  <td className="px-5 py-3 tabular muted">{row.orders}</td>
                </tr>
              ))}
              {overview.gmv.by_currency.length === 0 && (
                <tr>
                  <td className="px-5 py-8 text-center muted" colSpan={3}>
                    {t.common.empty}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </Card>
      </div>

      <div>
        <h2 className="font-semibold mb-3">{t.admin.topSuppliers}</h2>
        <DataTable<TopSupplier>
          rows={top ?? []}
          exportName="top-suppliers"
          columns={topColumns}
          order={['gmv', 'desc']}
          paging={(top ?? []).length > 10}
          info={(top ?? []).length > 10}
          searching={(top ?? []).length > 10}
        />
      </div>
    </div>
  )
}
