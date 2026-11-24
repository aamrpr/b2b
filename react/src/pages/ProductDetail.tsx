import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { BadgeCheck, ChevronLeft, ChevronRight, MapPin, MessageCircle } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { formatMoney } from '@/lib/money'
import { useAuth } from '@/store/auth'
import { Gallery } from '@/components/Gallery'
import { Thumb } from '@/components/Thumb'
import { Badge, Button, Card, Empty, ErrorState, Loading } from '@/components/ui'
import type { Conversation, Product } from '@/types'
import { useUnits } from '@/lib/units'
import { SupplierLogo } from '@/components/SupplierLogo'

/**
 * صفحة المنتج.
 *
 * ما يَفصل بين قطعتين متشابهتين عند تاجر الجملة ليس الصورة وحدها بل
 * الرقم: المقاس، التوافق، أقلّ كمية، مدّة التوريد. فالصفحة عمودان —
 * الصور إلى جانب ما يُقرأ ويُقارن — ثمّ الخصائص مبسوطة تحتهما، وزرّ
 * طلب عرض السعر مرافق للسعر لا في ذيل الصفحة.
 */
export function ProductDetail() {
  const { slug = '' } = useParams()
  const { t, locale, dir } = useI18n()
  const navigate = useNavigate()
  const user = useAuth((s) => s.user)
  const { label: unitLabel } = useUnits()
  const [error, setError] = useState<string | null>(null)

  const Arrow = dir === 'rtl' ? ChevronLeft : ChevronRight

  const { data: product, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['product', slug],
    queryFn: async () => (await api.get<{ data: Product }>(`/products/${slug}`)).data.data,
  })

  const { data: similar } = useQuery({
    queryKey: ['similar', product?.category?.slug, product?.id],
    queryFn: async () =>
      (
        await api.get<{ data: Product[] }>('/products', {
          params: { category: product!.category!.slug, per_page: 5 },
        })
      ).data.data.filter((item) => item.id !== product!.id).slice(0, 4),
    enabled: Boolean(product?.category?.slug),
  })

  const startConversation = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ conversation: Conversation }>('/conversations', {
          tenant_id: product!.supplier!.id,
          subject: product!.name,
        })
      ).data.conversation,
    onSuccess: (conversation) => navigate(`/messages/${conversation.id}`),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  // رمز البلد وحده لا يقول شيئاً لمن يقرأ: Intl يترجمه إلى اسم البلد بلغته
  const origin = useMemo(() => {
    const code = product?.country_of_origin

    if (!code) return null

    try {
      return new Intl.DisplayNames([locale], { type: 'region' }).of(code) ?? code
    } catch {
      return code
    }
  }, [product?.country_of_origin, locale])

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />
  if (!product) return <Empty message={t.common.empty} />

  const supplier = product.supplier
  const canMessage = Boolean(user) && Boolean(supplier) && user?.tenant?.id !== supplier?.id

  const facts = [
    { label: t.suppliers.minOrder, value: `${product.min_order_quantity} ${unitLabel(product.unit_of_measure)}` },
    { label: t.quotation.leadTime, value: `${product.lead_time_days} ${t.quotation.days}` },
    { label: t.products.sku, value: product.sku, ltr: true },
    { label: t.suppliers.brands, value: product.brand },
    { label: t.products.origin, value: origin },
    { label: t.products.hsCode, value: product.hs_code, ltr: true },
  ].filter((fact) => Boolean(fact.value))

  const rfqLink =
    `/rfq/new?product=${product.id}&slug=${encodeURIComponent(product.slug)}` +
    `&item=${encodeURIComponent(product.name)}` +
    `&category=${product.category_id}&qty=${product.min_order_quantity}&unit=${encodeURIComponent(product.unit_of_measure)}`

  const tierColumns: DataTableColumn<NonNullable<typeof product.price_tiers>[number]>[] = [
    {
      data: 'min_qty',
      title: t.products.quantity,
      orderable: false,
      className: 'dt-num',
      render: (row) =>
        esc(
          `${row.max_qty ? `${row.min_qty} \u2013 ${row.max_qty}` : `${row.min_qty} ${t.products.andAbove}`} ${unitLabel(product.unit_of_measure)}`,
        ),
    },
    {
      data: 'unit_price',
      title: t.common.unitPrice,
      orderable: false,
      className: 'dt-num font-medium',
      render: (row) => esc(formatMoney(row.unit_price, locale)),
    },
  ]

  return (
    <div className="space-y-10">
      {/* المسار: صفٌّ مرن لا نصّ بفواصل، فلا تلتصق الأسماء اللاتينية في العربية */}
      <nav className="flex flex-wrap items-center gap-1.5 text-xs muted">
        <Link to="/products" className="hover:text-accent">
          {t.nav.products}
        </Link>
        <Arrow size={13} className="shrink-0" />
        {product.category && (
          <>
            <Link to={`/products?category=${product.category.slug}`} className="hover:text-accent">
              {product.category.name}
            </Link>
            <Arrow size={13} className="shrink-0" />
          </>
        )}
        <span className="text-[var(--text)]">{product.name}</span>
      </nav>

      <div className="grid gap-8 lg:grid-cols-2">
        <Gallery images={product.images} alt={product.name} />

        <div>
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={product.status === 'active' ? 'success' : 'warning'}>
              {product.status === 'active' ? t.products.available : t.products.outOfStock}
            </Badge>
            {product.brand && <Badge tone="brand">{product.brand}</Badge>}
          </div>

          {/*
            * النصّ للمستورد لا للواجهة: قد يكتبه بالعربية أو باللاتينية، فـ
            * dir="auto" على النصّ وحده ليُقرأ باتجاهه، والمحاذاة تبقى للصفحة
            * فلا يهرب العنوان إلى الحافة المقابلة.
            */}
          <h1 className="mt-3 text-2xl font-bold leading-snug md:text-3xl">
            <span dir="auto">{product.name}</span>
          </h1>

          {supplier && (
            <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
              <Link to={`/suppliers/${supplier.slug}`} className="flex items-center gap-1.5 font-medium hover:text-accent">
                <SupplierLogo src={supplier.logo_url} name={supplier.name} size="xs" />
                {supplier.name}
              </Link>
              {supplier.is_verified && (
                <Badge tone="success">
                  <BadgeCheck size={13} className="me-1" />
                  {t.suppliers.verified}
                </Badge>
              )}
              {supplier.city && (
                <span className="flex items-center gap-1 muted">
                  <MapPin size={14} className="shrink-0" />
                  {supplier.city}
                </span>
              )}
            </div>
          )}

          <Card className="mt-5">
            <div className="text-3xl font-bold tabular text-accent">
              {product.price_visible ? formatMoney(product.price, locale) : t.products.priceOnRequest}
            </div>
            <div className="muted mt-1 text-sm">
              {t.common.unit}: {unitLabel(product.unit_of_measure)}
            </div>

            <div className="mt-5 flex flex-wrap gap-3">
              <Link to={rfqLink}>
                <Button>{t.home.postRfq}</Button>
              </Link>

              {canMessage ? (
                <Button
                  variant="outline"
                  onClick={() => startConversation.mutate()}
                  disabled={startConversation.isPending}
                >
                  <MessageCircle size={16} />
                  {startConversation.isPending ? t.common.loading : t.messages.contact}
                </Button>
              ) : (
                !user && (
                  <Button variant="outline" onClick={() => navigate('/login')}>
                    <MessageCircle size={16} />
                    {t.messages.contact}
                  </Button>
                )
              )}
            </div>

            {error && <p className="mt-3 text-sm text-danger">{error}</p>}
          </Card>

          {facts.length > 0 && (
            <dl className="mt-5 grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
              {facts.map((fact) => (
                <div key={fact.label} className="border-b border-[var(--border)] pb-2">
                  <dt className="muted text-xs">{fact.label}</dt>
                  <dd className="mt-0.5 font-medium">
                    <span dir={fact.ltr ? 'ltr' : 'auto'}>{fact.value}</span>
                  </dd>
                </div>
              ))}
            </dl>
          )}
        </div>
      </div>

      {Boolean(product.price_tiers?.length) && (
        <section>
          <h2 className="mb-3 text-lg font-bold">{t.products.priceTiers}</h2>
          {/*
            * سلّم الأسعار جدولُ وثيقةٍ لا قائمةَ سجلّات: قصيرٌ ومرتَّب
            * بالكمية، فلا بحث فيه ولا تصفّح ولا فرز.
            */}
          <DataTable<NonNullable<typeof product.price_tiers>[number]>
            rows={product.price_tiers!}
            columns={tierColumns}
            paging={false}
            exportable={false}
            searching={false}
            info={false}
          />
        </section>
      )}

      {product.description && (
        <section>
          <h2 className="mb-3 text-lg font-bold">{t.products.description}</h2>
          <p dir="auto" className="max-w-3xl leading-relaxed muted">
            {product.description}
          </p>
        </section>
      )}

      {Boolean(product.specifications?.length) && (
        <section>
          <h2 className="mb-3 text-lg font-bold">{t.products.specifications}</h2>
          <Card className="p-0 overflow-hidden">
            <dl className="divide-y divide-[var(--border)]">
              {product.specifications!.map((spec) => (
                <div key={spec.label} className="grid gap-1 px-4 py-3 sm:grid-cols-3 sm:gap-4">
                  <dt className="muted text-sm">
                    <span dir="auto">{spec.label}</span>
                  </dt>
                  {/*
                    * القيمة باتجاهها: 90915-YZZD2 تُقلب في سياقٍ عربيّ فتصير
                    * YZZD2-90915 — رقم قطعةٍ آخر لا وجود له.
                    */}
                  <dd className="text-sm font-medium sm:col-span-2">
                    <span dir="auto">{spec.value}</span>
                  </dd>
                </div>
              ))}
            </dl>
          </Card>
        </section>
      )}

      {Boolean(similar?.length) && (
        <section>
          <h2 className="mb-3 text-lg font-bold">{t.products.similar}</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {similar!.map((item) => (
              <Link key={item.id} to={`/products/${item.slug}`}>
                <Card className="h-full overflow-hidden p-0 transition hover:border-brand-400">
                  <Thumb src={item.cover_thumb_url || item.cover_url} alt={item.name} rounded="rounded-none" className="h-36 w-full" />
                  <div className="p-4">
                    <div className="font-semibold">
                      <span dir="auto">{item.name}</span>
                    </div>
                    <div className="mt-2 font-bold tabular text-accent">
                      {item.price_visible ? formatMoney(item.price, locale) : t.products.priceOnRequest}
                    </div>
                  </div>
                </Card>
              </Link>
            ))}
          </div>
        </section>
      )}
    </div>
  )
}
