import { useQuery } from '@tanstack/react-query'
import {
  Bar,
  BarChart,
  Cell,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import {
  Activity,
  AlertTriangle,
  Award,
  BadgeCheck,
  Banknote,
  Boxes,
  CalendarClock,
  Check,
  CheckCheck,
  CheckCircle2,
  Clock,
  FilePen,
  FileSearch,
  FolderOpen,
  Image,
  LayoutGrid,
  Layers,
  type LucideIcon,
  Mail,
  MessageCircle,
  Package,
  PackageCheck,
  Percent,
  Quote,
  Receipt,
  RotateCcw,
  Route,
  Scale,
  ShoppingCart,
  Store,
  Truck,
  UserRound,
  Users,
  Warehouse,
  X,
} from 'lucide-react'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Card, ErrorState, Loading } from '@/components/ui'
import type { AdminSummary as Summary } from '@/types'

/**
 * ملخّص المنصة.
 *
 * اللوحة تُجيب «كيف تسير المنصة؟» بالمال والاتجاه، وهذه تُجيب «ماذا
 * فيها؟» بالعدد — وهما سؤالان مختلفان فصفحتان لا صفحة.
 *
 * والأيقونة هنا ليست زينة: الصفحة أربعون رقماً في شاشة واحدة، والعين
 * تجد «الشحنات» بأيقونة الشاحنة أسرع ممّا تقرأ الكلمة. ولذلك ثبتت
 * الأيقونة لكل معنى: الشاحنة للشحن حيثما ورد، والمثلّث لما يستدعي تدخّلاً.
 */

/** أيقونة كل عدّاد بمفتاحه، فالعين تجدها قبل أن تقرأ الكلمة */
const ICONS: Record<string, LucideIcon> = {
  tenants: Users,
  catalog: Package,
  demand: FileSearch,
  orders: ShoppingCart,
  logistics: Truck,
  finance: Banknote,
  inventory: Boxes,
  engagement: MessageCircle,

  suppliers: Users,
  verified: BadgeCheck,
  pending_review: Clock,
  buyers: Store,
  users: UserRound,
  products: Package,
  active: CheckCircle2,
  draft: FilePen,
  categories: LayoutGrid,
  subcategories: Layers,
  images: Image,
  rfqs: FileSearch,
  open: FolderOpen,
  awarded: Award,
  quotations: Quote,
  accepted: Check,
  confirmed: CheckCheck,
  shipped: Truck,
  completed: PackageCheck,
  cancelled: X,
  shipments: Truck,
  in_transit: Route,
  delivered: PackageCheck,
  returns: RotateCcw,
  disputes: Scale,
  open_disputes: AlertTriangle,
  invoices: Receipt,
  payments: Banknote,
  commissions: Percent,
  settled: CheckCircle2,
  subscriptions: CalendarClock,
  warehouses: Warehouse,
  items: Boxes,
  low_stock: AlertTriangle,
  conversations: MessageCircle,
  messages: Mail,
}

/** الشرائح: فيروزيّ للسليم، عنبريّ لما ينتظر، كحليّ ورماديّ لما بقي */
const SLICES = ['var(--chart-2)', 'var(--chart-3)', 'var(--chart-1)', 'var(--chart-4)']

/*
 * القمع بلونٍ واحد يتدرّج لا بأربعة ألوان: المراحل مرتبطة لا متنافسة،
 * والتدرّج يقول «كلّما ضاق اشتدّ» — ويبقى مقروءاً مطبوعاً بالرمادي.
 */
const FUNNEL_OPACITY = [0.45, 0.62, 0.8, 1]

export function AdminSummary() {
  const { t, locale } = useI18n()

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin-summary'],
    queryFn: async () => (await api.get<Summary>('/admin/dashboard/summary')).data,
  })

  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const number = (value: number) =>
    new Intl.NumberFormat(locale === 'ar' ? 'ar-SY' : 'en-US', { numberingSystem: 'latn' }).format(value)

  const label = (key: string) => t.admin.counts[key as keyof typeof t.admin.counts] ?? key

  const highlights = [
    { key: 'suppliers', value: data.highlights.suppliers },
    { key: 'buyers', value: data.highlights.buyers },
    { key: 'products', value: data.highlights.products },
    { key: 'categories', value: data.highlights.categories },
    { key: 'completed', value: data.highlights.completed_orders, accent: true },
  ]

  const funnel = data.charts.funnel.map((point) => ({ name: label(point.key), value: point.value }))
  const accounts = data.charts.accounts.map((point) => ({ name: label(point.key), value: point.value }))
  const accountsTotal = accounts.reduce((sum, point) => sum + point.value, 0)

  const tooltipStyle = {
    background: 'var(--card)',
    border: '1px solid var(--border)',
    borderRadius: 10,
    color: 'var(--text)',
  }

  return (
    <div className="space-y-6">
      <div>
        <p className="muted text-sm">{t.admin.summarySubtitle}</p>
        <p className="muted mt-1 text-xs tabular">
          {t.admin.asOf} {data.generated_at.slice(0, 16).replace('T', ' ')}
        </p>
      </div>

      {/* الخمسة الأولى أكبر: هي ما يُسأل عنه قبل غيره */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {highlights.map((item) => {
          const Icon = ICONS[item.key] ?? Activity

          return (
            <Card key={item.key} className="flex items-center gap-3">
              <span
                className={
                  'grid h-10 w-10 shrink-0 place-items-center rounded-xl ' +
                  (item.accent ? 'bg-[var(--success-soft)] text-success' : 'bg-[var(--accent-soft)] text-accent')
                }
              >
                <Icon size={18} />
              </span>

              <div className="min-w-0">
                <div className="muted truncate text-xs">{label(item.key)}</div>
                <div className={'text-2xl font-bold tabular ' + (item.accent ? 'text-success' : '')}>
                  {number(item.value)}
                </div>
              </div>
            </Card>
          )
        })}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <h2 className="mb-1 font-semibold">{t.admin.funnelTitle}</h2>
          <p className="muted mb-4 text-xs">{t.admin.funnelHint}</p>

          {/* الرسوم تبقى LTR: recharts لا يقلب محاوره، والنصّ داخلها يُقرأ باتجاهه */}
          <div className="h-56" dir="ltr">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={funnel} layout="vertical" margin={{ top: 0, right: 16, left: 8, bottom: 0 }}>
                <XAxis type="number" stroke="var(--muted)" fontSize={12} allowDecimals={false} />
                <YAxis
                  type="category"
                  dataKey="name"
                  stroke="var(--muted)"
                  fontSize={12}
                  width={96}
                  tickLine={false}
                  axisLine={false}
                />
                <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--surface)' }} />
                <Bar dataKey="value" radius={[0, 6, 6, 0]} barSize={22}>
                  {funnel.map((point, index) => (
                    <Cell
                      key={point.name}
                      fill="var(--chart-1)"
                      fillOpacity={FUNNEL_OPACITY[index] ?? 1}
                    />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card>
          <h2 className="mb-1 font-semibold">{t.admin.accountsMix}</h2>
          <p className="muted mb-4 text-xs">{t.admin.sections.tenants}</p>

          {/* المجموع في قلب الحلقة: الفراغ وسطها موضعه الطبيعي */}
          <div className="relative h-56" dir="ltr">
            <div className="pointer-events-none absolute inset-x-0 top-[38%] -translate-y-1/2 text-center">
              <div className="text-2xl font-bold tabular">{number(accountsTotal)}</div>
            </div>

            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={accounts} dataKey="value" nameKey="name" innerRadius="55%" outerRadius="80%" paddingAngle={2}>
                  {accounts.map((point, index) => (
                    <Cell key={point.name} fill={SLICES[index % SLICES.length]} stroke="var(--card)" strokeWidth={2} />
                  ))}
                </Pie>
                <Tooltip contentStyle={tooltipStyle} />
                {/* عنوان المفتاح بلون النصّ لا بلون الشريحة: الفاتح منها يسقط دون حدّ التباين */}
                <Legend formatter={(value: string) => <span style={{ color: 'var(--text)' }}>{value}</span>} />
              </PieChart>
            </ResponsiveContainer>
          </div>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {data.sections.map((section) => {
          const SectionIcon = ICONS[section.key] ?? Activity

          return (
            <Card key={section.key}>
              <h2 className="mb-3 flex items-center gap-2 font-semibold">
                <span className="grid h-7 w-7 place-items-center rounded-lg bg-[var(--accent-soft)] text-accent">
                  <SectionIcon size={15} />
                </span>
                {t.admin.sections[section.key as keyof typeof t.admin.sections] ?? section.key}
              </h2>

              <dl className="divide-y divide-[var(--border)]">
                {section.items.map((item) => {
                  const Icon = ICONS[item.key] ?? Activity
                  const alarming = item.tone === 'warning' && item.value > 0

                  return (
                    <div key={item.key} className="flex items-center justify-between gap-4 py-2 text-sm">
                      <dt className="flex items-center gap-2 muted">
                        <Icon size={14} className={'shrink-0 ' + (alarming ? 'text-danger' : '')} />
                        {label(item.key)}
                      </dt>
                      <dd
                        className={
                          'tabular font-semibold ' +
                          (alarming ? 'text-danger' : item.tone === 'success' ? 'text-success' : '')
                        }
                      >
                        {number(item.value)}
                      </dd>
                    </div>
                  )
                })}
              </dl>
            </Card>
          )
        })}
      </div>
    </div>
  )
}
