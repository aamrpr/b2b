import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Button, Card, ErrorState, Field, Input, Loading, Select } from '@/components/ui'
import type { CommissionRule } from '@/types'

export function AdminCommissions() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['commission-rules'],
    queryFn: async () => (await api.get<{ rules: CommissionRule[] }>('/admin/commission-rules')).data.rules,
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['commission-rules'] })
  }

  const create = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => (await api.post('/admin/commission-rules', payload)).data,
    onSuccess: () => {
      setOpen(false)
      setError(null)
      refresh()
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const update = useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      (await api.put(`/admin/commission-rules/${id}`, payload)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const remove = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/admin/commission-rules/${id}`)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const scopeLabel: Record<string, string> = {
    global: t.admin.scopeGlobal,
    category: t.admin.scopeCategory,
    supplier: t.admin.scopeSupplier,
    plan: t.admin.scopePlan,
  }

  const typeLabel: Record<string, string> = {
    percentage: t.admin.typePercentage,
    fixed: t.admin.typeFixed,
    tiered: t.admin.typeTiered,
  }

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    create.mutate({
      scope: form.get('scope'),
      scope_id: form.get('scope_id') ? Number(form.get('scope_id')) : null,
      type: form.get('type'),
      value: Number(form.get('value') ?? 0),
      accrual_trigger: form.get('accrual_trigger'),
      priority: Number(form.get('priority') ?? 0),
      is_active: true,
    })
  }

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  const columns: DataTableColumn<CommissionRule>[] = [
    {
      data: 'scope',
      title: t.admin.scope,
      priority: 1,
      render: (row) =>
        `<span class="dt-chip ${row.scope === 'global' ? 'dt-chip-neutral' : 'dt-chip-accent'}">${esc(scopeLabel[row.scope])}</span>` +
        (row.target ? `<span class="ms-2 text-sm" dir="auto">${esc(row.target)}</span>` : ''),
    },
    {
      data: 'type',
      title: t.admin.ruleType,
      className: 'muted text-sm',
      priority: 3,
      render: (row) => esc(typeLabel[row.type]),
    },
    {
      data: 'value',
      title: t.admin.value,
      className: 'dt-num font-medium',
      priority: 1,
      render: (row) => esc(row.type === 'percentage' ? `${row.value}%` : row.value),
    },
    {
      data: 'accrual_trigger',
      title: t.admin.trigger,
      className: 'muted text-sm dt-ltr',
      priority: 4,
      render: (row) => esc(row.accrual_trigger),
    },
    {
      data: 'priority',
      title: t.admin.priority,
      className: 'dt-num muted',
      priority: 4,
      render: (row) => esc(row.priority),
    },
    {
      data: 'is_active',
      title: t.common.status,
      priority: 2,
      render: (row) =>
        `<button type="button" data-action="toggle" data-id="${row.id}" class="dt-chip ${row.is_active ? 'dt-chip-success' : 'dt-chip-neutral'}">${esc(
          row.is_active ? t.common.accept : t.common.cancel,
        )}</button>`,
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 1,
      // القاعدة العامّة لا تُحذف: حذفها يترك المنصة بلا عمولة افتراضية
      render: (row) =>
        row.scope === 'global'
          ? ''
          : `<button type="button" class="dt-btn dt-btn-danger" data-action="delete" data-id="${row.id}" data-confirm="${esc(t.admin.confirmDelete)}">${esc(t.common.delete)}</button>`,
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button size="sm" onClick={() => setOpen((v) => !v)}>
          <Plus size={15} />
          {t.admin.newRule}
        </Button>
      </div>

      {error && <p className="text-sm text-danger">{error}</p>}

      {open && (
        <Card>
          <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
            <Field label={t.admin.scope}>
              <Select name="scope" defaultValue="category">
                <option value="category">{t.admin.scopeCategory}</option>
                <option value="supplier">{t.admin.scopeSupplier}</option>
                <option value="plan">{t.admin.scopePlan}</option>
              </Select>
            </Field>

            <Field label="ID">
              <Input name="scope_id" type="number" min={1} className="tabular" required />
            </Field>

            <Field label={t.admin.ruleType}>
              <Select name="type" defaultValue="percentage">
                <option value="percentage">{t.admin.typePercentage}</option>
                <option value="fixed">{t.admin.typeFixed}</option>
              </Select>
            </Field>

            <Field label={t.admin.value}>
              <Input name="value" type="number" step="0.001" min="0" className="tabular" required />
            </Field>

            <Field label={t.admin.trigger}>
              <Select name="accrual_trigger" defaultValue="delivered">
                <option value="confirmed">confirmed</option>
                <option value="shipped">shipped</option>
                <option value="delivered">delivered</option>
              </Select>
            </Field>

            <Field label={t.admin.priority}>
              <Input name="priority" type="number" min={0} defaultValue={10} className="tabular" />
            </Field>

            <div className="sm:col-span-3 flex gap-3">
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

      <DataTable<CommissionRule>
        rows={data ?? []}
        exportName="commission-rules"
        columns={columns}
        order={['priority', 'desc']}
        paging={(data ?? []).length > 10}
        info={(data ?? []).length > 10}
        onAction={(action, id, _value, row) => {
          if (action === 'toggle' && row) update.mutate({ id: row.id, payload: { is_active: !row.is_active } })
          if (action === 'delete') remove.mutate(Number(id))
        }}
      />
    </div>
  )
}
