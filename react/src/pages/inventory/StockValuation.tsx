import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Badge, ErrorState, Loading, Stat } from '@/components/ui'
import type { StockValuation as StockValuationData } from '@/types'

/** تقييم المخزون يجب أن يطابق رصيد حساب المخزون في الأستاذ. */
export function StockValuation() {
  const { t, locale } = useI18n()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['stock-valuation'],
    queryFn: async () => (await api.get<StockValuationData>('/inventory/valuation')).data,
  })

  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const money = (value: number) => formatAmount(value, data.currency, 2, locale)

  const columns: DataTableColumn<StockValuationData['items'][number]>[] = [
    {
      data: 'product',
      title: t.inventory.product,
      priority: 1,
      // الفجوة بالصفّ المرن لا بالحاشية، وإلّا التصق الرمز باسمٍ لاتينيّ في العربية
      render: (row) =>
        '<span class="flex flex-wrap items-baseline gap-2">' +
        `<span class="font-medium" dir="auto">${esc(row.product)}</span>` +
        (row.sku ? `<span class="muted text-xs dt-num" dir="ltr">${esc(row.sku)}</span>` : '') +
        '</span>',
    },
    {
      data: 'warehouse',
      title: t.inventory.warehouse,
      className: 'muted text-sm',
      priority: 3,
      render: (row) => `<span dir="auto">${esc(row.warehouse)}</span>`,
    },
    {
      data: 'quantity',
      title: t.common.quantity,
      className: 'dt-num',
      priority: 2,
      render: (row) => esc(row.quantity),
    },
    {
      data: 'average_cost',
      title: t.inventory.averageCost,
      className: 'dt-num',
      priority: 4,
      render: (row) => esc(money(row.average_cost)),
    },
    {
      data: 'value',
      title: t.inventory.value,
      className: 'dt-num font-medium',
      priority: 1,
      render: (row) => esc(money(row.value)),
    },
  ]

  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-3">
        <Stat label={t.inventory.value} value={money(data.total_value)} />
        <Stat label={t.inventory.ledgerBalance} value={money(data.ledger_balance)} />
        <Stat
          label={t.inventory.difference}
          value={money(data.difference)}
          tone={data.is_reconciled ? 'success' : 'danger'}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3 text-sm">
        <Badge tone={data.is_reconciled ? 'success' : 'danger'}>
          {data.is_reconciled ? t.inventory.reconciled : t.inventory.notReconciled}
        </Badge>
        <span className="muted">
          {t.inventory.costingMethod}: {data.costing_method === 'fifo' ? t.inventory.fifo : t.inventory.wac}
        </span>
      </div>

      {/* المجموع في البطاقة أعلاه، فلا يتكرّر سطراً في ذيل الجدول */}
      <DataTable<StockValuationData['items'][number]>
        rows={data.items}
        exportName="stock-valuation"
        columns={columns}
        order={['value', 'desc']}
        emptyMessage={t.inventory.noItems}
      />
    </div>
  )
}
