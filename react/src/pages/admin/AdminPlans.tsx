import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Badge, Card, ErrorState, Loading } from '@/components/ui'
import type { AdminPlan } from '@/types'

interface SubscriptionRow {
  id: number
  tenant: { id: number; name: string | null }
  plan: string | null
  billing_cycle: string
  amount: number
  currency: string
  status: string
  ends_at: string | null
  is_active: boolean
}

export function AdminPlans() {
  const { t, locale } = useI18n()

  const { data: plans, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin-plans'],
    queryFn: async () => (await api.get<{ plans: AdminPlan[] }>('/admin/plans')).data.plans,
  })

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />

  const columns: DataTableColumn<SubscriptionRow>[] = [
    {
      data: 'tenant',
      title: t.auth.asSupplier,
      orderable: false,
      className: 'font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.tenant.name ?? '—')}</span>`,
    },
    {
      data: 'plan',
      title: t.admin.plan,
      orderable: false,
      priority: 2,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.plan ?? '—')}</span>`,
    },
    {
      data: 'amount',
      title: t.common.total,
      className: 'dt-num',
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        `${esc(formatAmount(row.amount, row.currency, 2, locale))}` +
        `<span class="muted text-xs ms-2">${esc(row.billing_cycle === 'yearly' ? t.admin.yearly : t.admin.monthly)}</span>`,
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 2,
      filter: {
        type: 'select',
        options: ['trialing', 'active', 'past_due', 'cancelled', 'expired'].map((value) => ({
          value,
          label: value,
        })),
      },
      render: (row) =>
        `<span class="dt-chip ${row.is_active ? 'dt-chip-success' : 'dt-chip-neutral'} dt-ltr">${esc(row.status)}</span>`,
    },
    {
      data: 'ends_at',
      title: t.common.date,
      className: 'dt-num muted',
      priority: 3,
      filter: { type: 'dates' },
      render: (row) => esc(row.ends_at ?? '—'),
    },
  ]

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {(plans ?? []).map((plan) => (
          <Card key={plan.id} className="flex flex-col">
            <div className="flex items-start justify-between gap-2">
              <span className="font-semibold">{plan.name}</span>
              <Badge tone={plan.is_active ? 'success' : 'neutral'}>{plan.slug}</Badge>
            </div>

            <div className="mt-4 text-2xl font-bold tabular text-accent">
              {formatAmount(plan.price_monthly, plan.currency, 2, locale)}
              <span className="muted text-sm font-normal"> / {t.admin.monthly}</span>
            </div>

            <dl className="mt-4 space-y-1.5 text-sm">
              {[
                [t.admin.yearly, formatAmount(plan.price_yearly, plan.currency, 2, locale)],
                [t.admin.productLimit, plan.product_limit ?? t.admin.unlimited],
                [t.admin.userLimit, plan.user_limit],
                [t.nav.accounting, plan.accounting_enabled ? '✓' : '—'],
                [t.admin.subscribers, plan.subscribers],
              ].map(([label, value]) => (
                <div key={String(label)} className="flex justify-between gap-2">
                  <dt className="muted">{label}</dt>
                  <dd className="tabular">{value}</dd>
                </div>
              ))}
            </dl>
          </Card>
        ))}
      </div>

      <div>
        <h2 className="font-semibold mb-3">{t.admin.plans}</h2>
        <DataTable<SubscriptionRow>
          url="/admin/subscriptions"
          exportName="subscriptions"
          columns={columns}
          order={['id', 'desc']}
        />
      </div>
    </div>
  )
}
