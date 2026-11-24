import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PackagePlus } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount, toMinor } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Button, Card, Field, Input, Select } from '@/components/ui'
import type { Product, StockItem, StockWarehouse } from '@/types'
import { SearchSelect } from '@/components/fields/SearchSelect'

export function StockItems() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [lowOnly, setLowOnly] = useState(false)
  const [open, setOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data: warehouses } = useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.get<{ warehouses: StockWarehouse[] }>('/inventory/warehouses')).data.warehouses,
  })

  const { data: products } = useQuery({
    queryKey: ['my-products-lite'],
    queryFn: async () => (await api.get<{ data: Product[] }>('/my/products', { params: { length: 200 } })).data.data,
    enabled: open,
  })

  const [reloadKey, setReloadKey] = useState(0)
  const [currency, setCurrency] = useState('SYP')

  const receive = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => (await api.post('/inventory/receive', payload)).data,
    onSuccess: () => {
      setOpen(false)
      setError(null)
      setReloadKey((key) => key + 1)
      void queryClient.invalidateQueries({ queryKey: ['stock-valuation'] })
      void queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    receive.mutate({
      product_id: Number(form.get('product_id')),
      warehouse_id: form.get('warehouse_id') ? Number(form.get('warehouse_id')) : undefined,
      quantity: Number(form.get('quantity')),
      unit_cost: toMinor(String(form.get('unit_cost') ?? '0')),
      type: form.get('type'),
      post_journal: true,
      notes: form.get('notes') || undefined,
    })
  }

  const money = (value: number) => formatAmount(value, currency, 2, locale)

  const columns: DataTableColumn<StockItem>[] = [
    {
      data: 'product',
      title: t.inventory.product,
      orderable: false,
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        /*
         * الصفّ المرن لا الحاشية: اسمٌ لاتينيّ ورمزٌ لاتينيّ متجاوران في
         * العربية يندمجان في مقطعٍ واحد فتقع الحاشية خارجهما ويلتصقان،
         * بينما gap يفصل بين الصناديق مهما كان اتجاه النصّ.
         */
        '<span class="flex flex-wrap items-baseline gap-2">' +
        `<a class="dt-link font-medium" href="/inventory/items/${row.id}" dir="auto">${esc(row.product.name)}</a>` +
        (row.product.sku ? `<span class="muted text-xs dt-num" dir="ltr">${esc(row.product.sku)}</span>` : '') +
        '</span>',
    },
    {
      data: 'warehouse',
      title: t.inventory.warehouse,
      orderable: false,
      className: 'muted text-sm',
      priority: 3,
      filter: {
        type: 'select',
        options: (warehouses ?? []).map((w) => ({ value: String(w.id), label: w.name })),
      },
      render: (row) => `<span dir="auto">${esc(row.warehouse.name)}</span>`,
    },
    {
      data: 'quantity',
      title: t.inventory.onHand,
      className: 'dt-num font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        esc(row.quantity_on_hand) +
        (row.is_low ? `<span class="dt-chip dt-chip-danger ms-2">${esc(t.inventory.lowStock)}</span>` : ''),
    },
    {
      data: 'average_cost',
      title: t.inventory.averageCost,
      className: 'dt-num',
      priority: 4,
      filter: { type: 'text' },
      render: (row) => esc(money(row.average_cost)),
    },
    {
      data: 'value',
      title: t.inventory.value,
      className: 'dt-num font-medium',
      priority: 2,
      filter: { type: 'text' },
      render: (row) => esc(money(row.value)),
    },
    {
      data: 'reorder_point',
      title: t.inventory.reorderPoint,
      className: 'dt-num muted',
      priority: 5,
      filter: { type: 'text' },
      render: (row) => esc(row.reorder_point || '\u2014'),
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        {/* المستودع صار مرشّحاً في عموده؛ وحدّ النقص شرطٌ لا عمود، فبقي هنا */}
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={lowOnly} onChange={(e) => setLowOnly(e.target.checked)} />
          {t.inventory.lowStockOnly}
        </label>

        <Button size="sm" className="ms-auto" onClick={() => setOpen((v) => !v)}>
          <PackagePlus size={15} />
          {t.inventory.receive}
        </Button>
      </div>

      {error && <p className="text-sm text-danger">{error}</p>}

      {open && (
        <Card>
          <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
            <Field label={t.inventory.product}>
              <SearchSelect
                name="product_id"
                required
                options={(products ?? []).map((p) => ({ value: String(p.id), label: p.name }))}
              />
            </Field>

            <Field label={t.inventory.warehouse}>
              <SearchSelect
                name="warehouse_id"
                options={(warehouses ?? []).map((w) => ({ value: String(w.id), label: w.name }))}
              />
            </Field>

            <Field label={t.inventory.movementType}>
              <Select name="type" defaultValue="purchase_in">
                <option value="purchase_in">{t.inventory.types.purchase_in}</option>
                <option value="opening">{t.inventory.types.opening}</option>
              </Select>
            </Field>

            <Field label={t.common.quantity}>
              <Input name="quantity" type="number" min={1} className="tabular" required />
            </Field>

            <Field label={t.inventory.unitCost}>
              <Input name="unit_cost" type="number" step="0.01" min="0" className="tabular" required />
            </Field>

            <Field label={t.quotation.notes}>
              <Input name="notes" />
            </Field>

            <div className="sm:col-span-3 flex gap-3">
              <Button type="submit" disabled={receive.isPending}>
                {receive.isPending ? t.common.loading : t.common.save}
              </Button>
              <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                {t.common.cancel}
              </Button>
            </div>
          </form>
        </Card>
      )}

      <DataTable<StockItem>
        url="/inventory/items"
        exportName="stock-items"
        columns={columns}
        order={['id', 'desc']}
        reloadKey={reloadKey}
        emptyMessage={t.inventory.noItems}
        params={{ low_stock: lowOnly ? 1 : undefined }}
        onResponse={(payload) => {
          if (typeof payload.currency === 'string') setCurrency(payload.currency)
        }}
      />
    </div>
  )
}
