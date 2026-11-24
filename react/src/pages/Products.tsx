import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatMoney } from '@/lib/money'
import { Thumb } from '@/components/Thumb'
import { Card, Empty, ErrorState, Loading, Pager, PageTitle, SearchField, Select } from '@/components/ui'
import { ATTR_JOIN, ProductFilters, type ProductFacets } from '@/components/ProductFilters'
import { SupplierLogo } from '@/components/SupplierLogo'
import { useUnits } from '@/lib/units'
import type { Paged, Product } from '@/types'

interface Catalog extends Paged<Product> {
  facets?: ProductFacets
}

function listed(params: URLSearchParams, key: string): string[] {
  const raw = params.get(key)

  return raw ? raw.split(',').map((part) => part.trim()).filter(Boolean) : []
}

export function Products() {
  const { t, locale, count } = useI18n()
  const { label: unitLabel } = useUnits()
  const [params, setParams] = useSearchParams()
  const [page, setPage] = useState(1)
  const [searchKey, setSearchKey] = useState(0)

  const q = params.get('q') ?? ''
  const categories = listed(params, 'category')
  const brands = listed(params, 'brand')
  const cities = listed(params, 'city')
  const attributes = (params.get('attr') ?? '').split(ATTR_JOIN).filter(Boolean)
  const sort = params.get('sort') ?? ''
  const priceMin = params.get('price_min')
  const priceMax = params.get('price_max')

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['products', q, categories.join(','), brands.join(','), cities.join(','), attributes.join(ATTR_JOIN), priceMin, priceMax, sort, page, locale],
    placeholderData: keepPreviousData,
    queryFn: async () =>
      (
        await api.get<Catalog>('/products', {
          params: {
            q: q || undefined,
            category: categories.join(',') || undefined,
            brand: brands.join(',') || undefined,
            city: cities.join(',') || undefined,
            attr: attributes.join(ATTR_JOIN) || undefined,
            price_min: priceMin || undefined,
            price_max: priceMax || undefined,
            sort: sort || undefined,
            page,
          },
        })
      ).data,
  })

  const rows = data?.data ?? []
  const active = Boolean(q || categories.length || brands.length || cities.length || attributes.length || priceMin || priceMax || sort)

  function update(patch: Record<string, string>) {
    const next = new URLSearchParams(params)

    for (const [key, value] of Object.entries(patch)) {
      if (value) next.set(key, value)
      else next.delete(key)
    }

    setParams(next)
    setPage(1)
  }

  function toggle(key: 'category' | 'brand' | 'city', value: string) {
    const current = key === 'category' ? categories : key === 'brand' ? brands : cities
    const next = current.includes(value) ? current.filter((item) => item !== value) : [...current, value]

    update({ [key]: next.join(',') })
  }

  function toggleAttribute(token: string) {
    const next = attributes.includes(token) ? attributes.filter((item) => item !== token) : [...attributes, token]

    update({ attr: next.join(ATTR_JOIN) })
  }

  return (
    <div>
      <PageTitle title={t.nav.products} />

      <div className="lg:grid lg:grid-cols-[17.5rem_minmax(0,1fr)] lg:items-start lg:gap-8">
        <ProductFilters
          facets={data?.facets}
          categories={categories}
          brands={brands}
          cities={cities}
          attributes={attributes}
          priceMin={priceMin == null ? null : Number(priceMin)}
          priceMax={priceMax == null ? null : Number(priceMax)}
          onToggle={toggle}
          onAttribute={toggleAttribute}
          onPrice={(min, max) => update({ price_min: min == null ? '' : String(min), price_max: max == null ? '' : String(max) })}
          onClear={() => {
            setSearchKey((key) => key + 1)
            setParams(new URLSearchParams())
            setPage(1)
          }}
          active={active}
        />

        <div className={isFetching ? 'opacity-70 transition' : undefined}>
          <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <SearchField
              key={searchKey}
              className="w-full sm:max-w-md"
              defaultValue={q}
              onSearch={(term) => update({ q: term })}
            />
            <div className="w-full shrink-0 sm:w-56">
              <Select
                aria-label={t.products.sort}
                value={sort}
                onChange={(event) => update({ sort: event.target.value })}
              >
                <option value="">{t.products.sortNewest}</option>
                <option value="price_asc">{t.products.sortPriceAsc}</option>
                <option value="price_desc">{t.products.sortPriceDesc}</option>
                <option value="popular">{t.products.sortPopular}</option>
              </Select>
            </div>
          </div>

          {data?.meta && (
            <p className="mb-4 text-sm muted">{count(data.meta.total, t.products.resultCount)}</p>
          )}

          {isError && !data ? (
            <ErrorState error={error} onRetry={() => void refetch()} />
          ) : isLoading && !data ? (
            <Loading message={t.common.loading} />
          ) : !rows.length ? (
            <Empty
              message={t.common.empty}
              action={
                active ? (
                  <button
                    type="button"
                    className="text-sm font-medium text-accent hover:underline"
                    onClick={() => {
                      setSearchKey((key) => key + 1)
                      setParams(new URLSearchParams())
                      setPage(1)
                    }}
                  >
                    {t.products.clearFilters}
                  </button>
                ) : undefined
              }
            />
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {rows.map((product) => (
                <Card key={product.id} className="flex flex-col overflow-hidden p-0 transition hover:border-brand-400">
                  <Link to={`/products/${product.slug}`} className="block" aria-label={product.name}>
                    <Thumb
                      src={product.cover_thumb_url || product.cover_url}
                      alt={product.name}
                      rounded="rounded-none"
                      className="h-40 w-full"
                    />
                  </Link>
                  <div className="flex flex-1 flex-col p-4">
                    <Link to={`/products/${product.slug}`} className="font-semibold hover:text-accent">
                      <span dir="auto">{product.name}</span>
                    </Link>
                    {product.supplier && (
                      <Link
                        to={`/suppliers/${product.supplier.slug}`}
                        className="muted mt-1 inline-flex items-center gap-1.5 py-1 text-xs hover:text-accent"
                      >
                        <SupplierLogo src={product.supplier.logo_url} name={product.supplier.name} size="xs" />
                        {product.supplier.name}
                      </Link>
                    )}
                    <div className="mt-3 text-lg font-bold tabular text-accent">
                      {product.price_visible ? formatMoney(product.price, locale) : t.products.priceOnRequest}
                    </div>
                    <div className="muted mt-auto pt-3 text-xs tabular">
                      {t.suppliers.minOrder}: {product.min_order_quantity} {unitLabel(product.unit_of_measure)}
                    </div>
                  </div>
                </Card>
              ))}
            </div>
          )}

          <Pager meta={data?.meta} page={page} onChange={setPage} />
        </div>
      </div>
    </div>
  )
}
