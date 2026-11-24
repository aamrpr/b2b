import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Button, Card } from '@/components/ui'
import type { CreditNoteRecord, OutstandingResponse } from '@/types'
import { SearchSelect } from '@/components/fields/SearchSelect'

export function CreditNotes() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [openOnly, setOpenOnly] = useState(true)
  const [targetInvoice, setTargetInvoice] = useState('')
  const [applying, setApplying] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const [reloadKey, setReloadKey] = useState(0)
  const [currency, setCurrency] = useState('SYP')

  const { data: outstanding } = useQuery({
    queryKey: ['outstanding', ''],
    queryFn: async () => (await api.get<OutstandingResponse>('/payments/outstanding')).data,
    enabled: applying !== null,
  })

  const apply = useMutation({
    mutationFn: async ({ id, invoiceId }: { id: number; invoiceId: number }) =>
      (await api.post(`/credit-notes/${id}/apply`, { invoice_id: invoiceId })).data,
    onSuccess: () => {
      setApplying(null)
      setError(null)
      setReloadKey((key) => key + 1)
      void queryClient.invalidateQueries({ queryKey: ['outstanding'] })
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const money = (value: number) => formatAmount(value, currency, 2, locale)

  const columns: DataTableColumn<CreditNoteRecord>[] = [
    {
      data: 'note_no',
      title: t.payments.noteNo,
      className: 'dt-num dt-ltr font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        esc(row.note_no) + `<div class="muted text-xs mt-0.5 dt-num">${esc(row.issue_date)}</div>`,
    },
    {
      data: 'invoice_no',
      title: t.accounting.entryNo,
      orderable: false,
      className: 'dt-num dt-ltr muted',
      priority: 3,
      filter: { type: 'text' },
      render: (row) =>
        esc(row.invoice_no ?? '\u2014') +
        (row.return_reference ? `<div class="text-xs">${esc(row.return_reference)}</div>` : ''),
    },
    {
      data: 'total',
      title: t.common.total,
      className: 'dt-num font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => esc(money(row.total)),
    },
    {
      data: 'applied_amount',
      title: t.payments.applied,
      className: 'dt-num muted',
      priority: 4,
      filter: { type: 'text' },
      render: (row) => esc(money(row.applied_amount)),
    },
    {
      data: 'unapplied_amount',
      title: t.payments.unapplied,
      orderable: false,
      className: 'dt-num',
      priority: 2,
      filter: { type: 'text' },
      render: (row) =>
        row.unapplied_amount > 0
          ? `<span class="dt-chip dt-chip-warning">${esc(money(row.unapplied_amount))}</span>`
          : '<span class="muted">\u2014</span>',
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 1,
      render: (row) =>
        row.unapplied_amount > 0
          ? `<button type="button" class="dt-btn" data-action="apply" data-id="${row.id}">${esc(t.payments.applyCredit)}</button>`
          : '',
    },
  ]

  return (
    <div className="space-y-4">
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={openOnly} onChange={(e) => setOpenOnly(e.target.checked)} />
        {t.payments.openOnly}
      </label>

      {error && <p className="text-sm text-danger">{error}</p>}

      <DataTable<CreditNoteRecord>
        url="/credit-notes"
        exportName="credit-notes"
        columns={columns}
        order={['id', 'desc']}
        reloadKey={reloadKey}
        params={{ open_only: openOnly ? 1 : undefined }}
        onResponse={(payload) => {
          if (typeof payload.currency === 'string') setCurrency(payload.currency)
        }}
        onAction={(action, id) => {
          if (action === 'apply') setApplying((current) => (current === Number(id) ? null : Number(id)))
        }}
      />

      {applying !== null && outstanding && (
        <Card className="flex flex-wrap items-end gap-3">
          <div className="min-w-56">
            <SearchSelect
              name="invoice_id"
              defaultValue={String(outstanding.invoices[0]?.id ?? '')}
              options={outstanding.invoices.map((invoice) => ({
                value: String(invoice.id),
                label: `${invoice.invoice_no} — ${money(invoice.balance_due)}`,
              }))}
              onChange={setTargetInvoice}
            />
          </div>

          <Button
            size="sm"
            disabled={apply.isPending || !outstanding.invoices.length}
            onClick={() => {
              const invoiceId = Number(targetInvoice || outstanding.invoices[0]?.id)

              if (invoiceId) apply.mutate({ id: applying, invoiceId })
            }}
          >
            {t.payments.applyCredit}
          </Button>

          <Button size="sm" variant="ghost" onClick={() => setApplying(null)}>
            {t.common.cancel}
          </Button>
        </Card>
      )}
    </div>
  )
}
