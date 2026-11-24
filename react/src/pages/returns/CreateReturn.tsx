import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { Button, Card, Empty, ErrorState, Field, Input, Loading, PageTitle, Select, Textarea } from '@/components/ui'
import type { ReturnDetail, ReturnableItems } from '@/types'
import { useUnits } from '@/lib/units'

export function CreateReturn() {
  const { orderId = '' } = useParams()
  const { t, locale } = useI18n()
  const { label: unitLabel } = useUnits()
  const navigate = useNavigate()
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [restock, setRestock] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['returnable', orderId],
    queryFn: async () => (await api.get<ReturnableItems>(`/orders/${orderId}/returnable`)).data,
  })

  // لا شيء مُحدَّد مسبقاً: المرتجع نادراً ما يشمل الطلب كله
  useEffect(() => {
    if (data) {
      setQuantities(Object.fromEntries(data.items.filter((i) => i.returnable > 0).map((i) => [i.order_item_id, ''])))
    }
  }, [data])

  const create = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<{ return: ReturnDetail }>(`/orders/${orderId}/returns`, payload)).data.return,
    onSuccess: (row) => navigate(`/returns/${row.id}`),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const returnable = data.items.filter((item) => item.returnable > 0)

  if (returnable.length === 0) {
    return <Empty message={t.returns.nothingReturnable} />
  }

  const reasons = t.returns.reasons as Record<string, string>

  const total = returnable.reduce(
    (sum, item) => sum + Number(quantities[item.order_item_id] || 0) * item.unit_price,
    0,
  )

  return (
    <div className="mx-auto max-w-3xl">
      <PageTitle title={t.returns.newReturn} subtitle={data.order_no} />

      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault()
          const form = new FormData(event.currentTarget)

          create.mutate({
            reason: form.get('reason'),
            description: form.get('description') || undefined,
            restock,
            items: Object.entries(quantities)
              .filter(([, value]) => Number(value) > 0)
              .map(([id, value]) => ({
                order_item_id: Number(id),
                quantity: Number(value),
                condition_note: form.get(`note_${id}`) || undefined,
              })),
          })
        }}
      >
        <Card className="space-y-4">
          <Field label={t.returns.reason}>
            <Select name="reason" defaultValue="damaged" required>
              {Object.entries(reasons).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </Select>
          </Field>

          <Field label={t.rfq.description}>
            <Textarea name="description" />
          </Field>

          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              className="mt-1"
              checked={restock}
              onChange={(e) => setRestock(e.target.checked)}
            />
            <span>
              {t.returns.restock}
              <span className="block muted text-xs mt-0.5">{t.returns.restockHint}</span>
            </span>
          </label>
        </Card>

        <Card>
          <h2 className="font-semibold mb-4">{t.rfq.items}</h2>
          <div className="space-y-3">
            {returnable.map((item) => (
              <div key={item.order_item_id} className="flex flex-wrap items-end gap-3">
                <div className="flex-1 min-w-44">
                  <Field label={t.rfq.itemDescription}>
                    <Input value={item.description} readOnly className="muted" />
                  </Field>
                </div>
                <div className="w-28">
                  <Field label={t.returns.returnable}>
                    <Input value={`${item.returnable} ${unitLabel(item.unit)}`} readOnly className="muted tabular" />
                  </Field>
                </div>
                <div className="w-24">
                  <Field label={t.common.quantity}>
                    <Input
                      type="number"
                      min={0}
                      max={item.returnable}
                      className="tabular"
                      value={quantities[item.order_item_id] ?? ''}
                      onChange={(e) =>
                        setQuantities((q) => ({ ...q, [item.order_item_id]: e.target.value }))
                      }
                    />
                  </Field>
                </div>
                <div className="flex-1 min-w-40">
                  <Field label={t.returns.conditionNote}>
                    <Input name={`note_${item.order_item_id}`} />
                  </Field>
                </div>
              </div>
            ))}
          </div>

          <div className="mt-4 pt-4 border-t border-[var(--border)] flex items-center justify-between">
            <span className="font-semibold">{t.common.total}</span>
            <span className="text-lg font-bold tabular text-accent">
              {formatAmount(total, data.currency, 2, locale)}
            </span>
          </div>
        </Card>

        {error && <p className="text-sm text-danger">{error}</p>}

        <Button type="submit" className="w-full" disabled={create.isPending || total === 0}>
          {create.isPending ? t.common.loading : t.returns.newReturn}
        </Button>
      </form>
    </div>
  )
}
