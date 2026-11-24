import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { BadgeCheck, MapPin, MessageCircle, Phone, Star } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatMoney } from '@/lib/money'
import { useAuth } from '@/store/auth'
import { Thumb } from '@/components/Thumb'
import { Badge, Button, Card, Empty, ErrorState, Loading, PageTitle } from '@/components/ui'
import type { Conversation, Product, Supplier } from '@/types'
import { useState } from 'react'
import { SupplierLogo } from '@/components/SupplierLogo'
import { useUnits } from '@/lib/units'

export function SupplierProfile() {
  const { slug = '' } = useParams()
  const { t, locale } = useI18n()
  const { label: unitLabel } = useUnits()
  const navigate = useNavigate()
  const user = useAuth((s) => s.user)
  const [error, setError] = useState<string | null>(null)

  const { data: supplier, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['supplier', slug],
    queryFn: async () => (await api.get<{ data: Supplier }>(`/suppliers/${slug}`)).data.data,
  })

  const { data: products } = useQuery({
    queryKey: ['supplier-products', slug],
    queryFn: async () => (await api.get<{ data: Product[] }>(`/suppliers/${slug}/products`)).data.data,
    enabled: Boolean(slug),
  })

  // مراسلة المستورد مباشرة — المسار (أ) في السيناريو
  const startConversation = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ conversation: Conversation }>('/conversations', {
          tenant_id: supplier!.id,
          subject: supplier!.name,
        })
      ).data.conversation,
    onSuccess: (conversation) => navigate(`/messages/${conversation.id}`),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />
  if (!supplier) return <Empty message={t.common.empty} />

  const canMessage = Boolean(user) && user?.tenant?.id !== supplier.id

  return (
    <div className="space-y-8">
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-4">
            <SupplierLogo src={supplier.logo_url} name={supplier.name} size="lg" />

            <div>
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-bold">{supplier.name}</h1>
              {supplier.is_verified && (
                <Badge tone="success">
                  <BadgeCheck size={13} className="me-1" />
                  {t.suppliers.verified}
                </Badge>
              )}
            </div>
            {supplier.description && <p className="muted mt-2 max-w-2xl">{supplier.description}</p>}

            <div className="flex flex-wrap items-center gap-4 mt-4 text-sm muted">
              {supplier.city && (
                <span className="flex items-center gap-1">
                  <MapPin size={14} />
                  {supplier.city}
                </span>
              )}
              {supplier.phone && (
                <span className="flex items-center gap-1 tabular" dir="ltr">
                  <Phone size={14} />
                  {supplier.phone}
                </span>
              )}
              <span className="flex items-center gap-1 tabular">
                <Star size={14} className="text-star" />
                {supplier.rating.average} ({supplier.rating.count})
              </span>
            </div>
            </div>
          </div>

          <div className="flex flex-col items-end gap-3">
            {supplier.min_order && (
              <div className="text-sm text-end">
                <div className="muted text-xs">{t.suppliers.minOrder}</div>
                <div className="font-semibold tabular">{formatMoney(supplier.min_order, locale)}</div>
              </div>
            )}

            {canMessage ? (
              <Button onClick={() => startConversation.mutate()} disabled={startConversation.isPending}>
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
        </div>

        {supplier.categories?.length ? (
          <div className="mt-5 pt-5 border-t border-[var(--border)]">
            <div className="text-xs muted mb-2">{t.auth.categories}</div>
            <div className="flex flex-wrap gap-1.5">
              {supplier.categories.map((c) => (
                <Badge key={c.id} tone={c.is_primary ? 'brand' : 'neutral'}>
                  {c.name}
                </Badge>
              ))}
            </div>
            {supplier.categories[0]?.brands?.length ? (
              <div className="mt-3 text-sm">
                <span className="muted text-xs">{t.suppliers.brands}: </span>
                {supplier.categories[0].brands.join(' · ')}
              </div>
            ) : null}
          </div>
        ) : null}

        {error && <p className="text-sm text-danger mt-3">{error}</p>}
      </Card>

      <div>
        <PageTitle title={t.nav.products} subtitle={products ? `${products.length}` : undefined} />
        {!products?.length ? (
          <Empty message={t.common.empty} />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {products.map((product) => (
              <Card key={product.id} className="p-0 overflow-hidden transition hover:border-brand-400">
                <Link to={`/products/${product.slug}`} className="block" aria-label={product.name}>
                  <Thumb
                    src={product.cover_thumb_url || product.cover_url}
                    alt={product.name}
                    rounded="rounded-none"
                    className="h-40 w-full"
                  />
                </Link>
                <div className="p-4">
                <Link to={`/products/${product.slug}`} className="font-semibold hover:text-accent">
                  {product.name}
                </Link>
                {product.brand && <div className="muted text-xs mt-1">{product.brand}</div>}
                <div className="mt-3 text-lg font-bold tabular text-accent">
                  {product.price_visible ? formatMoney(product.price, locale) : '—'}
                </div>
                <div className="muted text-xs mt-2 tabular">
                  {t.suppliers.minOrder}: {product.min_order_quantity} {unitLabel(product.unit_of_measure)}
                  {' · '}
                  {t.quotation.leadTime}: {product.lead_time_days} {t.quotation.days}
                </div>
                </div>
              </Card>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
