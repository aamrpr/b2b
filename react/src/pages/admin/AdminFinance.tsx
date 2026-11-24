import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { ErrorState, Loading, Stat } from '@/components/ui'
import type { CommissionAging, CommissionRow } from '@/types'

const BUCKETS = ['current', '1_30', '31_60', '61_90', 'over_90'] as const

type SupplierAgingRow = CommissionAging['by_supplier'][number]

/**
 * المركز المالي للمنصة: ما لها على مستورديها، وأعمارُه.
 *
 * جدولان مختلفان في مصدرهما: الأعمار تقريرٌ يأتي كاملاً فيُفرَز ويُبحَث
 * في المتصفّح، والعمولات قائمةٌ طويلة تُصفَّح من الخادم. وكلاهما
 * DataTable — والفرق في مكان العمل لا في شكل الجدول.
 */
export function AdminFinance() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  const { data: aging, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['admin-aging'],
    queryFn: async () => (await api.get<CommissionAging>('/admin/finance/commission-aging')).data,
  })

  function refresh() {
    setError(null)
    setReloadKey((key) => key + 1)
    void queryClient.invalidateQueries({ queryKey: ['admin-aging'] })
    void queryClient.invalidateQueries({ queryKey: ['admin-overview'] })
  }

  const settle = useMutation({
    mutationFn: async (id: string) => (await api.post(`/admin/finance/commissions/${id}/settle`)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const settleSupplier = useMutation({
    mutationFn: async (id: string) => (await api.post(`/admin/finance/suppliers/${id}/settle`)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />
  if (isLoading || !aging) return <Loading message={t.common.loading} />

  const currency = aging.by_supplier[0]?.currency ?? 'SYP'
  const money = (value: number, c = currency) => formatAmount(value, c, 2, locale)

  const bucketLabel: Record<string, string> = {
    current: t.accounting.balance,
    '1_30': '1–30',
    '31_60': '31–60',
    '61_90': '61–90',
    over_90: '+90',
  }

  const supplierColumns: DataTableColumn<SupplierAgingRow>[] = [
    {
      data: 'name',
      title: t.auth.asSupplier,
      className: 'font-medium',
      priority: 1,
      render: (row) => `<span dir="auto">${esc(row.name)}</span>`,
    },
    {
      data: 'total',
      title: t.admin.accrued,
      className: 'dt-num',
      priority: 1,
      render: (row) =>
        `${esc(money(row.total, row.currency))}<span class="muted text-xs ms-2">(${esc(row.count)})</span>`,
    },
    {
      data: 'oldest_days',
      title: t.admin.oldestDays,
      className: 'dt-num',
      priority: 2,
      render: (row) => `${esc(row.oldest_days)} ${esc(t.admin.days)}`,
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 1,
      render: (row) =>
        `<button type="button" class="dt-btn" data-action="settle-supplier" data-id="${row.supplier_id}">${esc(t.admin.settleAll)}</button>`,
    },
  ]

  const commissionColumns: DataTableColumn<CommissionRow>[] = [
    {
      data: 'order_no',
      title: t.order.orderNo,
      orderable: false,
      className: 'dt-ltr dt-num',
      priority: 2,
      filter: { type: 'text' },
      render: (row) => esc(row.order_no ?? '—'),
    },
    {
      data: 'supplier',
      title: t.auth.asSupplier,
      orderable: false,
      priority: 3,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.supplier.name)}</span>`,
    },
    {
      data: 'amount',
      title: t.accounting.balance,
      className: 'dt-num font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        `<span class="flex flex-wrap items-baseline gap-2">${esc(money(row.amount, row.currency))}` +
        `<span class="muted text-xs dt-num" dir="ltr">${esc(row.rate)}%</span></span>`,
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 1,
      filter: {
        type: 'select',
        options: [
          { value: 'accrued', label: t.admin.accrued },
          { value: 'settled', label: t.admin.settled },
        ],
      },
      render: (row) =>
        `<span class="dt-chip ${row.status === 'settled' ? 'dt-chip-success' : 'dt-chip-warning'}">${esc(
          row.status === 'settled' ? t.admin.settled : t.admin.accrued,
        )}</span>`,
    },
    {
      data: 'accrued_at',
      title: t.common.date,
      className: 'dt-num muted',
      priority: 4,
      filter: { type: 'dates' },
      render: (row) => esc(row.accrued_at ?? '—'),
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 1,
      render: (row) =>
        row.status === 'settled'
          ? ''
          : `<button type="button" class="dt-btn" data-action="settle" data-id="${row.id}">${esc(t.admin.settle)}</button>`,
    },
  ]

  return (
    <div className="space-y-6">
      {error && <p className="text-sm text-danger">{error}</p>}

      <div>
        <h2 className="font-semibold mb-3">{t.admin.aging}</h2>
        <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
          {BUCKETS.map((bucket) => (
            <Stat
              key={bucket}
              label={bucketLabel[bucket]}
              value={money(aging.buckets[bucket] ?? 0)}
              tone={bucket === 'over_90' && (aging.buckets[bucket] ?? 0) > 0 ? 'danger' : undefined}
            />
          ))}
        </div>
      </div>

      <div>
        <h2 className="font-semibold mb-3">{t.auth.asSupplier}</h2>
        <DataTable<SupplierAgingRow>
          rows={aging.by_supplier}
          exportName="commission-aging"
          columns={supplierColumns}
          order={['total', 'desc']}
          paging={aging.by_supplier.length > 10}
          info={aging.by_supplier.length > 10}
          onAction={(action, id) => {
            if (action === 'settle-supplier') settleSupplier.mutate(id)
          }}
        />
      </div>

      <div>
        <h2 className="font-semibold mb-3">{t.admin.commissions}</h2>
        <DataTable<CommissionRow>
          url="/admin/finance/commissions"
          exportName="commissions"
          columns={commissionColumns}
          order={['id', 'desc']}
          reloadKey={reloadKey}
          onAction={(action, id) => {
            if (action === 'settle') settle.mutate(id)
          }}
        />
      </div>
    </div>
  )
}
