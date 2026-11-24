import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { ArrowLeftRight, SlidersHorizontal } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Badge, Button, Card, ErrorState, Field, Input, Loading, PageTitle, Stat } from '@/components/ui'
import type { StockItem, StockMovement, StockWarehouse } from '@/types'
import { SearchSelect } from '@/components/fields/SearchSelect'

interface ItemResponse {
  currency: string
  item: StockItem
  derived_quantity: number
  movements: StockMovement[]
}

export function StockItemDetail() {
  const { itemId = '' } = useParams()
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [panel, setPanel] = useState<'adjust' | 'transfer' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['stock-item', itemId],
    queryFn: async () => (await api.get<ItemResponse>(`/inventory/items/${itemId}`)).data,
  })

  const { data: warehouses } = useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.get<{ warehouses: StockWarehouse[] }>('/inventory/warehouses')).data.warehouses,
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['stock-item', itemId] })
    void queryClient.invalidateQueries({ queryKey: ['stock-items'] })
    void queryClient.invalidateQueries({ queryKey: ['stock-valuation'] })
    setPanel(null)
    setError(null)
  }

  const adjust = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post(`/inventory/items/${itemId}/adjust`, payload)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const transfer = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post(`/inventory/items/${itemId}/transfer`, payload)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const reorder = useMutation({
    mutationFn: async (value: number) =>
      (await api.put(`/inventory/items/${itemId}/settings`, { reorder_point: value })).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const money = (value: number) => formatAmount(value, data.currency, 2, locale)
  const typeLabels = t.inventory.types as Record<string, string>
  const mismatch = data.derived_quantity !== data.item.quantity_on_hand

  const movementColumns: DataTableColumn<StockMovement>[] = [
    {
      data: 'created_at',
      title: t.common.date,
      className: 'dt-num muted text-sm',
      priority: 2,
      render: (row) => esc(row.created_at?.slice(0, 10) ?? ''),
    },
    {
      data: 'type',
      title: t.inventory.movementType,
      priority: 1,
      render: (row) =>
        `<span class="dt-chip ${row.quantity > 0 ? 'dt-chip-success' : 'dt-chip-warning'}">${esc(
          typeLabels[row.type] ?? row.type,
        )}</span>` + (row.notes ? `<div class="muted text-xs mt-1" dir="auto">${esc(row.notes)}</div>` : ''),
    },
    {
      data: 'quantity',
      title: t.common.quantity,
      // الإشارة تسبق الرقم: في العربية يقع ‎+‎ آخر السطر ما لم يُعزَل
      className: 'dt-num dt-ltr font-medium',
      priority: 1,
      render: (row) =>
        `<span class="${row.quantity > 0 ? 'text-success' : 'text-danger'}">${row.quantity > 0 ? '+' : ''}${esc(row.quantity)}</span>`,
    },
    {
      data: 'unit_cost',
      title: t.inventory.unitCost,
      className: 'dt-num',
      priority: 3,
      render: (row) => esc(money(row.unit_cost)),
    },
    {
      data: 'balance_after',
      title: t.inventory.balanceAfter,
      className: 'dt-num',
      priority: 2,
      render: (row) => esc(row.balance_after),
    },
    {
      data: 'journal_entry_no',
      title: t.inventory.journalEntry,
      className: 'dt-num dt-ltr muted text-xs',
      priority: 5,
      render: (row) => esc(row.journal_entry_no ?? '\u2014'),
    },
  ]

  return (
    <div className="space-y-5">
      <PageTitle title={t.inventory.title} back="/inventory" />
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 className="text-lg font-bold">{data.item.product.name}</h2>
            <div className="muted text-sm mt-1 tabular">
              {data.item.product.sku} · {data.item.warehouse.name}
            </div>
          </div>

          <div className="flex gap-2">
            <Button size="sm" variant="outline" onClick={() => setPanel(panel === 'adjust' ? null : 'adjust')}>
              <SlidersHorizontal size={15} />
              {t.inventory.adjust}
            </Button>
            <Button size="sm" variant="outline" onClick={() => setPanel(panel === 'transfer' ? null : 'transfer')}>
              <ArrowLeftRight size={15} />
              {t.inventory.transfer}
            </Button>
          </div>
        </div>

        {mismatch && (
          <p className="mt-4 text-sm text-danger">
            {t.inventory.notReconciled}: {data.item.quantity_on_hand} ≠ {data.derived_quantity}
          </p>
        )}

        {panel === 'adjust' && (
          <form
            className="mt-4 pt-4 border-t border-[var(--border)] grid gap-4 sm:grid-cols-3"
            onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              adjust.mutate({
                counted_quantity: Number(form.get('counted_quantity')),
                reason: form.get('reason'),
              })
            }}
          >
            <Field label={t.inventory.countedQuantity}>
              <Input
                name="counted_quantity"
                type="number"
                min={0}
                className="tabular"
                defaultValue={data.item.quantity_on_hand}
                required
              />
            </Field>
            <Field label={t.inventory.reason}>
              <Input name="reason" required />
            </Field>
            <div className="flex items-end">
              <Button type="submit" disabled={adjust.isPending}>
                {t.common.save}
              </Button>
            </div>
          </form>
        )}

        {panel === 'transfer' && (
          <form
            className="mt-4 pt-4 border-t border-[var(--border)] grid gap-4 sm:grid-cols-3"
            onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              transfer.mutate({
                warehouse_id: Number(form.get('warehouse_id')),
                quantity: Number(form.get('quantity')),
              })
            }}
          >
            <Field label={t.inventory.warehouse}>
              <SearchSelect
                name="warehouse_id"
                required
                options={(warehouses ?? [])
                  .filter((warehouse) => warehouse.id !== data.item.warehouse.id)
                  .map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name }))}
              />
            </Field>
            <Field label={t.common.quantity}>
              <Input
                name="quantity"
                type="number"
                min={1}
                max={data.item.quantity_on_hand}
                className="tabular"
                required
              />
            </Field>
            <div className="flex items-end">
              <Button type="submit" disabled={transfer.isPending}>
                {t.common.save}
              </Button>
            </div>
          </form>
        )}

        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      </Card>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label={t.inventory.onHand} value={String(data.item.quantity_on_hand)} />
        <Stat label={t.inventory.available} value={String(data.item.quantity_available)} />
        <Stat label={t.inventory.averageCost} value={money(data.item.average_cost)} />
        <Stat label={t.inventory.value} value={money(data.item.value)} />
      </div>

      <Card className="flex flex-wrap items-end gap-4">
        <Field label={t.inventory.reorderPoint}>
          <Input
            type="number"
            min={0}
            defaultValue={data.item.reorder_point}
            className="tabular max-w-32"
            onBlur={(e) => {
              const value = Number(e.target.value)
              if (value !== data.item.reorder_point) reorder.mutate(value)
            }}
          />
        </Field>
        {data.item.is_low && <Badge tone="danger">{t.inventory.lowStock}</Badge>}
      </Card>

      <div>
        <h3 className="font-semibold mb-3">{t.inventory.movements}</h3>
        <DataTable<StockMovement>
          rows={data.movements}
          exportName="item-movements"
          columns={movementColumns}
          order={['created_at', 'desc']}
          paging={data.movements.length > 10}
          info={data.movements.length > 10}
          searching={data.movements.length > 10}
        />
      </div>
    </div>
  )
}
