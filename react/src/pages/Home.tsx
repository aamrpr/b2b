import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Search, Send, Table2 } from 'lucide-react'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatMoney } from '@/lib/money'
import { useUnits } from '@/lib/units'
import { Thumb } from '@/components/Thumb'
import { SupplierLogo } from '@/components/SupplierLogo'
import { CategoryTicker, PromoBanners } from '@/components/PromoBanners'
import { Button, Card, ErrorState, LoadingLabel } from '@/components/ui'
import type { Category, Product } from '@/types'

export function Home() {
  const { t, dir, locale, count } = useI18n()
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['categories', locale],
    queryFn: async () => (await api.get<{ data: Category[] }>('/categories')).data.data,
  })

  const bestsellers = useQuery({
    queryKey: ['bestsellers', locale],
    queryFn: async () => (await api.get<{ data: Product[] }>('/products/bestsellers')).data.data,
  })

  const latest = useQuery({
    queryKey: ['latest-products', locale],
    queryFn: async () =>
      (await api.get<{ data: Product[] }>('/products', { params: { per_page: 10 } })).data.data,
  })

  return (
    <div className="space-y-14">
      {/* المقدمة وشريط الأصناف يبلغان حافة الشاشة. ما بعدهما يبقى داخل الهامش. */}
      <div>
      <section className="hero bleed-x -mt-8 px-4 py-20 text-center md:py-28">
        <div className="mx-auto max-w-3xl">
          <h1 className="hero-title rise text-3xl font-bold leading-tight sm:text-4xl md:text-5xl">
            {t.app.tagline}
          </h1>

          <p className="hero-sub rise rise-2 mt-4 text-base md:text-lg">{t.app.descriptor}</p>

          <div className="rise rise-3 mt-8 flex flex-wrap justify-center gap-3">
            <Link to="/products">
              <Button>
                {t.home.browseProducts}
                <Arrow size={16} />
              </Button>
            </Link>
            <Link to="/rfq/new">
              <Button variant="outline" className="btn-on-dark">
                {t.home.postRfq}
              </Button>
            </Link>
          </div>
        </div>
      </section>

      <div className="bleed-x">
        <CategoryTicker names={data?.map((category) => category.name)} />
      </div>
      <div className="mt-8">
        <PromoBanners />
      </div>
      </div>

      {/* scroll-mt يترك مقدار الشريط اللاصق فلا يختفي العنوان تحته عند /#categories */}
      <section id="categories" className="scroll-mt-20">
        <div className="mb-5 flex items-end justify-between gap-3">
          <h2 className="rise text-xl font-bold">{t.home.categoriesTitle}</h2>
          <Link to="/suppliers" className="flex items-center gap-1.5 text-sm text-accent hover:underline">
            {t.home.browseSuppliers}
            <Arrow size={15} />
          </Link>
        </div>

        {isError ? (
          <ErrorState error={error} onRetry={() => void refetch()} />
        ) : isLoading ? (
          <div role="status" aria-label={t.common.loading}>
            <LoadingLabel message={t.common.loading} />
            <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
              {Array.from({ length: 8 }, (_, index) => (
                <div key={index} className="skeleton h-40" />
              ))}
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            {data?.map((category, index) => (
              <Link
                key={category.id}
                to={`/suppliers?category=${category.slug}`}
                className="group rise"
                style={{ animationDelay: `${Math.min(index, 8) * 45}ms` }}
              >
                <Thumb
                  src={category.image_thumb_url || category.image_url}
                  alt={category.name}
                  markClassName="h-10 w-10"
                  className="h-32 w-full border border-[var(--border)] transition group-hover:border-brand-400"
                />
                <div className="mt-2.5 text-center text-sm font-semibold">{category.name}</div>
                <div className="mt-0.5 text-center text-xs muted">
                  {count(category.children?.length ?? 0, t.home.subcategories)}
                </div>
              </Link>
            ))}
          </div>
        )}
      </section>

      <ProductStrip title={t.home.bestsellers} query={bestsellers} hideWhenEmpty />
      <ProductStrip title={t.home.latest} query={latest} hideWhenEmpty />

      <section id="how" className="scroll-mt-20">
        <h2 className="rise mb-5 text-xl font-bold">{t.home.howTitle}</h2>
        <div className="grid gap-4 md:grid-cols-3">
          {[
            { icon: Search, title: t.home.step1, body: t.home.step1d },
            { icon: Send, title: t.home.step2, body: t.home.step2d },
            { icon: Table2, title: t.home.step3, body: t.home.step3d },
          ].map(({ icon: Icon, title, body }, index) => (
            <Card key={title} className="rise" >
              <div className="mb-3 flex items-center gap-3">
                <span className="grid h-9 w-9 place-items-center rounded-lg bg-[var(--accent-soft)] text-accent">
                  <Icon size={17} />
                </span>
                <span className="text-sm font-bold text-accent tabular">{index + 1}</span>
              </div>
              <div className="font-semibold">{title}</div>
              <p className="mt-1.5 text-sm muted">{body}</p>
            </Card>
          ))}
        </div>
      </section>
    </div>
  )
}

function ProductStrip({
  title,
  query,
  hideWhenEmpty = false,
}: {
  title: string
  query: {
    data?: Product[]
    isLoading: boolean
    isError: boolean
    error: unknown
    refetch: () => unknown
  }
  hideWhenEmpty?: boolean
}) {
  const { t, dir, locale } = useI18n()
  const { label: unitLabel } = useUnits()
  const Arrow = dir === 'rtl' ? ArrowLeft : ArrowRight
  const products = query.data ?? []

  if (hideWhenEmpty && !query.isLoading && !query.isError && products.length === 0) return null

  return (
    <section>
      <div className="mb-5 flex items-end justify-between gap-3">
        <h2 className="rise text-xl font-bold">{title}</h2>
        <Link to="/products" className="flex items-center gap-1.5 text-sm text-accent hover:underline">
          {t.home.browseProducts}
          <Arrow size={15} />
        </Link>
      </div>

      {query.isError ? (
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      ) : query.isLoading ? (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-5" role="status" aria-label={t.common.loading}>
          {Array.from({ length: 5 }, (_, index) => (
            <div key={index} className="overflow-hidden rounded-xl border border-[var(--border)]">
              <div className="skeleton h-36 rounded-none" />
              <div className="space-y-2 p-3">
                <div className="skeleton h-4 w-3/4" />
                <div className="skeleton h-3 w-1/2" />
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
          {products.map((product, index) => (
            <Card
              key={product.id}
              className="rise flex flex-col overflow-hidden p-0 transition hover:border-brand-400"
              style={{ animationDelay: `${Math.min(index, 6) * 40}ms` }}
            >
              <Link to={`/products/${product.slug}`} className="block" aria-label={product.name}>
                <Thumb src={product.cover_thumb_url || product.cover_url} alt={product.name} rounded="rounded-none" className="h-36 w-full" />
              </Link>
              <div className="flex flex-1 flex-col p-3">
                <Link to={`/products/${product.slug}`} className="line-clamp-2 text-sm font-semibold hover:text-accent">
                  <span dir="auto">{product.name}</span>
                </Link>
                {product.supplier && (
                  <Link
                    to={`/suppliers/${product.supplier.slug}`}
                    className="muted mt-1 inline-flex items-center gap-1.5 text-xs hover:text-accent"
                  >
                    <SupplierLogo src={product.supplier.logo_url} name={product.supplier.name} size="xs" />
                    <span className="truncate">{product.supplier.name}</span>
                  </Link>
                )}
                <div className="mt-2 text-sm font-bold tabular text-accent">
                  {product.price_visible ? formatMoney(product.price, locale) : t.products.priceOnRequest}
                </div>
                <div className="muted mt-auto pt-2 text-xs tabular">
                  {t.suppliers.minOrder}: {product.min_order_quantity} {unitLabel(product.unit_of_measure)}
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </section>
  )
}
