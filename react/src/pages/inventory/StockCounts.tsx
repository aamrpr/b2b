import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ClipboardList, Check } from 'lucide-react'
import clsx from 'clsx'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Button, Card, Field, Input } from '@/components/ui'
import type { StockCountDetail, StockCountSummary, StockWarehouse } from '@/types'
import { SearchSelect } from '@/components/fields/SearchSelect'

export function StockCounts() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [activeId, setActiveId] = useState<number | null>(null)
  const [counts, setCounts] = useState<Record<number, string>>({})
  const [open, setOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data: warehouses } = useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.get<{ warehouses: StockWarehouse[] }>('/inventory/warehouses')).data.warehouses,
  })

  const [reloadKey, setReloadKey] = useState(0)

  const { data: detail } = useQuery({
    queryKey: ['stock-count', activeId],
    queryFn: async () =>
      (await api.get<{ stock_count: StockCountDetail }>(`/inventory/stock-counts/${activeId}`)).data.stock_count,
    enabled: activeId !== null,
  })

  function refresh() {
    setReloadKey((key) => key + 1)
    void queryClient.invalidateQueries({ queryKey: ['stock-count', activeId] })
    void queryClient.invalidateQueries({ queryKey: ['stock-items'] })
    void queryClient.invalidateQueries({ queryKey: ['stock-valuation'] })
    setError(null)
  }

  const create = useMutation({
    mutationFn: async (warehouseId: number) =>
      (await api.post<{ stock_count: StockCountDetail }>('/inventory/stock-counts', { warehouse_id: warehouseId })).data
        .stock_count,
    onSuccess: (count) => {
      setOpen(false)
      setActiveId(count.id)
      refresh()
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const record = useMutation({
    mutationFn: async () =>
      (
        await api.put(`/inventory/stock-counts/${activeId}/record`, {
          items: Object.entries(counts).map(([id, value]) => ({
            id: Number(id),
            counted_quantity: Number(value),
          })),
        })
      ).data,
    onSuccess: () => {
      setCounts({})
      refresh()
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const approve = useMutation({
    mutationFn: async () => (await api.post(`/inventory/stock-counts/${activeId}/approve`)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const statusLabels = t.inventory.countStatus as Record<string, string>

  const columns: DataTableColumn<StockCountSummary>[] = [
    {
      data: 'reference_no',
      title: t.accounting.entryNo,
      className: 'dt-num dt-ltr font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => esc(row.reference_no),
    },
    {
      data: 'warehouse',
      title: t.inventory.warehouse,
      orderable: false,
      priority: 3,
      filter: {
        type: 'select',
        options: (warehouses ?? []).map((w) => ({ value: String(w.id), label: w.name })),
      },
      render: (row) => `<span dir="auto">${esc(row.warehouse ?? '\u2014')}</span>`,
    },
    {
      data: 'count_date',
      filter: { type: 'dates' },
      title: t.common.date,
      className: 'dt-num muted',
      priority: 2,
      render: (row) => esc(row.count_date ?? '\u2014'),
    },
    {
      data: 'items_count',
      title: t.inventory.items,
      orderable: false,
      className: 'dt-num',
      priority: 4,
      filter: { type: 'text' },
      render: (row) => esc(row.items_count),
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 1,
      filter: {
        type: 'select',
        options: Object.entries(statusLabels).map(([value, label]) => ({ value, label })),
      },
      render: (row) =>
        `<span class="dt-chip ${row.status === 'completed' ? 'dt-chip-success' : 'dt-chip-warning'}">${esc(
          statusLabels[row.status] ?? row.status,
        )}</span>`,
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 1,
      render: (row) =>
        `<button type="button" class="dt-btn" data-action="open" data-id="${row.id}">${esc(t.common.view)}</button>`,
    },
  ]

  return (
    <div className="space-y-5">
      <div className="flex justify-end">
        <Button size="sm" onClick={() => setOpen((v) => !v)}>
          <ClipboardList size={15} />
          {t.inventory.newCount}
        </Button>
      </div>

      {error && <p className="text-sm text-danger">{error}</p>}

      {open && (
        <Card>
          <form
            className="flex flex-wrap items-end gap-4"
            onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              create.mutate(Number(form.get('warehouse_id')))
            }}
          >
            <Field label={t.inventory.warehouse}>
              <div className="min-w-48">
                <SearchSelect
                  name="warehouse_id"
                  required
                  options={(warehouses ?? []).map((w) => ({ value: String(w.id), label: w.name }))}
                />
              </div>
            </Field>
            <Button type="submit" disabled={create.isPending}>
              {t.inventory.openCount}
            </Button>
          </form>
        </Card>
      )}

      <DataTable<StockCountSummary>
        url="/inventory/stock-counts"
        exportName="stock-counts"
        columns={columns}
        order={['id', 'desc']}
        pageLength={20}
        reloadKey={reloadKey}
        onAction={(action, id) => {
          if (action === 'open') setActiveId(Number(id))
        }}
      />

      {detail && (
        <Card className="p-0">
          <div className="flex flex-wrap items-center justify-between gap-3 p-5 border-b border-[var(--border)]">
            <div>
              <span className="font-semibold tabular">{detail.reference_no}</span>
              <span className="muted text-sm ms-3">{detail.warehouse.name}</span>
            </div>

            <div className="flex items-center gap-3">
              <span className="text-sm muted">
                {t.inventory.valueDifference}:{' '}
                <span
                  className={clsx(
                    'tabular font-medium',
                    detail.net_value_difference < 0 ? 'text-danger' : 'text-success',
                  )}
                >
                  {formatAmount(detail.net_value_difference, 'SYP', 2, locale)}
                </span>
              </span>

              {detail.status === 'counting' && (
                <>
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={Object.keys(counts).length === 0 || record.isPending}
                    onClick={() => record.mutate()}
                  >
                    {t.inventory.recordCounts}
                  </Button>
                  <Button size="sm" disabled={approve.isPending} onClick={() => approve.mutate()}>
                    <Check size={15} />
                    {t.inventory.approveCount}
                  </Button>
                </>
              )}
            </div>
          </div>

          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-[var(--border)]">
                {[t.inventory.product, t.inventory.expected, t.inventory.countedQuantity, t.inventory.difference].map(
                  (head) => (
                    <th key={head} className="text-start font-medium muted px-5 py-3">
                      {head}
                    </th>
                  ),
                )}
              </tr>
            </thead>
            <tbody>
              {detail.items.map((line) => (
                <tr key={line.id} className="border-b border-[var(--border)] last:border-0">
                  <td className="px-5 py-2.5">{line.product}</td>
                  <td className="px-5 py-2.5 tabular">{line.expected_quantity}</td>
                  <td className="px-5 py-2.5">
                    {detail.status === 'counting' ? (
                      <Input
                        type="number"
                        min={0}
                        className="tabular max-w-28"
                        value={counts[line.id] ?? line.counted_quantity ?? ''}
                        onChange={(e) => setCounts((c) => ({ ...c, [line.id]: e.target.value }))}
                      />
                    ) : (
                      <span className="tabular">{line.counted_quantity}</span>
                    )}
                  </td>
                  <td
                    className={clsx(
                      'px-5 py-2.5 tabular font-medium',
                      line.difference === 0 ? 'muted' : line.difference > 0 ? 'text-success' : 'text-danger',
                    )}
                  >
                    {line.difference > 0 ? '+' : ''}
                    {line.difference}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

    </div>
  )
}
