import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Check, FileText, Wand2, X } from 'lucide-react'
import clsx from 'clsx'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount, toMinor } from '@/lib/money'
import { Badge, Button, Card, Empty, ErrorState, Field, Input, Loading, Select, Stat } from '@/components/ui'
import type { OutstandingResponse } from '@/types'
import { DateField } from '@/components/fields/DateField'

/**
 * شاشة التحصيل.
 * المحاسب يُدخل المبلغ المقبوض ثم يوزّعه — والتوزيع التلقائي يبدأ
 * بالأقدم استحقاقاً، وهو ما يفعله أي محاسب يدوياً على أي حال.
 */
export function Collect() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()

  const [customerId, setCustomerId] = useState<string>('')
  const [amount, setAmount] = useState('')
  const [fee, setFee] = useState('')
  const [allocations, setAllocations] = useState<Record<number, string>>({})
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['outstanding', customerId],
    queryFn: async () =>
      (
        await api.get<OutstandingResponse>('/payments/outstanding', {
          params: { contact_id: customerId || undefined },
        })
      ).data,
  })

  const receive = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => (await api.post('/payments/receive', payload)).data,
    onSuccess: (result: { message: string }) => {
      setMessage(result.message)
      setAmount('')
      setFee('')
      setAllocations({})
      void queryClient.invalidateQueries({ queryKey: ['outstanding'] })
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      setTimeout(() => setMessage(null), 4000)
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const invoices = useMemo(
    () => (data?.invoices ?? []).filter((invoice) => !customerId || String(invoice.contact_id) === customerId),
    [data, customerId],
  )

  const receivedMinor = toMinor(amount || '0')
  const allocatedMinor = Object.values(allocations).reduce((sum, value) => sum + toMinor(value || '0'), 0)
  const unallocated = receivedMinor - allocatedMinor
  const overAllocated = unallocated < 0

  /** يوزّع المبلغ المقبوض على الفواتير بدءاً بالأقدم استحقاقاً. */
  function autoAllocate() {
    let remaining = receivedMinor
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

      {/* min-w-0 على عنصر الشبكة: بدونه يأخذ العنصر أصغرَ عرضٍ لمحتواه،
          وجدول الفواتير برؤوسه غير المكسورة يتجاوز 500px، فيتمدّد العمود
          ويخرج عن عرض الشاشة على الهاتف بدل أن يتمرّر الجدول وحده. */}
      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <div className="space-y-4 min-w-0">
          <Card className="flex flex-wrap items-end gap-4">
            <div className="min-w-56">
              <Field label={t.payments.customer}>
                <Select
                  value={customerId}
                  onChange={(e) => {
                    setCustomerId(e.target.value)
                    setAllocations({})
                  }}
                >
                  <option value="">{t.common.all}</option>
                  {data.customers.map((customer) => (
                    <option key={customer.id} value={customer.id}>
                      {customer.name}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>

            {customerId && (
              <Link to={`/payments/statement/${customerId}`}>
                <Button variant="outline" size="sm">
                  <FileText size={15} />
                  {t.payments.statement}
                </Button>
              </Link>
            )}

            {data.available_credit > 0 && (
              <Badge tone="success">
                {t.payments.availableCredit}: {money(data.available_credit)}
              </Badge>
            )}
          </Card>

          {!invoices.length ? (
            <Empty message={t.payments.nothingOutstanding} />
          ) : (
            <Card className="p-0 overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-[var(--border)]">
                    {[
                      t.accounting.entryNo,
                      ...(customerId ? [] : [t.payments.customer]),
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
                      {!customerId && <td className="px-4 py-2.5 text-sm">{invoice.contact_name}</td>}
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

            const form = new FormData(event.currentTarget)

            receive.mutate({
              contact_id: customerId ? Number(customerId) : undefined,
              method: form.get('method'),
              amount: receivedMinor,
              fee_amount: toMinor(fee || '0'),
              payment_date: form.get('payment_date') || undefined,
              gateway_reference: form.get('reference') || undefined,
              allocations: Object.entries(allocations)
                .filter(([, value]) => toMinor(value || '0') > 0)
                .map(([id, value]) => ({ invoice_id: Number(id), amount: toMinor(value) })),
            })
          }}
        >
          <Card className="space-y-4">
            <Field label={t.payments.amount}>
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
              <Select name="method" defaultValue="cash">
                {Object.entries(methods).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label={t.payments.fee} hint={t.payments.feeHint}>
              <Input
                type="number"
                step="0.01"
                min="0"
                className="tabular"
                value={fee}
                onChange={(e) => setFee(e.target.value)}
              />
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
                disabled={receivedMinor <= 0 || !invoices.length}
                onClick={autoAllocate}
              >
                <Wand2 size={15} />
                {t.payments.autoAllocate}
              </Button>

              <Button type="button" variant="ghost" size="sm" onClick={() => setAllocations({})}>
                <X size={15} />
              </Button>
            </div>

            <dl className="space-y-1.5 text-sm">
              <div className="flex justify-between gap-2">
                <dt className="muted">{t.payments.allocated}</dt>
                <dd className="tabular font-medium">{money(allocatedMinor)}</dd>
              </div>
              <div className="flex justify-between gap-2">
                <dt className="muted">{overAllocated ? t.payments.overAllocated : t.payments.advance}</dt>
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
              <p className="muted text-xs">{t.payments.advanceHint}</p>
            )}

            <Button
              type="submit"
              className="w-full"
              disabled={receive.isPending || receivedMinor <= 0 || overAllocated}
            >
              <Check size={16} />
              {receive.isPending ? t.common.loading : t.payments.recordReceipt}
            </Button>
          </Card>

          <div className="grid gap-3 sm:grid-cols-2">
            <Stat label={t.payments.outstanding} value={money(invoices.reduce((s, i) => s + i.balance_due, 0))} />
            <Stat
              label={t.payments.overdue}
              value={money(
                invoices.filter((i) => i.bucket !== 'current').reduce((s, i) => s + i.balance_due, 0),
              )}
              tone="danger"
            />
          </div>
        </form>
      </div>
    </div>
  )
}
