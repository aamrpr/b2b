import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BadgeCheck, Ban, Check, X } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Badge, Button, Card, Empty, ErrorState, Loading, Pager, Select, Textarea } from '@/components/ui'
import type { AdminSupplier, Paged } from '@/types'
import { SupplierLogo } from '@/components/SupplierLogo'

export function AdminSuppliers() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('pending')
  const [rejecting, setRejecting] = useState<number | null>(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['admin-suppliers', status, page],
    queryFn: async () =>
      (await api.get<Paged<AdminSupplier>>('/admin/suppliers', { params: { status, page } })).data,
  })

  const rows = data?.data ?? []

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['admin-suppliers'] })
    void queryClient.invalidateQueries({ queryKey: ['admin-overview'] })
  }

  const verify = useMutation({
    mutationFn: async (id: number) => (await api.post(`/admin/suppliers/${id}/verify`)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const reject = useMutation({
    mutationFn: async (id: number) => (await api.post(`/admin/suppliers/${id}/reject`, { reason })).data,
    onSuccess: () => {
      setRejecting(null)
      setReason('')
      refresh()
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const suspend = useMutation({
    mutationFn: async (id: number) => (await api.post(`/admin/suppliers/${id}/suspend`)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const statusTone = (value: string) =>
    value === 'verified' ? 'success' : value === 'rejected' ? 'danger' : 'warning'

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Select aria-label={t.common.status} className="max-w-xs" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="pending">{t.admin.statusPending}</option>
          <option value="verified">{t.admin.statusVerified}</option>
          <option value="rejected">{t.admin.statusRejected}</option>
          <option value="all">{t.admin.statusAll}</option>
        </Select>
        {data?.meta && <span className="muted text-sm tabular">{data.meta.total}</span>}
      </div>

      {error && <p className="text-sm text-danger">{error}</p>}

      {isError ? (
        <ErrorState error={queryError} onRetry={() => void refetch()} />
      ) : isLoading ? (
        <Loading message={t.common.loading} />
      ) : !rows.length ? (
        <Empty message={status === 'pending' ? t.admin.noPending : t.common.empty} />
      ) : (
        <div className="space-y-3">
          {rows.map((supplier) => (
            <Card key={supplier.id}>
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex min-w-0 items-start gap-3">
                  <SupplierLogo name={supplier.profile?.display_name ?? supplier.name} size="sm" />
                  <div className="min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-semibold">{supplier.profile?.display_name ?? supplier.name}</span>
                    {supplier.profile && (
                      <Badge tone={statusTone(supplier.profile.verification_status)}>
                        {supplier.profile.verification_status}
                      </Badge>
                    )}
                    {supplier.status === 'suspended' && <Badge tone="danger">{t.admin.suspend}</Badge>}
                  </div>

                  <dl className="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    {[
                      [t.admin.legalName, supplier.profile?.legal_name],
                      [t.admin.commercialRegister, supplier.profile?.commercial_register_no],
                      [t.admin.taxNumber, supplier.profile?.tax_number],
                      [t.auth.phone, supplier.profile?.phone],
                      [t.auth.city, supplier.city],
                      [t.common.currency, supplier.base_currency],
                    ].map(([label, value]) => (
                      <div key={String(label)} className="flex gap-2">
                        <dt className="muted">{label}:</dt>
                        <dd className="truncate tabular">{value || '—'}</dd>
                      </div>
                    ))}
                  </dl>

                  {supplier.categories.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 mt-3">
                      {supplier.categories.map((category) => (
                        <Badge key={category.id} tone={category.is_primary ? 'brand' : 'neutral'}>
                          {category.name}
                        </Badge>
                      ))}
                    </div>
                  )}

                  {supplier.profile?.rejection_reason && (
                    <p className="text-sm text-danger mt-3">{supplier.profile.rejection_reason}</p>
                  )}
                  </div>
                </div>

                <div className="flex flex-col gap-2 shrink-0">
                  {supplier.profile?.verification_status !== 'verified' && (
                    <Button size="sm" disabled={verify.isPending} onClick={() => verify.mutate(supplier.id)}>
                      <BadgeCheck size={15} />
                      {t.admin.verify}
                    </Button>
                  )}

                  {supplier.profile?.verification_status !== 'rejected' && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => setRejecting(rejecting === supplier.id ? null : supplier.id)}
                    >
                      <X size={15} />
                      {t.admin.reject}
                    </Button>
                  )}

                  <Button size="sm" variant="ghost" onClick={() => suspend.mutate(supplier.id)}>
                    <Ban size={15} />
                    {supplier.status === 'suspended' ? t.admin.reactivate : t.admin.suspend}
                  </Button>
                </div>
              </div>

              {rejecting === supplier.id && (
                <div className="mt-4 pt-4 border-t border-[var(--border)] space-y-3">
                  <Textarea
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    placeholder={t.admin.rejectReason}
                  />
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="danger"
                      disabled={!reason.trim() || reject.isPending}
                      onClick={() => reject.mutate(supplier.id)}
                    >
                      <Check size={15} />
                      {t.admin.confirmReject}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setRejecting(null)}>
                      {t.common.cancel}
                    </Button>
                  </div>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      <Pager meta={data?.meta} page={page} onChange={setPage} />
    </div>
  )
}
