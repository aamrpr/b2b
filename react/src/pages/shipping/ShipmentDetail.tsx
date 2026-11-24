import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { Copy, ExternalLink, FileUp, Plus } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { ShipmentTimeline } from '@/components/ShipmentTimeline'
import { useAuth } from '@/store/auth'
import { Badge, Button, Card, ErrorState, Field, Input, Loading, PageTitle, Select, Stat } from '@/components/ui'
import type { ShipmentDetail as Detail } from '@/types'

export function ShipmentDetail() {
  const { shipmentId = '' } = useParams()
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const [panel, setPanel] = useState<'event' | null>(null)
  const [copied, setCopied] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['shipment', shipmentId],
    queryFn: async () => (await api.get<{ shipment: Detail }>(`/shipments/${shipmentId}`)).data.shipment,
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['shipment', shipmentId] })
    void queryClient.invalidateQueries({ queryKey: ['shipments'] })
    void queryClient.invalidateQueries({ queryKey: ['orders'] })
    setPanel(null)
    setError(null)
  }

  const transition = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.patch(`/shipments/${shipmentId}/status`, payload)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const addEvent = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post(`/shipments/${shipmentId}/events`, payload)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const upload = useMutation({
    mutationFn: async (form: FormData) => (await api.post(`/shipments/${shipmentId}/documents`, form)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const statuses = t.shipping.statuses as Record<string, string>
  const docTypes = t.shipping.docTypes as Record<string, string>
  const isOwner = user?.tenant?.id !== undefined && data.allowed_transitions.length > 0
  const trackUrl = `${window.location.origin}/track/${data.tracking_token}`

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(trackUrl)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      /* الحافظة محجوبة — الرابط ظاهر للنسخ يدوياً */
    }
  }

  return (
    <div className="space-y-5">
      <PageTitle title={t.shipping.title} subtitle={data.shipment_no} back="/shipping" />
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2 flex-wrap">
              <h2 className="text-lg font-bold tabular">{data.shipment_no}</h2>
              <Badge tone={data.status === 'delivered' ? 'success' : 'brand'}>
                {statuses[data.status] ?? data.status}
              </Badge>
            </div>
            <div className="muted text-sm mt-1 tabular">
              {data.order.order_no} · {data.carrier ?? '—'}
              {data.tracking_number && (
                <span dir="ltr">
                  {' · '}
                  {data.tracking_number}
                </span>
              )}
            </div>
          </div>

          <div className="flex flex-wrap gap-2">
            {data.carrier_tracking_url && (
              <a href={data.carrier_tracking_url} target="_blank" rel="noreferrer">
                <Button size="sm" variant="outline">
                  <ExternalLink size={15} />
                  {t.shipping.carrier}
                </Button>
              </a>
            )}
            <Button size="sm" variant="outline" onClick={copyLink}>
              <Copy size={15} />
              {copied ? t.shipping.copied : t.shipping.copyLink}
            </Button>
          </div>
        </div>

        {data.allowed_transitions.length > 0 && (
          <div className="mt-4 pt-4 border-t border-[var(--border)] flex flex-wrap items-end gap-3">
            <Field label={t.common.status}>
              <Select id="next-status" className="min-w-44" defaultValue={data.allowed_transitions[0]}>
                {data.allowed_transitions.map((next) => (
                  <option key={next} value={next}>
                    {statuses[next] ?? next}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label={t.shipping.location}>
              <Input id="next-location" className="min-w-40" />
            </Field>

            <Button
              disabled={transition.isPending}
              onClick={() => {
                const status = (document.getElementById('next-status') as HTMLSelectElement)?.value
                const location = (document.getElementById('next-location') as HTMLInputElement)?.value
                const receivedBy =
                  status === 'delivered'
                    ? (document.getElementById('received-by') as HTMLInputElement)?.value
                    : undefined
                transition.mutate({ status, location: location || undefined, received_by: receivedBy || undefined })
              }}
            >
              {t.common.save}
            </Button>

            {data.allowed_transitions.includes('delivered') && (
              <Field label={t.shipping.receivedBy}>
                <Input id="received-by" className="min-w-40" />
              </Field>
            )}
          </div>
        )}

        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      </Card>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label={t.shipping.packages} value={String(data.packages_count)} />
        <Stat label={t.shipping.weight} value={data.weight_kg ? String(data.weight_kg) : '—'} />
        <Stat label={t.shipping.shippingCost} value={formatAmount(data.shipping_cost, data.currency, 2, locale)} />
        <Stat
          label={t.shipping.estimatedDelivery}
          value={data.estimated_delivery ?? '—'}
        />
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <Card>
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-semibold">{t.shipping.timeline}</h3>
            {isOwner && (
              <Button size="sm" variant="ghost" onClick={() => setPanel(panel === 'event' ? null : 'event')}>
                <Plus size={15} />
                {t.shipping.addEvent}
              </Button>
            )}
          </div>

          {panel === 'event' && (
            <form
              className="mb-5 pb-5 border-b border-[var(--border)] grid gap-3 sm:grid-cols-2"
              onSubmit={(event) => {
                event.preventDefault()
                const form = new FormData(event.currentTarget)
                addEvent.mutate({
                  location: form.get('location') || undefined,
                  description: { ar: form.get('description_ar'), en: form.get('description_en') || undefined },
                })
              }}
            >
              <Field label={t.shipping.location}>
                <Input name="location" />
              </Field>
              <Field label={t.common.descriptionAr}>
                <Input name="description_ar" required />
              </Field>
              <div className="sm:col-span-2">
                <Field label={t.common.descriptionEn}>
                  <Input name="description_en" dir="ltr" />
                </Field>
              </div>
              <div className="sm:col-span-2">
                <Button type="submit" size="sm" disabled={addEvent.isPending}>
                  {t.common.save}
                </Button>
              </div>
            </form>
          )}

          <ShipmentTimeline events={data.events} />
        </Card>

        <div className="space-y-5">
          <Card>
            <h3 className="font-semibold mb-3">{t.rfq.items}</h3>
            <ul className="space-y-1.5 text-sm">
              {data.items.map((line) => (
                <li key={line.id} className="flex justify-between gap-3">
                  <span className="muted">{line.description}</span>
                  <span className="tabular font-medium">{line.quantity}</span>
                </li>
              ))}
            </ul>

            {data.destination_address && (
              <div className="mt-4 pt-4 border-t border-[var(--border)] text-sm">
                <span className="muted">{t.shipping.destination}: </span>
                {Object.values(data.destination_address).filter(Boolean).join('، ')}
              </div>
            )}

            {data.received_by && (
              <div className="mt-2 text-sm">
                <span className="muted">{t.shipping.receivedBy}: </span>
                {data.received_by}
              </div>
            )}
          </Card>

          <Card>
            <div className="flex items-center justify-between mb-3">
              <h3 className="font-semibold">{t.shipping.documents}</h3>
              {isOwner && (
                <label className="cursor-pointer">
                  <input
                    type="file"
                    className="hidden"
                    onChange={(event) => {
                      const file = event.target.files?.[0]
                      if (!file) return
                      const form = new FormData()
                      form.append('type', 'other')
                      form.append('file', file)
                      upload.mutate(form)
                      event.target.value = ''
                    }}
                  />
                  <span className="inline-flex items-center gap-1.5 text-sm text-accent">
                    <FileUp size={15} />
                    {t.shipping.uploadDocument}
                  </span>
                </label>
              )}
            </div>

            {data.documents.length === 0 ? (
              <p className="muted text-sm">{t.common.empty}</p>
            ) : (
              <ul className="space-y-2 text-sm">
                {data.documents.map((document) => (
                  <li key={document.id}>
                    <a
                      href={document.url}
                      target="_blank"
                      rel="noreferrer"
                      className="inline-flex flex-wrap items-baseline gap-2 text-accent hover:underline"
                    >
                      {docTypes[document.type] ?? document.type}
                      {/* اسم الملفّ لاتينيّ: الفجوة بالصفّ المرن لا بالحاشية كي لا يلتصق */}
                      {document.original_name && (
                        <span className="muted" dir="ltr">
                          {document.original_name}
                        </span>
                      )}
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}
