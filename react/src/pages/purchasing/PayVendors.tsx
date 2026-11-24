import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Wand2, X } from 'lucide-react'
import clsx from 'clsx'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount, toMinor } from '@/lib/money'
import { Badge, Button, Card, Empty, ErrorState, Field, Input, Loading, Select, Stat } from '@/components/ui'
import type { OutstandingResponse, Vendor } from '@/types'
import { DateField } from '@/components/fields/DateField'

/**
 * صرف للمورّدين.
 * فواتير الشراء تحمل المورّد في `contact_id`، لكنّ أسماء الأطراف في
 * نقطة المستحقّات تُجلب من المستأجرين — فالاسم يُوصَل هنا من دفتر
 * المورّدين. والسند لا يُرسل بـ`contact_id`: الخادم ينسبه إلى مورّد
 * الفواتير التي وُزِّع عليها.
 */
export function PayVendors() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()

  const [vendorId, setVendorId] = useState('')
  const [amount, setAmount] = useState('')
  const [allocations, setAllocations] = useState<Record<number, string>>({})
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['outstanding', 'purchase'],
    queryFn: async () =>
      (await api.get<OutstandingResponse>('/payments/outstanding', { params: { type: 'purchase' } })).data,
  })

  const { data: vendors } = useQuery({
    queryKey: ['vendors', true],
    queryFn: async () =>
      (await api.get<{ vendors: Vendor[] }>('/vendors', { params: { include_inactive: 1 } })).data.vendors,
  })

  const names = useMemo(
    () => new Map((vendors ?? []).map((vendor) => [vendor.id, vendor.name])),
    [vendors],
  )

  const pay = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<{ message: string }>('/payments/pay', payload)).data,
    onSuccess: (result) => {
      setMessage(result.message)
      setError(null)
      setAmount('')
      setAllocations({})
      void queryClient.invalidateQueries({ queryKey: ['outstanding'] })
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      void queryClient.invalidateQueries({ queryKey: ['vendors'] })
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const invoices = useMemo(
    () => (data?.invoices ?? []).filter((invoice) => !vendorId || String(invoice.contact_id) === vendorId),
    [data, vendorId],
  )

  const paidMinor = toMinor(amount || '0')
  const allocatedMinor = Object.values(allocations).reduce((sum, value) => sum + toMinor(value || '0'), 0)
  const unallocated = paidMinor - allocatedMinor
  const overAllocated = unallocated < 0

  /** يوزّع المبلغ المصروف على الفواتير بدءاً بالأقدم استحقاقاً. */
  function autoAllocate() {
    let remaining = paidMinor
    const next: Record<number, string> = {}

    for (const invoice of invoices) {
      if (remaining <= 0) break

      const take = Math.min(remaining, invoice.balance_due)
      next[invoice.id] = String(take / 100)
      remaining -= take
    }

    setAllocations(next)
  }

  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const money = (value: number) => formatAmount(value, data.currency, 2, locale)
  const methods = t.payments.methods as Record<string, string>
  const buckets = t.payments.buckets as Record<string, string>

  return (
    <div className="space-y-5">
      {message && <div className="card p-4 text-sm text-success">{message}</div>}
      {error && <div className="card p-4 text-sm text-danger">{error}</div>}

      {/* min-w-0 على عنصر الشبكة: بدونه يفرض عرضُ الجدول الأدنى تمدّدَ
          العمود فتخرج الصفحة عن عرض الهاتف بدل أن يتمرّر الجدول وحده. */}
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <div className="space-y-4 min-w-0">
          <Card className="flex flex-wrap items-end gap-4">
            <div className="min-w-56">
              <Field label={t.purchasing.vendor}>
                <Select
                  value={vendorId}
                  onChange={(e) => {
                    setVendorId(e.target.value)
                    setAllocations({})
                  }}
                >
                  <option value="">{t.common.all}</option>
                  {data.customers.map((party) => (
                    <option key={party.id} value={party.id}>
                      {names.get(party.id) ?? party.name ?? party.id}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>
          </Card>

          {!invoices.length ? (
            <Empty message={t.purchasing.nothingDue} />
          ) : (
            <Card className="p-0 overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-[var(--border)]">
                    {[
                      t.purchasing.invoiceNo,
                      ...(vendorId ? [] : [t.purchasing.vendor]),
                      t.payments.dueDate,
                      t.payments.balanceDue,
                      t.payments.age,
                      t.payments.allocate,
                    ].map((head) => (
                      <th key={head} className="text-start font-medium muted px-4 py-3 whitespace-nowrap">
                        {head}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {invoices.map((invoice) => (
                    <tr key={invoice.id} className="border-b border-[var(--border)] last:border-0">
                      <td className="px-4 py-2.5 tabular font-medium">{invoice.invoice_no}</td>
                      {!vendorId && (
                        <td className="px-4 py-2.5">
                          {invoice.contact_id ? names.get(invoice.contact_id) ?? '—' : '—'}
                        </td>
                      )}
                      <td className="px-4 py-2.5 tabular muted">{invoice.due_date}</td>
                      <td className="px-4 py-2.5 tabular font-medium">{money(invoice.balance_due)}</td>
                      <td className="px-4 py-2.5">
                        <Badge tone={invoice.bucket === 'current' ? 'neutral' : 'danger'}>
                          {buckets[invoice.bucket] ?? invoice.bucket}
                        </Badge>
                      </td>
                      <td className="px-4 py-2.5">
                        <Input
                          type="number"
                          step="0.01"
                          min="0"
                          max={invoice.balance_due / 100}
                          className="tabular max-w-32"
                          value={allocations[invoice.id] ?? ''}
                          onChange={(e) =>
                            setAllocations((current) => ({ ...current, [invoice.id]: e.target.value }))
                          }
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          )}
        </div>

        <form
          className="space-y-4"
          onSubmit={(event) => {
            event.preventDefault()
            setError(null)
            setMessage(null)

            const form = new FormData(event.currentTarget)

            pay.mutate({
              method: form.get('method'),
              amount: paidMinor,
              payment_date: form.get('payment_date') || undefined,
              gateway_reference: form.get('reference') || undefined,
              allocations: Object.entries(allocations)
                .filter(([, value]) => toMinor(value || '0') > 0)
                .map(([id, value]) => ({ invoice_id: Number(id), amount: toMinor(value) })),
            })
          }}
        >
          <Card className="space-y-4">
            <Field label={t.purchasing.amountPaid}>
              <Input
                type="number"
                step="0.01"
                min="0.01"
                className="tabular text-lg"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                required
              />
            </Field>

            <Field label={t.payments.method}>
              <Select name="method" defaultValue="bank">
                {Object.entries(methods).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label={t.payments.paymentDate}>
              <DateField name="payment_date" />
            </Field>

            <Field label={t.payments.reference}>
              <Input name="reference" dir="ltr" />
            </Field>
          </Card>

          <Card className="space-y-3">
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="flex-1"
                disabled={paidMinor <= 0 || !invoices.length}
                onClick={autoAllocate}
              >
                <Wand2 size={15} />
                {t.payments.autoAllocate}
              </Button>

              <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-label={t.payments.clearAllocation}
                onClick={() => setAllocations({})}
              >
                <X size={15} />
              </Button>
            </div>

            <dl className="space-y-1.5 text-sm">
              <div className="flex justify-between gap-2">
                <dt className="muted">{t.payments.allocated}</dt>
                <dd className="tabular font-medium">{money(allocatedMinor)}</dd>
              </div>
              <div className="flex justify-between gap-2">
                <dt className="muted">
                  {overAllocated ? t.payments.overAllocated : t.payments.unallocated}
                </dt>
                <dd
                  className={clsx(
                    'tabular font-medium',
                    overAllocated ? 'text-danger' : unallocated > 0 ? 'text-warning' : 'muted',
                  )}
                >
                  {money(Math.abs(unallocated))}
                </dd>
              </div>
            </dl>

            {unallocated > 0 && !overAllocated && (
              <p className="muted text-xs">{t.purchasing.advanceHint}</p>
            )}

            <Button type="submit" className="w-full" disabled={pay.isPending || paidMinor <= 0 || overAllocated}>
              <Check size={16} />
              {pay.isPending ? t.common.loading : t.payments.recordPayment}
            </Button>
          </Card>

          <div className="grid gap-3 sm:grid-cols-2">
            <Stat label={t.purchasing.outstanding} value={money(invoices.reduce((s, i) => s + i.balance_due, 0))} />
            <Stat
              label={t.payments.overdue}
              value={money(invoices.filter((i) => i.bucket !== 'current').reduce((s, i) => s + i.balance_due, 0))}
              tone="danger"
            />
          </div>
        </form>
      </div>
    </div>
  )
}
