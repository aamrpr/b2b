import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Clock, MapPin } from 'lucide-react'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Badge, Button, Card, Empty, ErrorState, Loading, Pager, PageTitle } from '@/components/ui'
import type { Paged, Rfq } from '@/types'
import { useUnits } from '@/lib/units'

/** الطلبات الواردة للمستورد في أصنافه — قلب السيناريو من جهة المستورد. */
export function RfqInbox() {
  const { t } = useI18n()
  const { label: unitLabel } = useUnits()

  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['rfq-inbox', page],
    queryFn: async () => (await api.get<Paged<Rfq>>('/rfq-inbox', { params: { page } })).data,
  })

  const rows = data?.data ?? []

  return (
    <div>
      <PageTitle title={t.rfq.inbox} subtitle={data?.meta ? `${data.meta.total}` : undefined} />

      {isError ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading ? (
        <Loading message={t.common.loading} />
      ) : !rows.length ? (
        <Empty message={t.rfq.inboxEmpty} />
      ) : (
        <div className="space-y-3">
          {rows.map((rfq) => (
            <Card key={rfq.id}>
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-semibold">{rfq.title}</span>
                    {rfq.has_quoted && <Badge tone="success">{t.rfq.alreadyQuoted}</Badge>}
                  </div>

                  <div className="muted text-xs mt-1.5 flex flex-wrap items-center gap-3 tabular">
                    <span>{rfq.reference_no}</span>
                    {rfq.category && <span>{rfq.category.name}</span>}
                    {rfq.delivery_city && (
                      <span className="flex items-center gap-1">
                        <MapPin size={12} />
                        {rfq.delivery_city}
                      </span>
                    )}
                    {rfq.hours_remaining !== null && (
                      <span className="flex items-center gap-1">
                        <Clock size={12} />
                        {t.rfq.expiresIn} {rfq.hours_remaining} {t.rfq.hours}
                      </span>
                    )}
                  </div>

                  {rfq.items?.length ? (
                    <ul className="mt-3 text-sm space-y-0.5">
                      {rfq.items.map((item) => (
                        <li key={item.id} className="muted">
                          {item.product ? (
                            <Link to={`/products/${item.product.slug}`} className="hover:text-accent">
                              {item.product.name}
                            </Link>
                          ) : (
                            item.description
                          )}
                          {' — '}
                          <span className="tabular">
                            {item.quantity} {unitLabel(item.unit)}
                          </span>
                        </li>
                      ))}
                    </ul>
                  ) : null}

                  <div className="muted text-xs mt-3">
                    {t.suppliers.title}: {rfq.buyer.name}
                  </div>
                </div>

                <Link to={`/rfqs/${rfq.id}/quote`}>
                  <Button size="sm" disabled={rfq.has_quoted}>
                    {rfq.has_quoted ? t.rfq.alreadyQuoted : t.rfq.sendQuote}
                  </Button>
                </Link>
              </div>
            </Card>
          ))}
        </div>
      )}

      <Pager meta={data?.meta} page={page} onChange={setPage} />
    </div>
  )
}
