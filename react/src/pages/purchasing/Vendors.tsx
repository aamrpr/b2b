import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Mail, Phone, Plus, Factory, Power } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { Badge, Button, Card, Empty, ErrorState, Field, Input, Loading, Select } from '@/components/ui'
import type { Vendor } from '@/types'
import { PhoneField } from '@/components/fields/PhoneField'

const CURRENCIES = ['SYP', 'USD', 'EUR', 'TRY', 'AED']

export function Vendors() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<number | null>(null)
  const [includeInactive, setIncludeInactive] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['vendors', includeInactive],
    queryFn: async () =>
      (
        await api.get<{ vendors: Vendor[] }>('/vendors', {
          params: { include_inactive: includeInactive ? 1 : undefined },
        })
      ).data.vendors,
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['vendors'] })
    setOpen(false)
    setEditing(null)
    setError(null)
  }

  const create = useMutation({
    mutationFn: async (payload: Record<string, unknown>) => (await api.post('/vendors', payload)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const update = useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      (await api.put(`/vendors/${id}`, payload)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const deactivate = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/vendors/${id}`)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  const vendors = data ?? []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={includeInactive}
            onChange={(e) => setIncludeInactive(e.target.checked)}
          />
          {t.purchasing.showInactive}
        </label>

        <Button size="sm" onClick={() => { setEditing(null); setOpen((v) => !v) }}>
          <Plus size={15} />
          {t.purchasing.newVendor}
        </Button>
      </div>

      {error && <p className="text-sm text-danger">{error}</p>}

      {open && (
        <Card>
          <VendorForm
            onCancel={() => setOpen(false)}
            pending={create.isPending}
            onSubmit={(payload) => create.mutate(payload)}
          />
        </Card>
      )}

      {!vendors.length ? (
        <Empty message={t.purchasing.noVendors} />
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {vendors.map((vendor) => (
            <Card key={vendor.id}>
              {editing === vendor.id ? (
                <VendorForm
                  vendor={vendor}
                  pending={update.isPending}
                  onCancel={() => setEditing(null)}
                  onSubmit={(payload) => update.mutate({ id: vendor.id, payload })}
                />
              ) : (
                <>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <Factory size={16} className="text-accent shrink-0" />
                        <span className="font-semibold">{vendor.name}</span>
                      </div>

                      {vendor.contact_person && (
                        <div className="muted text-sm mt-1">{vendor.contact_person}</div>
                      )}

                      {vendor.phone && (
                        <div className="muted text-sm mt-1 flex items-center gap-1 tabular" dir="ltr">
                          <Phone size={12} />
                          {vendor.phone}
                        </div>
                      )}

                      {vendor.email && (
                        <div className="muted text-sm mt-1 flex items-center gap-1 break-all" dir="ltr">
                          <Mail size={12} />
                          {vendor.email}
                        </div>
                      )}
                    </div>

                    <div className="flex flex-col items-end gap-1.5 shrink-0">
                      <Badge tone="brand">{vendor.code}</Badge>
                      {!vendor.is_active && <Badge tone="warning">{t.purchasing.inactive}</Badge>}
                    </div>
                  </div>

                  <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div>
                      <dt className="muted text-xs">{t.purchasing.outstanding}</dt>
                      <dd className="tabular font-medium">
                        {formatAmount(vendor.outstanding, vendor.default_currency, 2, locale)}
                      </dd>
                    </div>
                    <div>
                      <dt className="muted text-xs">{t.purchasing.paymentTerms}</dt>
                      <dd className="tabular">
                        {vendor.payment_terms_days} {t.payments.days}
                      </dd>
                    </div>
                    <div>
                      <dt className="muted text-xs">{t.purchasing.ordersCount}</dt>
                      <dd className="tabular">{vendor.orders_count ?? 0}</dd>
                    </div>
                    <div>
                      <dt className="muted text-xs">{t.common.city}</dt>
                      <dd>
                        {[vendor.city, vendor.country_code].filter(Boolean).join(' · ') || '—'}
                      </dd>
                    </div>
                  </dl>

                  <div className="mt-4 pt-4 border-t border-[var(--border)] flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={() => { setOpen(false); setEditing(vendor.id) }}>
                      {t.purchasing.edit}
                    </Button>

                    {vendor.is_active && (
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={deactivate.isPending}
                        onClick={() => deactivate.mutate(vendor.id)}
                      >
                        <Power size={14} />
                        {t.purchasing.deactivate}
                      </Button>
                    )}
                  </div>
                </>
              )}
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

function VendorForm({
  vendor,
  pending,
  onSubmit,
  onCancel,
}: {
  vendor?: Vendor
  pending: boolean
  onSubmit: (payload: Record<string, unknown>) => void
  onCancel: () => void
}) {
  const { t } = useI18n()

  return (
    <form
      className="grid gap-4 sm:grid-cols-2"
      onSubmit={(event) => {
        event.preventDefault()
        const form = new FormData(event.currentTarget)

        onSubmit({
          name: form.get('name'),
          code: form.get('code'),
          contact_person: form.get('contact_person') || null,
          phone: form.get('phone') || null,
          email: form.get('email') || null,
          country_code: form.get('country_code') || null,
          city: form.get('city') || null,
          default_currency: form.get('default_currency'),
          payment_terms_days: Number(form.get('payment_terms_days') ?? 0),
          is_active: form.get('is_active') === 'on',
        })
      }}
    >
      <Field label={t.purchasing.vendor}>
        <Input name="name" defaultValue={vendor?.name} required />
      </Field>
      <Field label={t.purchasing.code}>
        <Input name="code" dir="ltr" defaultValue={vendor?.code} required />
      </Field>
      <Field label={t.purchasing.contactPerson}>
        <Input name="contact_person" defaultValue={vendor?.contact_person ?? ''} />
      </Field>
      <Field label={t.auth.phone}>
        <PhoneField name="phone" defaultValue={vendor?.phone ?? ''} />
      </Field>
      <Field label={t.auth.email}>
        <Input name="email" type="email" dir="ltr" defaultValue={vendor?.email ?? ''} />
      </Field>
      <Field label={t.common.city}>
        <Input name="city" defaultValue={vendor?.city ?? ''} />
      </Field>
      <Field label="ISO 3166">
        <Input
          name="country_code"
          dir="ltr"
          maxLength={2}
          placeholder="SY"
          className="tabular uppercase"
          defaultValue={vendor?.country_code ?? ''}
        />
      </Field>
      <Field label={t.purchasing.defaultCurrency}>
        <Select name="default_currency" defaultValue={vendor?.default_currency ?? 'USD'}>
          {CURRENCIES.map((currency) => (
            <option key={currency} value={currency}>
              {currency}
            </option>
          ))}
        </Select>
      </Field>
      <Field label={t.purchasing.paymentTerms} hint={t.purchasing.paymentTermsHint}>
        <Input
          name="payment_terms_days"
          type="number"
          min="0"
          max="365"
          className="tabular"
          defaultValue={vendor?.payment_terms_days ?? 0}
        />
      </Field>

      <label className="flex items-center gap-2 text-sm self-end pb-2.5">
        <input type="checkbox" name="is_active" defaultChecked={vendor?.is_active ?? true} />
        {t.purchasing.active}
      </label>

      <div className="sm:col-span-2 flex gap-3">
        <Button type="submit" disabled={pending}>
          {t.common.save}
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel}>
          {t.common.cancel}
        </Button>
      </div>
    </form>
  )
}
