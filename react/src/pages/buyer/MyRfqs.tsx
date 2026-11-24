import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Badge, Button, Card, Empty, ErrorState, Loading, Pager, PageTitle } from '@/components/ui'
import type { Paged, Rfq } from '@/types'

export function MyRfqs() {
  const { t } = useI18n()

  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['my-rfqs', page],
    queryFn: async () => (await api.get<Paged<Rfq>>('/my/rfqs', { params: { page } })).data,
  })

  const rows = data?.data ?? []

  return (
    <div>
      <PageTitle
        title={t.rfq.myRfqs}
        action={
          <Link to="/rfq/new">
            <Button size="sm">
              <Plus size={15} />
              {t.rfq.create}
            </Button>
          </Link>
        }
      />

      {isError ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading ? (
        <Loading message={t.common.loading} />
      ) : !rows.length ? (
        <Empty message={t.common.empty} />
      ) : (
        <div className="space-y-3">
          {rows.map((rfq) => (
            <Card key={rfq.id}>
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="font-semibold">{rfq.title}</span>
                    <Badge tone={rfq.status === 'awarded' ? 'success' : 'brand'}>{rfq.status}</Badge>
                  </div>
                  <div className="muted text-xs mt-1 tabular">
                    {rfq.reference_no}
                    {rfq.category && ` · ${rfq.category.name}`}
                    {rfq.hours_remaining !== null && ` · ${t.rfq.expiresIn} ${rfq.hours_remaining} ${t.rfq.hours}`}
                  </div>
                </div>

                <div className="flex items-center gap-5 text-center">
                  {[
                    { label: t.rfq.reach, value: rfq.reach.notified },
                    { label: t.rfq.viewed, value: rfq.reach.viewed },
                    { label: t.rfq.quoted, value: rfq.reach.quoted },
                  ].map((stat) => (
                    <div key={stat.label}>
                      <div className="text-lg font-bold tabular">{stat.value}</div>
                      <div className="muted text-xs">{stat.label}</div>
                    </div>
                  ))}

                  <Link to={`/rfqs/${rfq.id}/quotations`}>
                    <Button variant="outline" size="sm">
                      {t.rfq.compare}
                    </Button>
                  </Link>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      <Pager meta={data?.meta} page={page} onChange={setPage} />
    </div>
  )
}
