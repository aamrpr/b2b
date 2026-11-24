import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Package, Phone, Search } from 'lucide-react'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatDateTime } from '@/lib/date'
import { ShipmentTimeline } from '@/components/ShipmentTimeline'
import { Badge, Button, Card, Input, Loading, PageTitle } from '@/components/ui'
import type { PublicTracking } from '@/types'

/** صفحة تتبّع عامة — تُفتح بلا حساب، ويُشارَك رابطها مع المشتري أو السائق. */
export function TrackShipment() {
  const { token } = useParams()
  const { t, locale } = useI18n()
  const navigate = useNavigate()
  const [code, setCode] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['tracking', token],
    queryFn: async () => (await api.get<PublicTracking>(`/track/${token}`)).data,
    enabled: Boolean(token),
    retry: false,
  })

  const statuses = t.shipping.statuses as Record<string, string>

  if (!token) {
    return (
      <div className="mx-auto max-w-md">
        <PageTitle title={t.shipping.trackTitle} />
        <Card>
          <form
            className="flex gap-2"
            onSubmit={(event) => {
              event.preventDefault()
              if (code.trim()) navigate(`/track/${code.trim()}`)
            }}
          >
            <Input
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder={t.shipping.trackPlaceholder}
              dir="ltr"
            />
            <Button type="submit" aria-label={t.common.search}>
              <Search size={16} />
            </Button>
          </form>
        </Card>
      </div>
    )
  }

  if (isLoading) return <Loading message={t.common.loading} />

  if (isError || !data) {
    return (
      <div className="mx-auto max-w-md">
        <Card className="text-center py-12">
          <Package size={32} className="mx-auto muted" />
          <p className="muted mt-4">{t.shipping.notFound}</p>
        </Card>
      </div>
    )
  }

  const { shipment, events } = data

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      <PageTitle title={t.shipping.trackTitle} subtitle={shipment.shipment_no} />

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Badge tone={shipment.status === 'delivered' ? 'success' : 'brand'}>
            {statuses[shipment.status] ?? shipment.status}
          </Badge>

          {shipment.carrier && (
            <div className="text-sm">
              <span className="muted">{t.shipping.carrier}: </span>
              {shipment.carrier}
              {shipment.carrier_phone && (
                <a href={`tel:${shipment.carrier_phone}`} className="ms-2 inline-flex items-center gap-1 text-accent" dir="ltr">
                  <Phone size={12} />
                  {shipment.carrier_phone}
                </a>
              )}
            </div>
          )}
        </div>

        <dl className="grid gap-x-6 gap-y-2 mt-5 text-sm sm:grid-cols-2">
          {[
            [t.shipping.trackingNumber, shipment.tracking_number],
            [t.shipping.destination, shipment.destination_city],
            [t.shipping.packages, shipment.packages_count],
            [t.shipping.estimatedDelivery, shipment.estimated_delivery],
            [t.shipping.receivedBy, shipment.received_by],
          ]
            .filter(([, value]) => value)
            .map(([label, value]) => (
              <div key={String(label)} className="flex gap-2">
                <dt className="muted">{label}:</dt>
                <dd className="tabular">{String(value)}</dd>
              </div>
            ))}
        </dl>

        {shipment.delivered_at && (
          <p className="muted text-sm mt-4 tabular">
            {formatDateTime(shipment.delivered_at, locale)}
          </p>
        )}
      </Card>

      <Card>
        <h3 className="font-semibold mb-4">{t.shipping.timeline}</h3>
        <ShipmentTimeline events={events} />
      </Card>
    </div>
  )
}
