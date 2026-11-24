import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Button, Card, ErrorState, Field, Input, Loading } from '@/components/ui'
import type { StockWarehouse } from '@/types'

export function Warehouses() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.get<{ warehouses: StockWarehouse[] }>('/inventory/warehouses')).data.warehouses,
  })

  const create = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => (await api.post('/inventory/warehouses', payload)).data,
    onSuccess: () => {
      setOpen(false)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['warehouses'] })
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    create.mutate({
      name: { ar: form.get('name_ar'), en: form.get('name_en') || form.get('name_ar') },
      code: form.get('code'),
      address: form.get('address') || undefined,
      is_default: form.get('is_default') === 'on',
    })
  }

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  const columns: DataTableColumn<StockWarehouse>[] = [
    {
      data: 'name',
      title: t.inventory.warehouse,
      className: 'font-medium',
      priority: 1,
      render: (row) =>
        `<span dir="auto">${esc(row.name)}</span>` +
        // النجمة تقول أيّ مستودعٍ هو الافتراضيّ، وإليه تذهب الحركات بلا وجهة
        (row.is_default ? '<span class="text-star ms-2">\u2605</span>' : '') +
        (row.address ? `<div class="muted text-xs mt-0.5" dir="auto">${esc(row.address)}</div>` : ''),
    },
    {
      data: 'code',
      title: t.inventory.code,
      className: 'dt-num dt-ltr muted',
      priority: 2,
      render: (row) => esc(row.code),
    },
    {
      data: 'items_count',
      title: t.inventory.items,
      className: 'dt-num',
      priority: 3,
      render: (row) => esc(row.items_count ?? 0),
    },
    {
      data: 'value',
      title: t.inventory.value,
      className: 'dt-num font-medium',
      priority: 1,
      render: (row) => esc(formatAmount(row.value, 'SYP', 2, locale)),
    },
    {
      data: 'is_active',
      title: t.common.status,
      priority: 4,
      render: (row) =>
        `<span class="dt-chip ${row.is_active ? 'dt-chip-success' : 'dt-chip-neutral'}">${esc(
          row.is_active ? t.common.accept : t.common.cancel,
        )}</span>`,
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button size="sm" onClick={() => setOpen((v) => !v)}>
          <Plus size={15} />
          {t.inventory.newWarehouse}
        </Button>
      </div>

      {error && <p className="text-sm text-danger">{error}</p>}

      {open && (
        <Card>
          <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <Field label={t.common.nameAr}>
              <Input name="name_ar" required />
            </Field>
            <Field label={t.common.nameEn}>
              <Input name="name_en" dir="ltr" />
            </Field>
            <Field label={t.inventory.code}>
              <Input name="code" dir="ltr" required />
            </Field>
            <Field label={t.auth.city}>
              <Input name="address" />
            </Field>
            <label className="flex items-center gap-2 text-sm sm:col-span-2">
              <input type="checkbox" name="is_default" />
              {t.inventory.isDefault}
            </label>
            <div className="sm:col-span-2 flex gap-3">
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

      <DataTable<StockWarehouse>
        rows={data ?? []}
        exportName="warehouses"
        columns={columns}
        order={['name', 'asc']}
        paging={(data ?? []).length > 10}
        info={(data ?? []).length > 10}
        searching={(data ?? []).length > 10}
      />
    </div>
  )
}
