import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { BadgeCheck, MapPin, Star } from 'lucide-react'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatMoney } from '@/lib/money'
import { Badge, Button, Card, Empty, ErrorState, Loading, Pager, PageTitle } from '@/components/ui'
import type { Category, Paged, Supplier } from '@/types'
import { SearchSelect } from '@/components/fields/SearchSelect'
import { SupplierLogo } from '@/components/SupplierLogo'

/** دليل المستوردين — المسار (أ): تاجر الجملة يبحث عن مستورد في صنف معيّن. */
export function Suppliers() {
  const { t, locale } = useI18n()
  const [params, setParams] = useSearchParams()
  const categorySlug = params.get('category') ?? ''
  const verifiedOnly = params.get('verified_only') === '1'

  const { data: categories } = useQuery({
    queryKey: ['categories'],
    queryFn: async () => (await api.get<{ data: Category[] }>('/categories')).data.data,
  })

  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['suppliers', categorySlug, verifiedOnly, page],
    queryFn: async () =>
      (
        await api.get<Paged<Supplier>>('/suppliers', {
          params: {
            category: categorySlug || undefined,
            verified_only: verifiedOnly ? 1 : undefined,
            page,
          },
        })
      ).data,
  })

  const rows = data?.data ?? []

  const activeCategory = categories?.find((c) => c.slug === categorySlug)

  function update(key: string, value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next)
  }

  return (
    <div>
      <PageTitle
        title={activeCategory ? `${t.suppliers.inCategory} ${activeCategory.name}` : t.suppliers.title}
        subtitle={rows.length ? `${rows.length} ${t.rfq.supplier}` : undefined}
      />

      <div className="flex flex-wrap gap-3 mb-6">
        <div className="w-full max-w-xs">
          <SearchSelect
            name="category"
            placeholder={t.rfq.category}
            isClearable
            defaultValue={categorySlug}
            options={(categories ?? []).map((c) => ({ value: c.slug, label: c.name }))}
            onChange={(value) => update('category', value)}
          />
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={verifiedOnly}
            onChange={(e) => update('verified_only', e.target.checked ? '1' : '')}
          />
          {t.common.verifiedOnly}
        </label>
      </div>

      {isError ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading ? (
        <Loading message={t.common.loading} />
      ) : !rows.length ? (
        <Empty
          message={t.common.empty}
          action={
            (categorySlug || verifiedOnly) && (
              <Button variant="outline" size="sm" onClick={() => setParams(new URLSearchParams())}>
                {t.common.clearSearch}
              </Button>
            )
          }
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {rows.map((supplier) => (
            <Card key={supplier.id} className="flex flex-col">
              <div className="flex items-start justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2.5">
                  <SupplierLogo src={supplier.logo_url} name={supplier.name} size="sm" />
                  <div className="font-semibold">{supplier.name}</div>
                </div>
                {supplier.is_verified && (
                  <Badge tone="success">
                    <BadgeCheck size={13} className="me-1" />
                    {t.suppliers.verified}
                  </Badge>
                )}
              </div>

              {supplier.description && <p className="muted text-sm mt-2 line-clamp-2">{supplier.description}</p>}

              <div className="flex flex-wrap items-center gap-4 mt-4 text-sm muted">
                {supplier.city && (
                  <span className="flex items-center gap-1">
                    <MapPin size={14} />
                    {supplier.city}
                  </span>
                )}
                <span className="flex items-center gap-1 tabular">
                  <Star size={14} className="text-star" />
                  {supplier.rating.average} ({supplier.rating.count})
                </span>
                <span className="tabular">
                  {supplier.completed_orders} {t.suppliers.orders}
                </span>
              </div>

              {supplier.categories?.length ? (
                <div className="flex flex-wrap gap-1.5 mt-3">
                  {supplier.categories.slice(0, 3).map((c) => (
                    <Badge key={c.id} tone={c.is_primary ? 'brand' : 'neutral'}>
                      {c.name}
                    </Badge>
                  ))}
                </div>
              ) : null}

              {supplier.min_order && (
                <div className="text-xs muted mt-3">
                  {t.suppliers.minOrder}: <span className="tabular">{formatMoney(supplier.min_order, locale)}</span>
                </div>
              )}

              <div className="mt-auto pt-4">
                <Link to={`/suppliers/${supplier.slug}`}>
                  <Button variant="outline" size="sm" className="w-full">
                    {t.suppliers.viewProducts}
                    {supplier.products_count !== undefined && (
                      <span className="tabular muted">({supplier.products_count})</span>
                    )}
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
