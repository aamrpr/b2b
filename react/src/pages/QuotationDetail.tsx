import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { BadgeCheck, Check, PenLine } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { formatMoney } from '@/lib/money'
import { useAuth } from '@/store/auth'
import { SignaturePad } from '@/components/SignaturePad'
import { Badge, Button, Card, Empty, ErrorState, Loading } from '@/components/ui'
import type { Quotation, QuotationSignature } from '@/types'
import { useUnits } from '@/lib/units'
import { SupplierLogo } from '@/components/SupplierLogo'

/**
 * وثيقة عرض السعر كما يقرأها طرفاها.
 *
 * جدول المقارنة يُقبَل منه بنقرة لأنّه مقارنة أرقام، أمّا هنا فالوثيقة
 * كاملة: بنودها وشروطها وتواقيعها. ومن فتحها ليقرأها يوقّعها — التوقيع
 * هو ما يحوّل «رأيتُه» إلى «ارتضيتُه»، وبعده يصير العرض أمر شراء.
 */
export function QuotationDetail() {
  const { quotationId = '' } = useParams()
  const { t, locale } = useI18n()
  const { label: unitLabel } = useUnits()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)

  const [signature, setSignature] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [hint, setHint] = useState<string | null>(null)

  const { data: quotation, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['quotation', quotationId],
    queryFn: async () => (await api.get<{ data: Quotation }>(`/quotations/${quotationId}`)).data.data,
  })

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['quotation', quotationId] })
    queryClient.invalidateQueries({ queryKey: ['my-quotations'] })
    queryClient.invalidateQueries({ queryKey: ['rfq-quotations'] })
  }

  const sign = useMutation({
    mutationFn: async () =>
      (await api.post(`/quotations/${quotationId}/sign`, { signature, signed_by: user?.name })).data,
    onSuccess: () => {
      setSignature(null)
      setError(null)
      invalidate()
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const approve = useMutation({
    mutationFn: async () =>
      (await api.post(`/quotations/${quotationId}/accept`, { signature, signed_by: user?.name })).data,
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['orders'] })
      navigate('/orders')
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />
  if (!quotation) return <Empty message={t.common.empty} />

  const tenantId = user?.tenant?.id
  /*
   * الطرفان بالمطابقة لا بالاستبعاد: المنصة تقرأ الوثيقة وليست طرفاً
   * فيها، و«ليس المستورد إذن هو المشتري» كان يعرض عليها زرّ الموافقة.
   */
  const isSupplier = Boolean(tenantId) && tenantId === quotation.supplier?.id
  const isBuyer = Boolean(tenantId) && tenantId === quotation.buyer?.id
  const signatures = quotation.signatures

  // المستورد يوقّع مرّة، والمشتري يوقّع موافقته ما دام العرض قابلاً للقبول
  const canSign = isSupplier && !signatures?.supplier
  const canApprove = isBuyer && quotation.is_actionable && quotation.status !== 'accepted'

  function submit(action: 'sign' | 'approve') {
    if (!signature) {
      setHint(t.quotation.signFirst)
      return
    }

    setHint(null)
    if (action === 'sign') sign.mutate()
    else approve.mutate()
  }

  const itemColumns: DataTableColumn<NonNullable<typeof quotation.items>[number]>[] = [
    {
      data: 'description',
      title: t.rfq.itemDescription,
      orderable: false,
      render: (row) => `<span dir="auto">${esc(row.description)}</span>`,
    },
    {
      data: 'quantity',
      title: t.common.quantity,
      orderable: false,
      className: 'dt-num',
      render: (row) => esc(`${row.quantity} ${unitLabel(row.unit)}`),
    },
    {
      data: 'unit_price',
      title: t.common.unitPrice,
      orderable: false,
      className: 'dt-num',
      render: (row) => esc(formatMoney(row.unit_price, locale)),
    },
    {
      data: 'line_total',
      title: t.common.total,
      orderable: false,
      className: 'dt-num font-medium',
      render: (row) => esc(formatMoney(row.line_total, locale)),
    },
  ]

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            {quotation.supplier && (
              <SupplierLogo src={quotation.supplier.logo_url} name={quotation.supplier.name} size="md" />
            )}

            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-lg font-bold">{quotation.supplier?.name}</h1>
                {quotation.supplier?.is_verified && (
                  <Badge tone="success">
                    <BadgeCheck size={13} className="me-1" />
                    {t.suppliers.verified}
                  </Badge>
                )}
              </div>
              <div className="muted mt-1 text-sm">
                {t.quotation.document} <span dir="ltr">{quotation.quotation_no}</span>
              </div>
            </div>
          </div>

          <div className="text-end">
            <div className="text-2xl font-bold tabular text-accent">{formatMoney(quotation.total, locale)}</div>
            <Badge tone={quotation.status === 'accepted' ? 'success' : 'brand'}>
              {t.quotation.statuses[quotation.status as keyof typeof t.quotation.statuses] ?? quotation.status}
            </Badge>
          </div>
        </div>

        <dl className="mt-5 grid grid-cols-2 gap-x-6 gap-y-3 border-t border-[var(--border)] pt-5 text-sm sm:grid-cols-4">
          {[
            [t.quotation.leadTime, `${quotation.lead_time_days} ${t.quotation.days}`],
            [t.quotation.paymentTerms, `${quotation.payment_terms_days} ${t.quotation.days}`],
            [t.quotation.validUntil, quotation.valid_until?.slice(0, 10) ?? '—'],
            [t.quotation.incoterm, quotation.incoterm ?? '—'],
          ].map(([label, value]) => (
            <div key={label}>
              <dt className="muted text-xs">{label}</dt>
              <dd className="mt-0.5 font-medium tabular">{value}</dd>
            </div>
          ))}
        </dl>

        {quotation.rfq_title && (
          <div className="muted mt-4 text-sm">
            <span dir="auto">{quotation.rfq_title}</span>
          </div>
        )}
      </Card>

      <section>
        <h2 className="mb-3 text-lg font-bold">{t.quotation.items}</h2>
        {/* بنود العقد: تُقرأ بترتيبها كما وُقّعت، فلا فرز ولا تصفّح */}
        <DataTable<NonNullable<typeof quotation.items>[number]>
          rows={quotation.items ?? []}
          columns={itemColumns}
          paging={false}
          exportable={false}
          searching={false}
          info={false}
        />

        <Card className="mt-4">
          <dl className="ms-auto max-w-xs space-y-2 text-sm">
            {[
              [t.quotation.subtotal, quotation.subtotal],
              [t.quotation.discount, quotation.discount],
              [t.quotation.tax, quotation.tax],
              [t.quotation.shipping, quotation.shipping],
            ].map(([label, money]) =>
              money ? (
                <div key={label as string} className="flex justify-between gap-4">
                  <dt className="muted">{label as string}</dt>
                  <dd className="tabular">{formatMoney(money as never, locale)}</dd>
                </div>
              ) : null,
            )}
            <div className="flex justify-between gap-4 border-t border-[var(--border)] pt-2 text-base font-bold">
              <dt>{t.common.total}</dt>
              <dd className="tabular text-accent">{formatMoney(quotation.total, locale)}</dd>
            </div>
          </dl>
        </Card>
      </section>

      {quotation.notes && (
        <section>
          <h2 className="mb-3 text-lg font-bold">{t.quotation.notes}</h2>
          <p dir="auto" className="muted leading-relaxed">
            {quotation.notes}
          </p>
        </section>
      )}

      <section>
        <h2 className="mb-3 text-lg font-bold">{t.quotation.signatures}</h2>

        <div className="grid gap-4 sm:grid-cols-2">
          <SignatureBlock title={t.quotation.supplierSignature} signature={signatures?.supplier} />
          <SignatureBlock title={t.quotation.buyerSignature} signature={signatures?.buyer} />
        </div>

        {(canSign || canApprove) && (
          <Card className="mt-4">
            {/* اللوحة تحمل إرشادها، فلا يُكرَّر هنا إلّا ما يخصّ الموافقة */}
            {canApprove && <p className="muted mb-3 text-sm">{t.quotation.approveHint}</p>}

            <SignaturePad onChange={setSignature} disabled={sign.isPending || approve.isPending} />

            <div className="mt-4 flex flex-wrap items-center gap-3">
              <Button
                onClick={() => submit(canApprove ? 'approve' : 'sign')}
                disabled={sign.isPending || approve.isPending}
              >
                {canApprove ? <Check size={16} /> : <PenLine size={16} />}
                {canApprove ? t.quotation.approve : t.quotation.sign}
              </Button>

              {hint && <span className="text-sm text-danger">{hint}</span>}
            </div>

            {error && <p className="mt-3 text-sm text-danger">{error}</p>}
          </Card>
        )}
      </section>
    </div>
  )
}

function SignatureBlock({ title, signature }: { title: string; signature?: QuotationSignature | null }) {
  const { t } = useI18n()


  return (
    <Card>
      <div className="muted text-xs">{title}</div>

      {signature ? (
        <>
          {/* خلفية بيضاء دائماً: التوقيع حبرٌ كحليّ لا يُقرأ على سطحٍ ليليّ */}
          <img
            src={signature.url}
            alt={signature.by}
            className="mt-2 h-20 w-full rounded-lg bg-white object-contain p-1"
          />
          <div className="mt-2 text-sm font-medium">
            <span dir="auto">{signature.by}</span>
          </div>
          {/* التاريخ كما يُكتب في بقية الوثائق: أرقام لاتينية وسنة أولاً */}
          <div className="muted text-xs tabular">
            {t.quotation.signedAt} {signature.at.slice(0, 10)}
          </div>
        </>
      ) : (
        <div className="mt-2 grid h-20 place-items-center rounded-lg border border-dashed border-[var(--border)] text-sm muted">
          {t.quotation.notSigned}
        </div>
      )}
    </Card>
  )
}
