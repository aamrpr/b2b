import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Phone, Plus, Truck } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount, toMinor } from '@/lib/money'
import { Badge, Button, Card, ErrorState, Field, Input, Loading } from '@/components/ui'
import type { Carrier } from '@/types'
import { PhoneField } from '@/components/fields/PhoneField'

export function Carriers() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [quoteFor, setQuoteFor] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['carriers'],
    queryFn: async () => (await api.get<{ carriers: Carrier[] }>('/carriers')).data.carriers,
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['carriers'] })
    setOpen(false)
    setQuoteFor(null)
    setError(null)
  }

  const create = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => (await api.post('/carriers', payload)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const addRate = useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      (await api.post(`/carriers/${id}/rates`, payload)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button size="sm" onClick={() => setOpen((v) => !v)}>
          <Plus size={15} />
          {t.shipping.newCarrier}
        </Button>
      </div>

      {error && <p className="text-sm text-danger">{error}</p>}

      {open && (
        <Card>
          <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              create.mutate({
                name: { ar: form.get('name_ar'), en: form.get('name_en') || form.get('name_ar') },
                code: form.get('code'),
                phone: form.get('phone') || undefined,
                supports_cod: form.get('supports_cod') === 'on',
              })
            }}
          >
            <Field label={t.common.nameAr}>
              <Input name="name_ar" required />
            </Field>
            <Field label={t.common.nameEn}>
              <Input name="name_en" dir="ltr" />
            </Field>
            <Field label={t.inventory.code}>
              <Input name="code" dir="ltr" required />
            </Field>
            <Field label={t.auth.phone}>
              <PhoneField name="phone" />
            </Field>
            <label className="flex items-center gap-2 text-sm sm:col-span-2">
              <input type="checkbox" name="supports_cod" />
              {t.shipping.cod}
            </label>
            <div className="sm:col-span-2 flex gap-3">
              <Button type="submit" disabled={create.isPending}>
                {t.common.save}
              </Button>
              <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                {t.common.cancel}
              </Button>
            </div>
          </form>
        </Card>
      )}

      <div className="grid gap-4 md:grid-cols-2">
        {(data ?? []).map((carrier) => (
          <Card key={carrier.id}>
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="flex items-center gap-2">
                  <Truck size={16} className="text-accent" />
                  <span className="font-semibold">{carrier.name}</span>
                </div>
                {carrier.phone && (
                  <div className="muted text-sm mt-1 flex items-center gap-1 tabular" dir="ltr">
                    <Phone size={12} />
                    {carrier.phone}
                  </div>
                )}
              </div>

              <div className="flex flex-col items-end gap-1.5">
                <Badge tone={carrier.is_platform_wide ? 'brand' : 'neutral'}>
                  {carrier.is_platform_wide ? t.shipping.platformCarrier : carrier.code}
                </Badge>
                {carrier.supports_cod && <Badge tone="success">{t.shipping.cod}</Badge>}
              </div>
            </div>

            {carrier.coverage.length > 0 && (
              <div className="mt-3 text-sm">
                <span className="muted text-xs">{t.shipping.coverage}: </span>
                {carrier.coverage.join('، ')}
              </div>
            )}

            {carrier.rates.length > 0 && (
              <table className="w-full text-sm mt-4">
                <tbody>
                  {carrier.rates.slice(0, 4).map((rate) => (
                    <tr key={rate.id} className="border-t border-[var(--border)]">
                      <td className="py-2 muted">
                        {rate.from_city ?? '—'} ← {rate.to_city ?? t.common.all}
                      </td>
                      <td className="py-2 tabular text-xs muted">
                        {rate.min_weight_kg}–{rate.max_weight_kg ?? '∞'} kg
                      </td>
                      <td className="py-2 tabular text-end font-medium">
                        {formatAmount(rate.base_price, rate.currency, 2, locale)}
                      </td>
                      <td className="py-2 tabular text-end text-xs muted">
                        {rate.transit_days} {t.quotation.days}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}

            {! carrier.is_platform_wide && (
              <div className="mt-4 pt-4 border-t border-[var(--border)]">
                {quoteFor === carrier.id ? (
                  <form
                    className="grid gap-3 sm:grid-cols-2"
                    onSubmit={(event) => {
                      event.preventDefault()
                      const form = new FormData(event.currentTarget)
                      addRate.mutate({
                        id: carrier.id,
                        payload: {
                          from_city: form.get('from_city') || undefined,
                          to_city: form.get('to_city') || undefined,
                          base_price: toMinor(String(form.get('base_price') ?? '0')),
                          price_per_kg: toMinor(String(form.get('price_per_kg') ?? '0')),
                          transit_days: Number(form.get('transit_days') ?? 1),
                        },
                      })
                    }}
                  >
                    <Field label={t.shipping.origin}>
                      <Input name="from_city" />
                    </Field>
                    <Field label={t.shipping.destination}>
                      <Input name="to_city" />
                    </Field>
                    <Field label={t.shipping.shippingCost}>
                      <Input name="base_price" type="number" step="0.01" min="0" className="tabular" required />
                    </Field>
                    <Field label={`${t.shipping.weight} / kg`}>
                      <Input name="price_per_kg" type="number" step="0.01" min="0" className="tabular" />
                    </Field>
                    <div className="sm:col-span-2 flex gap-2">
                      <Button type="submit" size="sm" disabled={addRate.isPending}>
                        {t.common.save}
                      </Button>
                      <Button type="button" size="sm" variant="ghost" onClick={() => setQuoteFor(null)}>
                        {t.common.cancel}
                      </Button>
                    </div>
                  </form>
                ) : (
                  <Button size="sm" variant="ghost" onClick={() => setQuoteFor(carrier.id)}>
                    <Plus size={14} />
                    {t.shipping.quote}
                  </Button>
                )}
              </div>
            )}
          </Card>
        ))}
      </div>
    </div>
  )
}
