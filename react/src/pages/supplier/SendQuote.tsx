import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount, toMinor } from '@/lib/money'
import { Button, Card, ErrorState, Field, Input, Loading, PageTitle, Textarea } from '@/components/ui'
import type { Rfq } from '@/types'
import { useUnits } from '@/lib/units'

interface QuoteLine {
  rfq_item_id: number | null
  description: string
  quantity: number
  unit: string
  unit_price: string
}

export function SendQuote() {
  const { rfqId = '' } = useParams()
  const { t, locale } = useI18n()
  const { label: unitLabel } = useUnits()
  const navigate = useNavigate()

  const [lines, setLines] = useState<QuoteLine[]>([])
  const [leadTime, setLeadTime] = useState(7)
  const [paymentTerms, setPaymentTerms] = useState(0)
  const [shipping, setShipping] = useState('0')
  const [validity, setValidity] = useState(48)
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)

  const { data: rfq, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['rfq', rfqId],
    queryFn: async () => (await api.get<{ data: Rfq }>(`/rfqs/${rfqId}`)).data.data,
  })

  useEffect(() => {
    if (rfq?.items) {
      setLines(
        rfq.items.map((item) => ({
          rfq_item_id: item.id,
          description: item.description,
          quantity: item.quantity,
          unit: item.unit,
          unit_price: '',
        })),
      )
    }
  }, [rfq])

  const currency = rfq?.currency ?? 'SYP'
  const subtotal = lines.reduce((sum, line) => sum + toMinor(line.unit_price) * line.quantity, 0)
  const total = subtotal + toMinor(shipping)

  const mutation = useMutation({
    mutationFn: async () =>
      (
        await api.post(`/rfqs/${rfqId}/quotations`, {
          currency,
          shipping_amount: toMinor(shipping),
          lead_time_days: leadTime,
          payment_terms_days: paymentTerms,
          validity_hours: validity,
          notes: notes || undefined,
          items: lines.map((line) => ({
            rfq_item_id: line.rfq_item_id,
            description: line.description,
            quantity: line.quantity,
            unit: line.unit,
            unit_price: toMinor(line.unit_price),
          })),
        })
      ).data,
    onSuccess: () => navigate('/my/quotations'),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  return (
    <div className="mx-auto max-w-3xl">
      <PageTitle title={t.rfq.sendQuote} subtitle={rfq?.title} />

      <form
        onSubmit={(e) => {
          e.preventDefault()
          mutation.mutate()
        }}
        className="space-y-5"
      >
        <Card>
          <h2 className="font-semibold mb-4">{t.rfq.items}</h2>
          <div className="space-y-3">
            {lines.map((line, index) => (
              <div key={index} className="flex flex-wrap items-end gap-3">
                <div className="flex-1 min-w-48">
                  <Field label={t.rfq.product}>
                    {rfq?.items?.[index]?.product ? (
                      <Link
                        to={`/products/${rfq.items[index].product.slug}`}
                        className="flex min-h-11 items-center text-sm font-medium hover:text-accent"
                      >
                        {rfq.items[index].product.name}
                      </Link>
                    ) : (
                      <Input value={line.description} readOnly className="muted" />
                    )}
                  </Field>
                </div>
                <div className="w-24">
                  <Field label={t.common.quantity}>
                    <Input value={`${line.quantity} ${unitLabel(line.unit)}`} readOnly className="muted tabular" />
                  </Field>
                </div>
                <div className="w-40">
                  <Field label={t.common.unitPrice}>
                    <Input
                      type="number"
                      step="0.01"
                      min="0"
                      className="tabular"
                      value={line.unit_price}
                      onChange={(e) =>
                        setLines((current) =>
                          current.map((l, i) => (i === index ? { ...l, unit_price: e.target.value } : l)),
                        )
                      }
                      required
                    />
                  </Field>
                </div>
                <div className="w-36 pb-2.5 text-sm tabular font-medium">
                  {formatAmount(toMinor(line.unit_price) * line.quantity, currency, 2, locale)}
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card className="grid gap-4 sm:grid-cols-2">
          <Field label={`${t.quotation.leadTime} (${t.quotation.days})`}>
            <Input type="number" min={0} value={leadTime} onChange={(e) => setLeadTime(Number(e.target.value))} />
          </Field>

          <Field label={`${t.quotation.paymentTerms} (${t.quotation.days})`}>
            <Input type="number" min={0} value={paymentTerms} onChange={(e) => setPaymentTerms(Number(e.target.value))} />
          </Field>

          <Field label={t.quotation.validUntil + ` (${t.rfq.hours})`}>
            <Input type="number" min={1} value={validity} onChange={(e) => setValidity(Number(e.target.value))} />
          </Field>

          <Field label={t.common.shipping}>
            <Input type="number" step="0.01" min="0" className="tabular" value={shipping} onChange={(e) => setShipping(e.target.value)} />
          </Field>

          <div className="sm:col-span-2">
            <Field label={t.quotation.notes}>
              <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} />
            </Field>
          </div>
        </Card>

        <Card className="flex items-center justify-between">
          <span className="font-semibold">{t.common.total}</span>
          <span className="text-xl font-bold tabular text-accent">{formatAmount(total, currency, 2, locale)}</span>
        </Card>

        {error && <p className="text-sm text-danger">{error}</p>}

        <Button type="submit" className="w-full" disabled={mutation.isPending}>
          {mutation.isPending ? t.common.loading : t.common.send}
        </Button>
      </form>
    </div>
  )
}
