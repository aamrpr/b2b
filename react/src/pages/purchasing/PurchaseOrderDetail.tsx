import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { Check, PackageCheck, Receipt, Send, X } from 'lucide-react'
import clsx from 'clsx'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { Badge, Button, Card, ErrorState, Field, Input, Loading, PageTitle, Stat, Textarea } from '@/components/ui'
import type { PurchaseOrderDetail as Detail, PurchaseOrderItem, StockWarehouse } from '@/types'
import { DateField } from '@/components/fields/DateField'
import { useUnits } from '@/lib/units'
import { SearchSelect } from '@/components/fields/SearchSelect'

const TONE: Record<string, 'success' | 'warning' | 'danger' | 'brand' | 'neutral'> = {
  draft: 'neutral',
  sent: 'brand',
  partially_received: 'warning',
  received: 'success',
  closed: 'neutral',
  cancelled: 'danger',
}

type Mode = 'none' | 'receive' | 'bill'

/**
 * أمر شراء واحد بمطابقته الثلاثية.
 * المطلوب والمستلَم والمفوتَر ثلاثة أعمدة لكل بند، فالفرق بينها هو
 * ما يُستلَم وما يُفوتَر لاحقاً — والفوترة محدودة بما استُلم فعلاً.
 */
export function PurchaseOrderDetail() {
  const { orderId = '' } = useParams()
  const { t, locale } = useI18n()
  const { label: unitLabel } = useUnits()
  const queryClient = useQueryClient()
  const [mode, setMode] = useState<Mode>('none')
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['purchase-order', orderId],
    queryFn: async () => (await api.get<{ order: Detail }>(`/purchase-orders/${orderId}`)).data.order,
  })

  const { data: warehouses } = useQuery({
    queryKey: ['warehouses'],
    queryFn: async () => (await api.get<{ warehouses: StockWarehouse[] }>('/inventory/warehouses')).data.warehouses,
  })

  function refresh(text?: string) {
    void queryClient.invalidateQueries({ queryKey: ['purchase-order', orderId] })
    void queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
    void queryClient.invalidateQueries({ queryKey: ['vendors'] })
    void queryClient.invalidateQueries({ queryKey: ['outstanding'] })
    setMode('none')
    setQuantities({})
    setError(null)
    setMessage(text ?? null)
  }

  const send = useMutation({
    mutationFn: async () =>
      (await api.post<{ message: string }>(`/purchase-orders/${orderId}/send`)).data,
    onSuccess: (result) => refresh(result.message),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const receive = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<{ message: string }>(`/purchase-orders/${orderId}/receive`, payload)).data,
    onSuccess: (result) => refresh(result.message),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const bill = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<{ message: string }>(`/purchase-orders/${orderId}/bill`, payload)).data,
    onSuccess: (result) => refresh(result.message),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  /** يفتح لوح الاستلام أو الفوترة بالكميات المتبقية جاهزةً للتعديل. */
  function openPanel(next: Mode, items: PurchaseOrderItem[]) {
    setError(null)
    setMessage(null)

    if (next === mode) {
      setMode('none')
      return
    }

    setQuantities(
      Object.fromEntries(
        items.map((item) => [
          item.id,
          String(next === 'receive' ? item.pending_receipt : item.pending_invoice),
        ]),
      ),
    )
    setMode(next)
  }

  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const statuses = t.purchasing.statuses as Record<string, string>
  const money = (value: number) => formatAmount(value, data.currency, 2, locale)

  const canSend = data.allowed_transitions.includes('sent')
  const canReceive = ['sent', 'partially_received'].includes(data.status)
  const pendingReceipt = data.items.reduce((sum, item) => sum + item.pending_receipt, 0)
  const pendingInvoice = data.items.reduce((sum, item) => sum + item.pending_invoice, 0)
  const canBill = data.items.some((item) => item.received_quantity > 0)

  const lines = Object.entries(quantities)
    .map(([id, value]) => ({ purchase_order_item_id: Number(id), quantity: Number(value || 0) }))
    .filter((line) => line.quantity > 0)

  return (
    <div className="space-y-5">
      <PageTitle title={t.purchasing.title} subtitle={data.reference_no} back="/purchasing" />
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              <h2 className="text-lg font-bold tabular">{data.reference_no}</h2>
              <Badge tone={TONE[data.status] ?? 'brand'}>{statuses[data.status] ?? data.status}</Badge>
              {data.incoterm && <Badge tone="neutral">{data.incoterm}</Badge>}
            </div>

            <div className="muted text-sm mt-1.5">
              <Link to="/purchasing/vendors" className="hover:text-accent">
                {data.vendor.name}
              </Link>
              {' · '}
              <span className="tabular">{data.order_date}</span>
              {data.expected_date && <span className="tabular"> → {data.expected_date}</span>}
              {data.warehouse.name && ` · ${data.warehouse.name}`}
            </div>

            {data.notes && <p className="muted text-sm mt-2">{data.notes}</p>}
          </div>

          <div className="flex flex-wrap gap-2">
            {canSend && (
              <Button size="sm" disabled={send.isPending} onClick={() => send.mutate()}>
                <Send size={15} />
                {t.purchasing.send}
              </Button>
            )}

            {canReceive && (
              <Button
                size="sm"
                variant={mode === 'receive' ? 'primary' : 'outline'}
                disabled={pendingReceipt === 0}
                onClick={() => openPanel('receive', data.items)}
              >
                <PackageCheck size={15} />
                {t.purchasing.receive}
              </Button>
            )}

            {canBill && (
              <Button
                size="sm"
                variant={mode === 'bill' ? 'primary' : 'outline'}
                onClick={() => openPanel('bill', data.items)}
              >
                <Receipt size={15} />
                {t.purchasing.bill}
              </Button>
            )}
          </div>
        </div>

        {data.status === 'draft' && <p className="muted text-sm mt-3">{t.purchasing.sendFirst}</p>}
        {canReceive && pendingReceipt === 0 && (
          <p className="muted text-sm mt-3">{t.purchasing.nothingToReceive}</p>
        )}
        {message && <p className="mt-3 text-sm text-success">{message}</p>}
        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      </Card>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label={t.purchasing.subtotal} value={money(data.subtotal)} />
        <Stat label={t.purchasing.tax} value={money(data.tax_amount)} />
        <Stat label={t.purchasing.shipping} value={money(data.shipping_amount)} />
        <Stat label={t.common.total} value={money(data.total)} />
      </div>

      <Card className="p-0">
        <div className="px-4 pt-4 flex flex-wrap items-center justify-between gap-2">
          <h3 className="font-semibold text-sm">{t.purchasing.threeWayMatch}</h3>
          <span className="muted text-xs">{t.purchasing.threeWayHint}</span>
        </div>

        <div className="overflow-x-auto mt-2">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-[var(--border)]">
                {[
                  t.purchasing.description,
                  t.purchasing.ordered,
                  t.purchasing.receivedQty,
                  t.purchasing.invoicedQty,
                  t.purchasing.unitCost,
                  t.common.total,
                  ...(mode === 'none'
                    ? []
                    : [mode === 'receive' ? t.purchasing.receiveQuantity : t.purchasing.billQuantity]),
                ].map((head) => (
                  <th key={head} className="text-start font-medium muted px-4 py-3 whitespace-nowrap">
                    {head}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.items.map((item) => (
                <tr key={item.id} className="border-b border-[var(--border)] last:border-0">
                  <td className="px-4 py-3 min-w-40">
                    {item.description}
                    <div className="muted text-xs mt-0.5 tabular">{unitLabel(item.unit)}</div>
                  </td>
                  <td className="px-4 py-3 tabular font-medium">{item.quantity}</td>
                  <td className="px-4 py-3 tabular">
                    <span className={clsx(item.received_quantity > 0 && 'text-success font-medium')}>
                      {item.received_quantity}
                    </span>
                    {item.pending_receipt > 0 && (
                      <div className="muted text-xs mt-0.5">
                        {t.purchasing.pendingReceipt}: {item.pending_receipt}
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-3 tabular">
                    <span className={clsx(item.invoiced_quantity > 0 && 'font-medium')}>
                      {item.invoiced_quantity}
                    </span>
                    {item.pending_invoice > 0 && (
                      <div className="text-warning text-xs mt-0.5">
                        {t.purchasing.pendingInvoice}: {item.pending_invoice}
                      </div>
                    )}
                  </td>
                  <td className="px-4 py-3 tabular muted">{money(item.unit_cost)}</td>
                  <td className="px-4 py-3 tabular">{money(item.line_total)}</td>
                  {mode !== 'none' && (
                    <td className="px-4 py-3">
                      <Input
                        type="number"
                        min={0}
                        max={mode === 'receive' ? item.pending_receipt : item.quantity - item.invoiced_quantity}
                        className="tabular w-24"
                        value={quantities[item.id] ?? '0'}
                        onChange={(e) => setQuantities((q) => ({ ...q, [item.id]: e.target.value }))}
                      />
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {mode === 'receive' && (
          <form
            className="p-4 border-t border-[var(--border)] space-y-4"
            onSubmit={(event) => {
              event.preventDefault()
              setError(null)

              const form = new FormData(event.currentTarget)

              receive.mutate({
                received_date: form.get('received_date') || undefined,
                vendor_note_no: form.get('vendor_note_no') || undefined,
                warehouse_id: form.get('warehouse_id') ? Number(form.get('warehouse_id')) : undefined,
                notes: form.get('notes') || undefined,
                items: lines,
              })
            }}
          >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <Field label={t.purchasing.receivedDate}>
                <DateField name="received_date" />
              </Field>
              <Field label={t.purchasing.vendorNoteNo}>
                <Input name="vendor_note_no" dir="ltr" />
              </Field>
              <Field label={t.purchasing.warehouse}>
                <SearchSelect
                    name="warehouse_id"
                    options={(warehouses ?? []).map((warehouse) => ({
                      value: String(warehouse.id),
                      label: warehouse.name,
                    }))}
                  />
              </Field>
            </div>

            <Field label={t.quotation.notes}>
              <Textarea name="notes" />
            </Field>

            <div className="flex flex-wrap gap-3">
              <Button type="submit" disabled={receive.isPending || lines.length === 0}>
                <Check size={16} />
                {t.purchasing.confirmReceive}
              </Button>
              <Button type="button" variant="ghost" onClick={() => setMode('none')}>
                <X size={16} />
                {t.common.cancel}
              </Button>
            </div>
          </form>
        )}

        {mode === 'bill' && (
          <form
            className="p-4 border-t border-[var(--border)] space-y-4"
            onSubmit={(event) => {
              event.preventDefault()
              setError(null)

              const form = new FormData(event.currentTarget)

              bill.mutate({
                issue_date: form.get('issue_date') || undefined,
                due_date: form.get('due_date') || undefined,
                vendor_invoice_no: form.get('vendor_invoice_no') || undefined,
                items: lines,
              })
            }}
          >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <Field label={t.purchasing.vendorInvoiceNo}>
                <Input name="vendor_invoice_no" dir="ltr" />
              </Field>
              <Field label={t.purchasing.issueDate}>
                <DateField name="issue_date" />
              </Field>
              <Field label={t.purchasing.dueDate}>
                <DateField name="due_date" />
              </Field>
            </div>

            {pendingInvoice === 0 && <p className="muted text-sm">{t.purchasing.nothingToBill}</p>}

            <div className="flex flex-wrap gap-3">
              <Button type="submit" disabled={bill.isPending || lines.length === 0}>
                <Check size={16} />
                {t.purchasing.confirmBill}
              </Button>
              <Button type="button" variant="ghost" onClick={() => setMode('none')}>
                <X size={16} />
                {t.common.cancel}
              </Button>
            </div>
          </form>
        )}
      </Card>

      {data.receipts.length > 0 && (
        <Card className="p-0">
          <h3 className="font-semibold text-sm px-4 pt-4">{t.purchasing.receipts}</h3>

          <div className="overflow-x-auto mt-2">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-[var(--border)]">
                  {[
                    t.purchasing.receiptNo,
                    t.purchasing.receivedDate,
                    t.purchasing.vendorNoteNo,
                    t.purchasing.receiptLines,
                    t.purchasing.receiptValue,
                  ].map((head) => (
                    <th key={head} className="text-start font-medium muted px-4 py-3 whitespace-nowrap">
                      {head}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.receipts.map((receipt) => (
                  <tr key={receipt.id} className="border-b border-[var(--border)] last:border-0">
                    <td className="px-4 py-3 tabular font-medium">{receipt.reference_no}</td>
                    <td className="px-4 py-3 tabular muted">{receipt.received_date}</td>
                    <td className="px-4 py-3 tabular muted" dir="ltr">
                      {receipt.vendor_note_no ?? '—'}
                    </td>
                    <td className="px-4 py-3 tabular">{receipt.items_count}</td>
                    <td className="px-4 py-3 tabular">{money(receipt.total_cost)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  )
}
