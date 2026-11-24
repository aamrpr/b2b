import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { Check, ImagePlus, Scale, Truck, X } from 'lucide-react'
import clsx from 'clsx'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { Badge, Button, Card, ErrorState, Field, Input, Loading, PageTitle, Stat, Textarea } from '@/components/ui'
import type { ReturnDetail as Detail } from '@/types'

export function ReturnDetail() {
  const { returnId = '' } = useParams()
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [rejecting, setRejecting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['return', returnId],
    queryFn: async () => (await api.get<{ return: Detail }>(`/returns/${returnId}`)).data.return,
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['return', returnId] })
    void queryClient.invalidateQueries({ queryKey: ['returns'] })
    void queryClient.invalidateQueries({ queryKey: ['orders'] })
    setQuantities({})
    setRejecting(false)
    setError(null)
  }

  function act(path: string, payload?: Record<string, unknown>) {
    return async () => {
      try {
        await api.post(`/returns/${returnId}/${path}`, payload)
        refresh()
      } catch (err) {
        setError(errorMessage(err, t.common.error))
      }
    }
  }

  const approve = useMutation({ mutationFn: act('approve', { quantities: numeric(quantities) }), onSuccess: refresh })
  const receive = useMutation({ mutationFn: act('receive', { quantities: numeric(quantities) }), onSuccess: refresh })
  const inTransit = useMutation({ mutationFn: act('in-transit'), onSuccess: refresh })
  const complete = useMutation({ mutationFn: act('complete'), onSuccess: refresh })

  const upload = useMutation({
    mutationFn: async (form: FormData) => (await api.post(`/returns/${returnId}/photos`, form)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const statuses = t.returns.statuses as Record<string, string>
  const reasons = t.returns.reasons as Record<string, string>
  const money = (value: number) => formatAmount(value, data.currency, 2, locale)

  const allowed = data.allowed_transitions
  const isBuyer = data.is_mine
  const canDecide = ! isBuyer && allowed.includes('approved')
  const canSend = isBuyer && allowed.includes('in_transit')
  const canReceive = ! isBuyer && allowed.includes('received')
  const canComplete = ! isBuyer && allowed.includes('completed')

  // الكمية المعروضة في حقل الإدخال: ما اعتُمد، أو ما طُلب إن لم يُبتّ بعد
  const defaultFor = (item: Detail['items'][number]) =>
    quantities[item.id] ?? String(canReceive ? item.approved_quantity || item.quantity : item.quantity)

  return (
    <div className="space-y-5">
      <PageTitle title={t.returns.title} subtitle={data.reference_no} back="/returns" />
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2 flex-wrap">
              <h2 className="text-lg font-bold tabular">{data.reference_no}</h2>
              <Badge tone={data.status === 'completed' ? 'success' : data.status === 'rejected' ? 'danger' : 'brand'}>
                {statuses[data.status] ?? data.status}
              </Badge>
              {! data.restock && <Badge tone="warning">{t.returns.restockHint}</Badge>}
            </div>

            <div className="muted text-sm mt-1.5">
              <Link to={`/orders`} className="tabular hover:text-accent">
                {data.order.order_no}
              </Link>
              {' · '}
              {data.counterpart}
              {' · '}
              {reasons[data.reason] ?? data.reason}
            </div>

            {data.description && <p className="muted text-sm mt-2">{data.description}</p>}
            {data.decision_note && <p className="text-sm mt-2">{data.decision_note}</p>}
          </div>

          <div className="flex flex-wrap gap-2">
            {canSend && (
              <Button size="sm" disabled={inTransit.isPending} onClick={() => inTransit.mutate()}>
                <Truck size={15} />
                {t.returns.markInTransit}
              </Button>
            )}

            {canComplete && (
              <Button size="sm" disabled={complete.isPending} onClick={() => complete.mutate()}>
                <Check size={15} />
                {t.returns.completeAction}
              </Button>
            )}

            {data.status !== 'completed' && (
              <Link to={`/returns/disputes/new/${data.order.id}?return=${data.id}`}>
                <Button size="sm" variant="ghost">
                  <Scale size={15} />
                  {t.disputes.newDispute}
                </Button>
              </Link>
            )}
          </div>
        </div>

        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      </Card>

      <div className="grid gap-3 sm:grid-cols-3">
        <Stat label={t.returns.requestedAmount} value={money(data.requested_amount)} />
        <Stat label={t.returns.approvedAmount} value={money(data.approved_amount)} />
        <Stat
          label={t.returns.receivedValue}
          value={money(data.received_value)}
          tone={data.received_value > 0 ? 'success' : undefined}
        />
      </div>

      {data.credit_note && (
        <Card className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <span className="muted text-sm">{t.returns.creditNote}: </span>
            <span className="font-semibold tabular">{data.credit_note.note_no}</span>
          </div>
          <span className="text-lg font-bold tabular text-success">{money(data.credit_note.total)}</span>
        </Card>
      )}

      <Card className="p-0 overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-[var(--border)]">
              {[
                t.rfq.itemDescription,
                t.returns.requested,
                t.returns.approved,
                t.returns.received,
                t.common.unitPrice,
                ...(canDecide || canReceive ? [t.common.quantity] : []),
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
                <td className="px-4 py-3">
                  {item.description}
                  {item.condition_note && <div className="muted text-xs mt-0.5">{item.condition_note}</div>}
                </td>
                <td className="px-4 py-3 tabular">{item.quantity}</td>
                <td className="px-4 py-3 tabular">{item.approved_quantity || '—'}</td>
                <td
                  className={clsx(
                    'px-4 py-3 tabular',
                    item.received_quantity > 0 && 'font-medium text-success',
                  )}
                >
                  {item.received_quantity || '—'}
                </td>
                <td className="px-4 py-3 tabular muted">{money(item.unit_price)}</td>
                {(canDecide || canReceive) && (
                  <td className="px-4 py-3">
                    <Input
                      type="number"
                      min={0}
                      max={canReceive ? item.approved_quantity || item.quantity : item.quantity}
                      className="tabular max-w-24"
                      value={defaultFor(item)}
                      onChange={(e) => setQuantities((q) => ({ ...q, [item.id]: e.target.value }))}
                    />
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>

        {(canDecide || canReceive) && (
          <div className="p-4 border-t border-[var(--border)] flex flex-wrap gap-3">
            {canDecide && (
              <>
                <Button size="sm" disabled={approve.isPending} onClick={() => approve.mutate()}>
                  <Check size={15} />
                  {t.returns.approve}
                </Button>
                <Button size="sm" variant="outline" onClick={() => setRejecting((v) => !v)}>
                  <X size={15} />
                  {t.returns.rejectAction}
                </Button>
              </>
            )}

            {canReceive && (
              <Button size="sm" disabled={receive.isPending} onClick={() => receive.mutate()}>
                <Check size={15} />
                {t.returns.receiveAction}
              </Button>
            )}
          </div>
        )}

        {rejecting && (
          <form
            className="p-4 border-t border-[var(--border)] flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              void act('reject', { reason: form.get('reason') })()
            }}
          >
            <div className="flex-1 min-w-48">
              <Field label={t.returns.rejectReason}>
                <Textarea name="reason" required />
              </Field>
            </div>
            <Button type="submit" variant="danger" size="sm">
              {t.admin.confirmReject}
            </Button>
          </form>
        )}
      </Card>

      <Card>
        <div className="flex items-center justify-between mb-3">
          <h3 className="font-semibold">{t.returns.photos}</h3>
          <label className="cursor-pointer text-sm text-accent inline-flex items-center gap-1.5">
            <input
              type="file"
              accept="image/*"
              className="hidden"
              onChange={(event) => {
                const file = event.target.files?.[0]
                if (!file) return
                const form = new FormData()
                form.append('photo', file)
                upload.mutate(form)
                event.target.value = ''
              }}
            />
            <ImagePlus size={15} />
            {t.returns.addPhoto}
          </label>
        </div>

        {data.photos.length === 0 ? (
          <p className="muted text-sm">{t.common.empty}</p>
        ) : (
          <div className="flex flex-wrap gap-3">
            {data.photos.map((photo) => (
              <a key={photo.id} href={photo.url} target="_blank" rel="noreferrer">
                <img
                  src={photo.url}
                  alt=""
                  className="h-24 w-24 rounded-lg object-cover border border-[var(--border)]"
                />
              </a>
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}

function numeric(values: Record<number, string>): Record<number, number> {
  return Object.fromEntries(Object.entries(values).map(([k, v]) => [Number(k), Number(v || 0)]))
}
