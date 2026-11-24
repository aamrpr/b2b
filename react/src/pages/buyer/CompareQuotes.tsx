import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { Check, FileText, Star } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatMoney } from '@/lib/money'
import { Badge, Button, Card, Empty, ErrorState, Loading, PageTitle } from '@/components/ui'
import type { Quotation } from '@/types'
import { useState } from 'react'
import { SupplierLogo } from '@/components/SupplierLogo'

/** جدول مقارنة العروض — ما يحسم به تاجر الجملة قراره. */
export function CompareQuotes() {
  const { rfqId = '' } = useParams()
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['rfq-quotations', rfqId],
    queryFn: async () => (await api.get<{ data: Quotation[] }>(`/rfqs/${rfqId}/quotations`)).data.data,
  })

  const accept = useMutation({
    mutationFn: async (quotationId: number) => (await api.post(`/quotations/${quotationId}/accept`)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['rfq-quotations', rfqId] })
      queryClient.invalidateQueries({ queryKey: ['orders'] })
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />
  if (!data?.length) return <Empty message={t.rfq.noQuotes} />

  const cheapest = Math.min(...data.map((q) => q.total.amount))
  const fastest = Math.min(...data.map((q) => q.lead_time_days))

  return (
    <div>
      <PageTitle title={t.rfq.compare} subtitle={`${data.length}`} />

      {error && <div className="card p-4 mb-4 text-sm text-danger">{error}</div>}

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {data.map((quotation) => (
          <Card key={quotation.id} className="flex flex-col">
            <div className="flex items-start justify-between gap-2">
              <div className="flex min-w-0 items-center gap-2.5">
                {quotation.supplier && (
                  <SupplierLogo src={quotation.supplier.logo_url} name={quotation.supplier.name} size="sm" />
                )}
                <div className="min-w-0">
                  <div className="font-semibold">{quotation.supplier?.name}</div>
                  <div className="muted mt-0.5 text-xs tabular">{quotation.quotation_no}</div>
                </div>
              </div>
              {quotation.supplier && (
                <span className="flex items-center gap-1 text-xs muted tabular">
                  <Star size={12} className="text-star" />
                  {quotation.supplier.rating.average}
                </span>
              )}
            </div>

            <div className="flex flex-wrap gap-1.5 mt-3">
              {quotation.total.amount === cheapest && <Badge tone="success">{t.quotation.bestPrice}</Badge>}
              {quotation.lead_time_days === fastest && <Badge tone="brand">{t.quotation.fastest}</Badge>}
            </div>

            <div className="mt-4 text-2xl font-bold tabular text-accent">
              {formatMoney(quotation.total, locale)}
            </div>

            <dl className="mt-4 space-y-1.5 text-sm">
              {[
                [t.quotation.leadTime, `${quotation.lead_time_days} ${t.quotation.days}`],
                [t.quotation.paymentTerms, `${quotation.payment_terms_days} ${t.quotation.days}`],
                [t.common.total, formatMoney(quotation.subtotal, locale)],
              ].map(([label, value]) => (
                <div key={label} className="flex justify-between gap-2">
                  <dt className="muted">{label}</dt>
                  <dd className="tabular">{value}</dd>
                </div>
              ))}
            </dl>

            {quotation.notes && <p className="muted text-sm mt-3">{quotation.notes}</p>}

            <div className="mt-auto flex gap-2 pt-4">
              {/* الوثيقة كاملةً لمن يريد أن يقرأ قبل أن يقرّر، والقبول السريع لمن قارن الأرقام */}
              <Link to={`/quotations/${quotation.id}`} className="flex-1">
                <Button size="sm" variant="outline" className="w-full">
                  <FileText size={14} />
                  {t.quotation.view}
                </Button>
              </Link>

              {quotation.status === 'accepted' ? (
                <Badge tone="success">
                  <Check size={13} className="me-1" />
                  {t.common.accept}
                </Badge>
              ) : (
                <Button
                  size="sm"
                  className="flex-1"
                  disabled={!quotation.is_actionable || accept.isPending}
                  onClick={() => accept.mutate(quotation.id)}
                >
                  {t.common.accept}
                </Button>
              )}
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}
