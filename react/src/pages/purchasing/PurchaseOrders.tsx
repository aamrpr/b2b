import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { Plus, Trash2 } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount, toMinor } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Button, Card, Field, Input, Textarea } from '@/components/ui'
import type { Product, PurchaseOrderDetail, PurchaseOrderSummary, StockWarehouse, Vendor } from '@/types'
import { DateField } from '@/components/fields/DateField'
import { SearchSelect } from '@/components/fields/SearchSelect'

const TONE: Record<string, 'success' | 'warning' | 'danger' | 'brand' | 'neutral'> = {
  draft: 'neutral',
  sent: 'brand',
  partially_received: 'warning',
  received: 'success',
  closed: 'neutral',
  cancelled: 'danger',
}

interface Line {
  description: string
  product_id: string
  quantity: string
  unit_cost: string
  tax_amount: string
}

const emptyLine: Line = { description: '', product_id: '', quantity: '1', unit_cost: '', tax_amount: '' }

export function PurchaseOrders() {
  const { t, locale } = useI18n()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [lines, setLines] = useState<Line[]>([{ ...emptyLine }])
  const [error, setError] = useState<string | null>(null)

  const [reloadKey, setReloadKey] = useState(0)

  const { data: vendors } = useQuery({
    queryKey: ['vendors', false],
    queryFn: async () => (await api.get<{ vendors: Vendor[] }>('/vendors')).data.vendors,
  })

  const { data: warehouses } = useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.get<{ warehouses: StockWarehouse[] }>('/inventory/warehouses')).data.warehouses,
  })

  const { data: products } = useQuery({
    queryKey: ['my-products'],
    queryFn: async () => (await api.get<{ data: Product[] }>('/my/products', { params: { length: 200 } })).data.data,
  })

  const create = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<{ order: PurchaseOrderDetail }>('/purchase-orders', payload)).data.order,
    onSuccess: (order) => {
      setReloadKey((key) => key + 1)
      navigate(`/purchasing/orders/${order.id}`)
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  function patchLine(index: number, patch: Partial<Line>) {
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  const statuses = t.purchasing.statuses as Record<string, string>
  const activeVendors = vendors ?? []

  const columns: DataTableColumn<PurchaseOrderSummary>[] = [
    {
      data: 'reference_no',
      title: t.purchasing.orderNo,
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        `<a class="dt-link font-medium dt-num" href="/purchasing/orders/${row.id}" dir="ltr">${esc(row.reference_no)}</a>` +
        `<div class="muted text-xs mt-0.5 dt-num">${esc(row.items_count)} · ${esc(row.currency)}</div>`,
    },
    {
      data: 'vendor',
      title: t.purchasing.vendor,
      orderable: false,
      priority: 2,
      filter: {
        type: 'select',
        options: (vendors ?? []).map((v) => ({ value: String(v.id), label: v.name })),
      },
      render: (row) => `<span dir="auto">${esc(row.vendor.name ?? '\u2014')}</span>`,
    },
    {
      data: 'order_date',
      title: t.purchasing.orderDate,
      className: 'dt-num muted',
      priority: 3,
      filter: { type: 'dates' },
      render: (row) =>
        esc(row.order_date ?? '\u2014') +
        (row.expected_date ? `<div class="text-xs">\u2192 ${esc(row.expected_date)}</div>` : ''),
    },
    {
      data: 'subtotal',
      title: t.purchasing.subtotal,
      orderable: false,
      className: 'dt-num muted',
      priority: 5,
      filter: { type: 'text' },
      render: (row) => esc(formatAmount(row.subtotal, row.currency, 2, locale)),
    },
    {
      data: 'total',
      title: t.common.total,
      className: 'dt-num font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => esc(formatAmount(row.total, row.currency, 2, locale)),
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 1,
      filter: {
        type: 'select',
        options: Object.entries(statuses).map(([value, label]) => ({ value, label })),
      },
      render: (row) =>
        `<span class="dt-chip dt-chip-${TONE[row.status] ?? 'accent'}">${esc(statuses[row.status] ?? row.status)}</span>`,
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">

        <Button size="sm" disabled={!activeVendors.length} onClick={() => setOpen((v) => !v)}>
          <Plus size={15} />
          {t.purchasing.newOrder}
        </Button>
      </div>

      {!activeVendors.length && (
        <p className="muted text-sm">
          {t.purchasing.noVendors} —{' '}
          <Link to="/purchasing/vendors" className="text-accent">
            {t.purchasing.vendors}
          </Link>
        </p>
      )}

      {error && <p className="text-sm text-danger">{error}</p>}

      {open && (
        <Card>
          <form
            className="space-y-5"
            onSubmit={(event) => {
              event.preventDefault()
              setError(null)

              const form = new FormData(event.currentTarget)

              create.mutate({
                vendor_id: Number(form.get('vendor_id')),
                warehouse_id: form.get('warehouse_id') ? Number(form.get('warehouse_id')) : undefined,
                order_date: form.get('order_date') || undefined,
                expected_date: form.get('expected_date') || undefined,
                incoterm: form.get('incoterm') || undefined,
                shipping_amount: toMinor(String(form.get('shipping_amount') ?? '0')),
                notes: form.get('notes') || undefined,
                items: lines
                  .filter((line) => line.description.trim() && Number(line.quantity) > 0)
                  .map((line) => ({
                    product_id: line.product_id ? Number(line.product_id) : undefined,
                    description: line.description,
                    quantity: Number(line.quantity),
                    unit_cost: toMinor(line.unit_cost || '0'),
                    tax_amount: toMinor(line.tax_amount || '0'),
                  })),
              })
            }}
          >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <Field label={t.purchasing.vendor}>
                <SearchSelect
                  name="vendor_id"
                  required
                  placeholder={t.purchasing.pickVendor}
                  options={activeVendors.map((vendor) => ({
                    value: String(vendor.id),
                    label: `${vendor.name} · ${vendor.default_currency}`,
                  }))}
                />
              </Field>

              <Field label={t.purchasing.warehouse}>
                <SearchSelect
                  name="warehouse_id"
                  isClearable
                  options={(warehouses ?? []).map((warehouse) => ({
                    value: String(warehouse.id),
                    label: warehouse.name,
                  }))}
                />
              </Field>

              <Field label={t.purchasing.orderDate}>
                <DateField name="order_date" />
              </Field>

              <Field label={t.purchasing.expectedDate}>
                <DateField name="expected_date" />
              </Field>

              <Field label={t.purchasing.incoterm}>
                <Input name="incoterm" dir="ltr" maxLength={10} placeholder="FOB" className="uppercase" />
              </Field>

              <Field label={t.purchasing.shipping}>
                <Input name="shipping_amount" type="number" step="0.01" min="0" className="tabular" />
              </Field>
            </div>

            <div>
              <div className="flex items-center justify-between gap-3 mb-2">
                <h3 className="font-semibold text-sm">{t.purchasing.lines}</h3>
                <span className="muted text-xs">{t.purchasing.productHint}</span>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-[var(--border)]">
                      {[
                        t.purchasing.description,
                        t.purchasing.product,
                        t.common.quantity,
                        t.purchasing.unitCost,
                        t.purchasing.taxAmount,
                        '',
                      ].map((head, i) => (
                        <th key={i} className="text-start font-medium muted px-2 py-2 whitespace-nowrap">
                          {head}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {lines.map((line, index) => (
                      <tr key={index} className="border-b border-[var(--border)] last:border-0">
                        <td className="px-2 py-2 min-w-48">
                          <Input
                            value={line.description}
                            onChange={(e) => patchLine(index, { description: e.target.value })}
                            required
                          />
                        </td>
                        <td className="px-2 py-2 min-w-40">
                          <SearchSelect
                            name={`product_${index}`}
                            isClearable
                            placeholder={t.purchasing.noProduct}
                            defaultValue={line.product_id}
                            options={(products ?? []).map((product) => ({
                              value: String(product.id),
                              label: product.name,
                            }))}
                            onChange={(value) => {
                              const product = (products ?? []).find((p) => String(p.id) === value)

                              patchLine(index, {
                                product_id: value,
                                description: line.description || product?.name || '',
                              })
                            }}
                          />
                        </td>
                        <td className="px-2 py-2">
                          <Input
                            type="number"
                            min="1"
                            className="tabular w-24"
                            value={line.quantity}
                            onChange={(e) => patchLine(index, { quantity: e.target.value })}
                            required
                          />
                        </td>
                        <td className="px-2 py-2">
                          <Input
                            type="number"
                            step="0.01"
                            min="0"
                            className="tabular w-28"
                            value={line.unit_cost}
                            onChange={(e) => patchLine(index, { unit_cost: e.target.value })}
                            required
                          />
                        </td>
                        <td className="px-2 py-2">
                          <Input
                            type="number"
                            step="0.01"
                            min="0"
                            className="tabular w-28"
                            value={line.tax_amount}
                            onChange={(e) => patchLine(index, { tax_amount: e.target.value })}
                          />
                        </td>
                        <td className="px-2 py-2">
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label={t.common.cancel}
                            disabled={lines.length === 1}
                            onClick={() => setLines((current) => current.filter((_, i) => i !== index))}
                          >
                            <Trash2 size={15} />
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="mt-2"
                onClick={() => setLines((current) => [...current, { ...emptyLine }])}
              >
                <Plus size={14} />
                {t.purchasing.addLine}
              </Button>
            </div>

            <Field label={t.quotation.notes}>
              <Textarea name="notes" />
            </Field>

            <div className="flex gap-3">
              <Button type="submit" disabled={create.isPending}>
                {t.common.save}
              </Button>
              <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                {t.common.cancel}
              </Button>
            </div>
          </form>
        </Card>
      )}

      <DataTable<PurchaseOrderSummary>
        url="/purchase-orders"
        exportName="purchase-orders"
        columns={columns}
        order={['id', 'desc']}
        reloadKey={reloadKey}
        emptyMessage={t.purchasing.noOrders}
      />
    </div>
  )
}
