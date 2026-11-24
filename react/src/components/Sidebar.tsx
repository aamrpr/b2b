import {
  BadgeCheck,
  Banknote,
  Boxes,
  CalendarClock,
  ClipboardList,
  FileSearch,
  Home,
  Gauge,
  GalleryHorizontal,
  Images,
  LayoutDashboard,
  type LucideIcon,
  Mail,
  Mails,
  MessageCircle,
  Package,
  PackageSearch,
  Percent,
  Quote,
  Receipt,
  Ruler,
  Scale,
  ScrollText,
  RotateCcw,
  ShoppingCart,
  Truck,
  Users,
} from 'lucide-react'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { isBuyer, isPlatform, isSupplier, useAuth } from '@/store/auth'
import { useRealtime } from '@/store/realtime'
import { NavBranch, NavMenuGroup, NavMenuItem } from './NavMenu'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  end?: boolean
  badge?: number
}

interface NavSection {
  /** بلا عنوان للقسم الأوّل: روابط التصفّح العامّة لا تحتاج تسمية */
  label?: string
  items: NavItem[]
}

/** يُسقط ما لا يراه الدور الحالي ويُبقي النوع دقيقاً بلا cast */
function group(...items: (NavItem | false)[]): NavItem[] {
  return items.filter((item): item is NavItem => item !== false)
}

/**
 * قائمة التنقّل الجانبية — لا تظهر إلّا بعد تسجيل الدخول، فالزائر يبقى
 * على الشريط العلوي وصفحةِ عرضٍ بلا عمود جانبي.
 *
 * تُستعمل مرّتين: ثابتة إلى جانب المحتوى على الشاشات الواسعة، وداخل درج
 * منزلق على الهاتف — فالأقسام تُبنى هنا مرّة واحدة لا في كلّ موضع.
 */
export function Sidebar({ className, collapsed = false }: { className?: string; collapsed?: boolean }) {
  const { t } = useI18n()
  const { user } = useAuth()
  const unreadConversations = useRealtime((s) => s.unreadConversations)

  const supplier = isSupplier(user)
  const buyer = isBuyer(user)
  const platform = isPlatform(user)

  const sections: NavSection[] = [
    {
      items: group(
        { to: '/', label: t.nav.home, icon: Home, end: true },
        { to: '/products', label: t.nav.products, icon: Package },
        { to: '/suppliers', label: t.nav.suppliers, icon: Users },
      ),
    },
    {
      label: t.nav.trade,
      items: group(
        supplier && { to: '/rfq-inbox', label: t.nav.inbox, icon: FileSearch },
        supplier && { to: '/my/quotations', label: t.nav.myQuotations, icon: Quote },
        supplier && { to: '/my/products', label: t.nav.myProducts, icon: PackageSearch },
        buyer && { to: '/my/rfqs', label: t.nav.rfqs, icon: FileSearch },
        (supplier || buyer) && { to: '/orders', label: t.nav.orders, icon: ShoppingCart },
      ),
    },
    {
      label: t.nav.operations,
      items: group(
        (supplier || buyer) && { to: '/shipping', label: t.nav.shipping, icon: Truck },
        Boolean(user) && { to: '/returns', label: t.nav.returns, icon: RotateCcw },
        supplier && { to: '/inventory', label: t.nav.inventory, icon: Boxes },
      ),
    },
    {
      label: t.nav.finance,
      items: group(
        supplier && { to: '/payments', label: t.nav.payments, icon: Banknote },
        supplier && { to: '/purchasing', label: t.nav.purchasing, icon: ClipboardList },
        (supplier || platform) && { to: '/accounting/trial-balance', label: t.nav.accounting, icon: Receipt },
      ),
    },
    {
      items: group({ to: '/messages', label: t.nav.messages, icon: MessageCircle, badge: unreadConversations }),
    },
  ].filter((section) => section.items.length > 0)

  /*
   * إدارة المنصة أحد عشر باباً: كانت تبويبات فوق كل صفحة من صفحاتها،
   * فصارت فرعاً ينطوي هنا — القائمة واحدة لكل المنصة، والمحتوى يبدأ من
   * أعلى الصفحة لا من تحت صفٍّ من الروابط.
   */
  const adminLinks = [
    { to: '/admin', label: t.admin.dashboard, icon: LayoutDashboard, end: true },
    { to: '/admin/summary', label: t.admin.summary, icon: Gauge },
    { to: '/admin/suppliers', label: t.admin.suppliers, icon: BadgeCheck },
    { to: '/admin/products', label: t.admin.manageProducts, icon: Package },
    { to: '/admin/users', label: t.admin.manageUsers, icon: Users },
    { to: '/admin/trade', label: t.admin.deals, icon: Quote },
    // النزاعات تُدار من صفحتها نفسها: المنصة وسيطها، وهي ترى كلّها هناك
    { to: '/returns/disputes', label: t.admin.disputes, icon: Scale },
    { to: '/admin/categories', label: t.admin.categories, icon: Images },
    { to: '/admin/banners', label: t.admin.banners, icon: GalleryHorizontal },
    { to: '/admin/units', label: t.admin.units, icon: Ruler },
    { to: '/admin/emails', label: t.admin.emails, icon: Mails },
    { to: '/admin/finance', label: t.admin.finance, icon: Banknote },
    { to: '/admin/commissions', label: t.admin.commissions, icon: Percent },
    { to: '/admin/plans', label: t.admin.plans, icon: CalendarClock },
    { to: '/admin/inbox', label: t.admin.inbox, icon: Mail },
    { to: '/admin/audit', label: t.admin.audit, icon: ScrollText },
  ]

  return (
    <nav className={clsx('flex flex-col', className)} aria-label={t.nav.menu}>
      {sections.map((section, index) => (
        <div
          key={section.label ?? index}
          className={index > 0 ? 'mt-2 border-t border-[var(--border)] pt-2' : undefined}
        >
          {/* العنوان لا يُختصر إلى أيقونة، والفاصل وحده يفصل الأقسام حين تُطوى */}
          {section.label && !collapsed && <NavMenuGroup label={section.label} />}

          <div className="grid gap-0.5">
            {section.items.map((item) => (
              <NavMenuItem key={item.to} {...item} collapsed={collapsed} />
            ))}
          </div>
        </div>
      ))}

      {platform && (
        <div className="mt-2 border-t border-[var(--border)] pt-2">
          <NavBranch label={t.nav.admin} icon={LayoutDashboard} items={adminLinks} collapsed={collapsed} />
        </div>
      )}
    </nav>
  )
}
