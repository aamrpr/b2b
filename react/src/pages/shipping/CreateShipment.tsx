import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { toMinor } from '@/lib/money'
import { Button, Card, Empty, ErrorState, Field, Input, Loading, PageTitle } from '@/components/ui'
import type { Carrier, PendingShipmentItems, ShipmentDetail } from '@/types'
import { DateField } from '@/components/fields/DateField'
import { useUnits } from '@/lib/units'
import { SearchSelect } from '@/components/fields/SearchSelect'

export function CreateShipment() {
  const { orderId = '' } = useParams()
  const { t } = useI18n()
  const { label: unitLabel } = useUnits()
  const navigate = useNavigate()
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [error, setError] = useState<string | null>(null)

  const { data: pending, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['order-pending-shipment', orderId],
    queryFn: async () => (await api.get<PendingShipmentItems>(`/orders/${orderId}/shipments/pending`)).data,
  })

  const { data: carriers } = useQuery({
    queryKey: ['carriers'],
    queryFn: async () => (await api.get<{ carriers: Carrier[] }>('/carriers')).data.carriers,
  })

  // الافتراضي: شحن كل المتبقي
  useEffect(() => {
    if (pending) {
      setQuantities(
        Object.fromEntries(pending.items.filter((i) => i.pending > 0).map((i) => [i.order_item_id, String(i.pending)])),
      )
    }
  }, [pending])

  const create = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<{ shipment: ShipmentDetail }>(`/orders/${orderId}/shipments`, payload)).data.shipment,
    onSuccess: (shipment) => navigate(`/shipping/${shipment.id}`),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  if (isLoading || !pending) return <Loading message={t.common.loading} />

  const shippable = pending.items.filter((item) => item.pending > 0)

  if (shippable.length === 0) {
    return (
      <div className="mx-auto max-w-3xl">
        <PageTitle title={t.shipping.newShipment} subtitle={pending.order_no} back="/orders" />
        <Empty message={t.shipping.allShipped} />
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-3xl">
      <PageTitle title={t.shipping.newShipment} subtitle={pending.order_no} back="/orders" />

      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault()
          const form = new FormData(event.currentTarget)

          create.mutate({
            carrier_id: form.get('carrier_id') ? Number(form.get('carrier_id')) : undefined,
            tracking_number: form.get('tracking_number') || undefined,
            weight_kg: form.get('weight_kg') ? Number(form.get('weight_kg')) : undefined,
            packages_count: Number(form.get('packages_count') ?? 1),
            shipping_cost: toMinor(String(form.get('shipping_cost') ?? '0')),
            estimated_delivery: form.get('estimated_delivery') || undefined,
            origin_address: form.get('origin_city') ? { city: String(form.get('origin_city')) } : undefined,
            destination_address: form.get('destination_city')
              ? { city: String(form.get('destination_city')) }
              : undefined,
            notes: form.get('notes') || undefined,
            items: Object.entries(quantities)
              .filter(([, value]) => Number(value) > 0)
              .map(([id, value]) => ({ order_item_id: Number(id), quantity: Number(value) })),
          })
        }}
      >
        <Card>
          <h2 className="font-semibold mb-4">{t.shipping.pendingItems}</h2>
          <div className="space-y-3">
            {shippable.map((item) => (
              <div key={item.order_item_id} className="flex flex-wrap items-end gap-3">
                <div className="flex-1 min-w-48">
                  <Field label={t.rfq.itemDescription}>
                    <Input value={item.description} readOnly className="muted" />
                  </Field>
                </div>
                <div className="w-28">
                  <Field label={t.shipping.pendingItems}>
                    <Input value={`${item.pending} ${unitLabel(item.unit)}`} readOnly className="muted tabular" />
                  </Field>
                </div>
                <div className="w-28">
                  <Field label={t.common.quantity}>
                    <Input
                      type="number"
                      min={0}
                      max={item.pending}
                      className="tabular"
                      value={quantities[item.order_item_id] ?? ''}
                      onChange={(e) =>
                        setQuantities((q) => ({ ...q, [item.order_item_id]: e.target.value }))
                      }
                    />
                  </Field>
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card className="grid gap-4 sm:grid-cols-2">
          <Field label={t.shipping.carrier}>
            <SearchSelect
              name="carrier_id"
              isClearable
              options={(carriers ?? []).map((c) => ({ value: String(c.id), label: c.name }))}
            />
          </Field>

          <Field label={t.shipping.trackingNumber}>
            <Input name="tracking_number" dir="ltr" />
          </Field>

          <Field label={t.shipping.origin}>
            <Input name="origin_city" />
          </Field>

          <Field label={t.shipping.destination}>
            <Input name="destination_city" />
          </Field>

          <Field label={t.shipping.packages}>
            <Input name="packages_count" type="number" min={1} defaultValue={1} className="tabular" />
          </Field>

          <Field label={t.shipping.weight}>
            <Input name="weight_kg" type="number" step="0.001" min="0" className="tabular" />
          </Field>

          <Field label={t.shipping.shippingCost}>
            <Input name="shipping_cost" type="number" step="0.01" min="0" className="tabular" />
          </Field>

          <Field label={t.shipping.estimatedDelivery}>
            <DateField name="estimated_delivery" />
          </Field>

          <div className="sm:col-span-2">
            <Field label={t.quotation.notes}>
              <Input name="notes" />
            </Field>
          </div>
        </Card>

        {error && <p className="text-sm text-danger">{error}</p>}

        <Button type="submit" className="w-full" disabled={create.isPending}>
          {create.isPending ? t.common.loading : t.shipping.newShipment}
        </Button>
      </form>
    </div>
  )
}
