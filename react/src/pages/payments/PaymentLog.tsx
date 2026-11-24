import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount, toMinor } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Button, Card, Input } from '@/components/ui'
import type { OutstandingResponse, PaymentRecord } from '@/types'

export function PaymentLog() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [allocating, setAllocating] = useState<number | null>(null)
  const [amounts, setAmounts] = useState<Record<number, string>>({})
  const [error, setError] = useState<string | null>(null)

  const [reloadKey, setReloadKey] = useState(0)
  const [currency, setCurrency] = useState('SYP')

  const { data: outstanding } = useQuery({
    queryKey: ['outstanding', ''],
    queryFn: async () => (await api.get<OutstandingResponse>('/payments/outstanding')).data,
    enabled: allocating !== null,
  })

  const allocate = useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      (await api.post(`/payments/${id}/allocate`, payload)).data,
    onSuccess: () => {
      setAllocating(null)
      setAmounts({})
      setError(null)
      setReloadKey((key) => key + 1)
      void queryClient.invalidateQueries({ queryKey: ['outstanding'] })
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const money = (value: number) => formatAmount(value, currency, 2, locale)
  const methods = t.payments.methods as Record<string, string>

  const columns: DataTableColumn<PaymentRecord>[] = [
    {
      data: 'payment_no',
      title: t.payments.paymentNo,
      className: 'dt-num dt-ltr font-medium',
      priority: 1,
      filter: { type: 'text' },
      // التوزيعات تحت الرقم: الدفعة وما ذهبت إليه يُقرآن معاً
      render: (row) =>
        esc(row.payment_no) +
        (row.allocations.length > 0
          ? '<div class="muted text-xs mt-1 dt-num">' +
            row.allocations
              .map((a) => `<div>${esc(a.invoice_no)} · ${esc(money(a.amount))}</div>`)
              .join('') +
            '</div>'
          : ''),
    },
    {
      data: 'direction',
      title: t.payments.direction,
      priority: 2,
      filter: {
        type: 'select',
        options: [
          { value: 'in', label: t.payments.directionIn },
          { value: 'out', label: t.payments.directionOut },
        ],
      },
      render: (row) =>
        `<span class="dt-chip ${row.direction === 'in' ? 'dt-chip-success' : 'dt-chip-warning'}">${esc(
          row.direction === 'in' ? t.payments.directionIn : t.payments.directionOut,
        )}</span>`,
    },
    {
      data: 'method',
      title: t.payments.method,
      className: 'muted text-sm',
      priority: 4,
      filter: {
        type: 'select',
        options: Object.entries(methods).map(([value, label]) => ({ value, label })),
      },
      render: (row) => esc(methods[row.method] ?? row.method),
    },
    {
      data: 'amount',
      title: t.payments.amount,
      className: 'dt-num font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        esc(money(row.amount)) +
        (row.fee_amount > 0
          ? `<div class="muted text-xs dt-num">${esc(t.payments.fee)}: ${esc(money(row.fee_amount))}</div>`
          : ''),
    },
    {
      data: 'allocated_amount',
      title: t.payments.allocate,
      orderable: false,
      className: 'dt-num',
      priority: 3,
      filter: { type: 'text' },
      render: (row) =>
        esc(money(row.allocated_amount)) +
        // ما بقي مقدَّماً ليس خطأً: دفعةٌ على الحساب قبل أن تصدر فاتورتها
        (row.unallocated_amount > 0
          ? `<div class="text-xs dt-num text-warning">${esc(t.payments.advance)}: ${esc(money(row.unallocated_amount))}</div>`
          : ''),
    },
    {
      data: 'payment_date',
      title: t.common.date,
      className: 'dt-num muted',
      priority: 3,
      filter: { type: 'dates' },
      render: (row) => esc(row.payment_date ?? ''),
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 2,
      render: (row) =>
        row.direction === 'in' && row.unallocated_amount > 0
          ? `<button type="button" class="dt-btn" data-action="allocate" data-id="${row.id}">${esc(t.payments.allocate)}</button>`
          : '',
    },
  ]

  return (
    <div className="space-y-4">

      {error && <p className="text-sm text-danger">{error}</p>}

      <DataTable<PaymentRecord>
        url="/payments"
        exportName="payments"
        columns={columns}
        order={['payment_date', 'desc']}
        reloadKey={reloadKey}
        emptyMessage={t.payments.empty}
        onResponse={(payload) => {
          if (typeof payload.currency === 'string') setCurrency(payload.currency)
        }}
        onAction={(action, id) => {
          if (action === 'allocate') setAllocating((current) => (current === Number(id) ? null : Number(id)))
        }}
      />

      {allocating !== null && outstanding && (
        <Card>
          <h3 className="font-semibold mb-4">{t.payments.allocate}</h3>

          <div className="space-y-3">
            {outstanding.invoices.map((invoice) => (
              <div key={invoice.id} className="flex flex-wrap items-center gap-3 text-sm">
                <span className="tabular font-medium min-w-36">{invoice.invoice_no}</span>
                <span className="muted tabular">{money(invoice.balance_due)}</span>
                <Input
                  type="number"
                  step="0.01"
                  min="0"
                  max={invoice.balance_due / 100}
                  className="tabular max-w-32 ms-auto"
                  value={amounts[invoice.id] ?? ''}
                  onChange={(e) => setAmounts((c) => ({ ...c, [invoice.id]: e.target.value }))}
                />
              </div>
            ))}
          </div>

          <div className="flex gap-2 mt-4">
            <Button
              size="sm"
              disabled={allocate.isPending}
              onClick={() =>
                allocate.mutate({
                  id: allocating,
                  payload: {
                    allocations: Object.entries(amounts)
                      .filter(([, value]) => toMinor(value || '0') > 0)
                      .map(([id, value]) => ({ invoice_id: Number(id), amount: toMinor(value) })),
                  },
                })
              }
            >
              {t.common.save}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setAllocating(null)}>
              {t.common.cancel}
            </Button>
          </div>
        </Card>
      )}
    </div>
  )
}
