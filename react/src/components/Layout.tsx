import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import {
  FileSearch,
  Globe,
  Home,
  Info,
  LayoutDashboard,
  LayoutGrid,
  LogOut,
  Menu,
  MessageCircle,
  Moon,
  Package,
  Search,
  ShoppingCart,
  Sun,
  User,
  UserCog,
  Users,
  X,
} from 'lucide-react'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { isBuyer, isPlatform, isSupplier, useAuth } from '@/store/auth'
import { useRealtime } from '@/store/realtime'
import { useSidebar } from '@/store/sidebar'
import { useTheme } from '@/store/theme'
import { Logo } from './Logo'
import { SupplierLogo } from './SupplierLogo'
import { NavMenu, NavMenuItem } from './NavMenu'
import { NotificationBell } from './NotificationBell'
import { Sidebar } from './Sidebar'
import { Toasts } from './Toasts'
import { Button } from './ui'

/**
 * للمنصّة واجهتان:
 *
 * الزائر يرى صفحةَ عرضٍ عريضة بشريط علويّ وحده — لا عمود جانبي يقتطع
 * من عرض الهيرو وشبكة الأصناف. وبعد تسجيل الدخول يتحوّل الشكل إلى
 * لوحة عمل: عمود جانبي بطول الشاشة يحمل الشعار وأقسام التنقّل، ويُطوى
 * إلى أيقوناته بزرّ يتذكّر اختياره.
 */
export function Layout() {
  const { t, locale, setLocale } = useI18n()
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const { pathname, hash } = useLocation()
  const unreadConversations = useRealtime((s) => s.unreadConversations)
  const { mode, resolved, toggle } = useTheme()
  const { collapsed, toggle: toggleSidebar } = useSidebar()
  const [menuOpen, setMenuOpen] = useState(false)

  const isDark = resolved() === 'dark'

  useEffect(() => setMenuOpen(false), [pathname])

  // روابط مثل /#categories تُبدّل المسار ولا يمرّر المتصفّح إلى القسم بنفسه
  useEffect(() => {
    if (!hash) return
    document.getElementById(hash.slice(1))?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [hash, pathname])

  useEffect(() => {
    if (!menuOpen) return

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setMenuOpen(false)
    }

    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [menuOpen])

  function handleSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const query = String(new FormData(event.currentTarget).get('q') ?? '').trim()
    navigate(query ? `/products?q=${encodeURIComponent(query)}` : '/products')
    setMenuOpen(false)
  }

  async function handleLogout() {
    await logout()
    navigate('/')
  }

  const searchField = (className?: string) => (
    <form onSubmit={handleSearch} role="search" className={clsx('relative', className)}>
      <Search size={15} className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 muted" />
      <input
        name="q"
        type="search"
        placeholder={t.nav.searchPlaceholder}
        aria-label={t.common.search}
        className="w-full rounded-full border border-[var(--border)] bg-[var(--surface)] py-2 ps-9 pe-3 text-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
      />
    </form>
  )

  const brand = (
    <Link to="/" className="flex shrink-0 items-center" aria-label={t.app.name}>
      <Logo variant="mark" className="h-9 w-9 sm:hidden" />
      <Logo className="hidden h-9 w-auto sm:block" />
    </Link>
  )

  const themeToggle = (
    <Button
      variant="ghost"
      size="sm"
      onClick={toggle}
      title={mode === 'system' ? t.theme.system : isDark ? t.theme.dark : t.theme.light}
      aria-label={isDark ? t.theme.light : t.theme.dark}
    >
      {isDark ? <Sun size={16} /> : <Moon size={16} />}
    </Button>
  )

  const localeToggle = (
    <Button
      variant="ghost"
      size="sm"
      onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}
      title={locale === 'ar' ? 'English' : 'العربية'}
    >
      <Globe size={16} />
      {locale === 'ar' ? 'EN' : 'ع'}
    </Button>
  )

  const burger = (
    <Button
      variant="ghost"
      size="sm"
      className="lg:hidden"
      onClick={() => setMenuOpen((open) => !open)}
      aria-expanded={menuOpen}
      aria-label={menuOpen ? t.nav.closeMenu : t.nav.menu}
    >
      {menuOpen ? <X size={18} /> : <Menu size={18} />}
    </Button>
  )

  // هامش جانبي يتّسع مع الشاشة ويتوقف عند حد واضح، بلا عرض أقصى يترك المحتوى كصندوق.
  const frame = 'page-frame mx-auto w-full'

  const footer = (wide = false) => (
    <footer className="border-t border-[var(--border)] py-6">
      <div className={clsx('flex items-center gap-3 text-sm muted', wide ? 'px-4 sm:px-6 lg:px-8' : frame)}>
        <Logo variant="mark" className="h-6 w-6 opacity-70" />
        {t.app.name} — {t.app.tagline}
      </div>
    </footer>
  )

  if (!user) {
    const publicLinks: { to: string; label: string; icon: typeof Home; end?: boolean }[] = [
      { to: '/', label: t.nav.home, icon: Home, end: true },
      { to: '/products', label: t.nav.products, icon: Package },
      { to: '/suppliers', label: t.nav.suppliers, icon: Users },
    ]

    const anchors = (
      <>
        <Link to="/#categories" className="nav-link">
          <LayoutGrid size={15} className="shrink-0" />
          {t.nav.categories}
        </Link>
        <Link to="/about" className="nav-link">
          <Info size={15} className="shrink-0" />
          {t.nav.about}
        </Link>
      </>
    )

    return (
      <div className="flex min-h-screen flex-col pb-[calc(4.75rem+env(safe-area-inset-bottom))] lg:pb-0">
        <header className="sticky top-0 z-30 border-b border-[var(--border)] bg-[var(--header)]/95 backdrop-blur">
          <div className={frame}>
            <div className="flex h-16 items-center gap-2">
              {brand}

              <nav className="hidden items-center gap-0.5 lg:flex">
                {publicLinks.map((link) => (
                  <BarLink key={link.to} {...link} />
                ))}
                {anchors}
              </nav>

              <div className="ms-auto flex shrink-0 items-center gap-1.5">
                {searchField('hidden w-56 lg:block xl:w-72')}
                {themeToggle}
                {localeToggle}

                <Link to="/login" className="hidden sm:block">
                  <Button variant="ghost" size="sm">
                    {t.nav.login}
                  </Button>
                </Link>
                <Link to="/register" className="hidden sm:block">
                  <Button size="sm">{t.nav.register}</Button>
                </Link>

                {burger}
              </div>
            </div>
          </div>

          {menuOpen && (
            <div className="border-t border-[var(--border)] bg-[var(--header)] lg:hidden">
              <div className={clsx(frame, 'py-3')} onClick={() => setMenuOpen(false)}>
                {searchField('mb-3')}

                <nav className="grid gap-0.5">
                  {publicLinks.map((link) => (
                    <NavMenuItem key={link.to} {...link} />
                  ))}
                  {anchors}
                </nav>

                <div className="mt-3 flex gap-2 border-t border-[var(--border)] pt-3">
                  <Link to="/login" className="flex-1">
                    <Button variant="outline" size="sm" className="w-full">
                      {t.nav.login}
                    </Button>
                  </Link>
                  <Link to="/register" className="flex-1">
                    <Button size="sm" className="w-full">
                      {t.nav.register}
                    </Button>
                  </Link>
                </div>
              </div>
            </div>
          )}
        </header>

        <main className={clsx(frame, 'flex-1 py-8')}>
          <Outlet />
        </main>

        {footer()}

        <BottomBar
          items={[
            { to: '/', label: t.nav.home, icon: Home, end: true },
            { to: '/products', label: t.nav.products, icon: Package },
            { to: '/suppliers', label: t.nav.suppliers, icon: Users },
            { to: '/', hash: '#categories', label: t.nav.categories, icon: LayoutGrid },
            { to: '/about', label: t.nav.about, icon: Info },
          ]}
        />

        <Toasts />
      </div>
    )
  }

  const phoneLinks: BottomItem[] = [
    { to: '/', label: t.nav.home, icon: Home, end: true },
    { to: '/products', label: t.nav.products, icon: Package },
    { to: '/suppliers', label: t.nav.suppliers, icon: Users },
    isPlatform(user)
      ? { to: '/admin', label: t.admin.dashboard, icon: LayoutDashboard }
      : isSupplier(user)
        ? { to: '/rfq-inbox', label: t.nav.inbox, icon: FileSearch }
        : isBuyer(user)
          ? { to: '/my/rfqs', label: t.nav.rfqs, icon: FileSearch }
          : { to: '/orders', label: t.nav.orders, icon: ShoppingCart },
    { to: '/messages', label: t.nav.messages, icon: MessageCircle, badge: unreadConversations },
  ]

  return (
    <div className="flex min-h-screen pb-[calc(4.75rem+env(safe-area-inset-bottom))] lg:pb-0">
      {/* العمود بطول الشاشة لا تحت الشريط: الشعار في رأسه، وزرّ طيّه في الشريط */}
      <aside
        className={clsx(
          'sticky top-0 hidden h-screen shrink-0 flex-col border-e border-[var(--border)]',
          'bg-[var(--header)] transition-[width] duration-200 lg:flex',
          collapsed ? 'w-16' : 'w-64',
        )}
      >
        {/*
          * رأس العمود للشعار: كاملاً حين يتّسع، وعلامةً وحدها حين يُطوى —
          * 64 بكسل لا تتّسع للكلمة، والعلامة تكفي للتعريف.
          */}
        <div
          className={clsx(
            'flex h-16 shrink-0 items-center border-b border-[var(--border)]',
            collapsed ? 'justify-center px-2' : 'px-3',
          )}
        >
          <Link to="/" className="flex items-center" aria-label={t.app.name}>
            {collapsed ? <Logo variant="mark" className="h-9 w-9" /> : <Logo className="h-8 w-auto" />}
          </Link>
        </div>

        <div className={clsx('flex-1 overflow-y-auto py-3', collapsed ? 'px-2' : 'px-3')}>
          <Sidebar collapsed={collapsed} />
        </div>
      </aside>

      {/* min-w-0 ضروري: بدونه يفرض أعرضُ جدولٍ داخل العمود عرضَه على الصفّ كلّه */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-40 border-b border-[var(--border)] bg-[var(--header)]/95 backdrop-blur">
          <div className="flex h-16 items-center gap-2 px-4">
            {/*
              * زرّ الطيّ في الشريط لا في العمود: موضعه واحد سواء أكان العمود
              * موسّعاً أم مطوياً، فلا تتنقّل العين خلفه. ويقابل زرّ الهاتف
              * تحته بالأيقونة نفسها، فكلّ منهما يفتح القائمة في مقاسه.
              */}
            <Button
              variant="ghost"
              size="sm"
              // max-lg لا hidden: استعلام الوسط يسبق inline-flex الأصلية في الترتيب، وhidden لا تسبقها
              className="max-lg:hidden"
              onClick={toggleSidebar}
              aria-expanded={!collapsed}
              title={collapsed ? t.nav.expandMenu : t.nav.collapseMenu}
              aria-label={collapsed ? t.nav.expandMenu : t.nav.collapseMenu}
            >
              <Menu size={18} />
            </Button>

            {burger}
            {/* الشعار في رأس العمود الجانبي، فلا يتكرّر هنا إلّا حين يختفي العمود */}
            <div className="lg:hidden">{brand}</div>

            <div className="ms-auto flex shrink-0 items-center gap-1.5">
              {searchField('hidden w-56 md:block xl:w-72')}
              {themeToggle}
              {localeToggle}
              <NotificationBell />

              <NavMenu
                align="end"
                ariaLabel={user.name}
                label={
                  <>
                    {/* صورته إن رفعها، وإلّا الأيقونة: الشريط يعرّفه بنفسه لا بدوره */}
                    {user.avatar_url ? (
                      <img src={user.avatar_url} alt="" className="h-6 w-6 shrink-0 rounded-full object-cover" />
                    ) : (
                      <User size={16} className="shrink-0" />
                    )}
                    <span className="hidden max-w-32 truncate xl:inline">{user.tenant?.name}</span>
                  </>
                }
              >
                <div className="mb-1 flex items-center gap-2.5 border-b border-[var(--border)] px-3 pb-2">
                  {user.tenant && user.tenant.type !== 'platform' && (
                    <SupplierLogo src={user.tenant.logo_url} name={user.tenant.name} size="xs" />
                  )}
                  <div className="min-w-0">
                    <div className="truncate text-sm font-medium">{user.tenant?.name ?? user.name}</div>
                    <div className="truncate text-xs muted">{user.email}</div>
                  </div>
                </div>

                <NavMenuItem to="/account" label={t.account.title} icon={UserCog} />

                <NavMenuItem
                  to="/messages"
                  label={t.nav.messages}
                  icon={MessageCircle}
                  badge={unreadConversations}
                />

                <button
                  type="button"
                  onClick={handleLogout}
                  className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-danger transition hover:bg-[var(--danger-soft)]"
                >
                  <LogOut size={16} className="shrink-0" />
                  {t.nav.logout}
                </button>
              </NavMenu>
            </div>
          </div>
        </header>

        {/* بلا حدّ 80rem: الجدول يأخذ عرض الشاشة بجانب القائمة، لا عموداً في وسطها */}
        <main className="app-main w-full min-w-0 flex-1 px-4 py-6 lg:px-6">
          <Outlet />
        </main>

        {footer(true)}
      </div>

      <BottomBar items={phoneLinks} />

      <Toasts />

      {menuOpen && (
        <Drawer onClose={() => setMenuOpen(false)}>
          <div className="mb-3 flex items-center justify-between gap-2 border-b border-[var(--border)] pb-3">
            <Logo className="h-8 w-auto" />
            <Button variant="ghost" size="sm" onClick={() => setMenuOpen(false)} aria-label={t.nav.closeMenu}>
              <X size={18} />
            </Button>
          </div>

          {searchField('mb-3')}

          {/* الدرج لا يُطوى: المساحة هنا ليست مزدحمة بل غائبة أصلاً */}
          <div onClick={() => setMenuOpen(false)}>
            <Sidebar />
          </div>

          <div className="mt-3 border-t border-[var(--border)] pt-3">
            <Button variant="outline" size="sm" onClick={handleLogout} className="w-full">
              <LogOut size={15} />
              {t.nav.logout}
            </Button>
          </div>
        </Drawer>
      )}
    </div>
  )
}

interface BottomItem {
  to: string
  label: string
  icon: typeof Home
  end?: boolean
  /** قسم في الصفحة الرئيسية، مثل #categories */
  hash?: string
  badge?: number
}

/**
 * شريط الأقسام أسفل الهاتف. يختفي حين يظهر العمود الجانبي،
 * والمحتوى يرتفع فوقه حتى لا يغطّي آخر الصفحة.
 */
function BottomBar({ items }: { items: BottomItem[] }) {
  const { pathname, hash } = useLocation()
  const { t } = useI18n()

  return (
    <nav
      className="fixed inset-x-0 bottom-0 z-40 border-t border-[var(--border)] bg-[var(--header)]/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden"
      aria-label={t.nav.menu}
    >
      <ul className="grid" style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}>
        {items.map((item) => {
          const active = item.hash
            ? pathname === item.to && hash === item.hash
            : item.end
              ? pathname === item.to && hash === ''
              : pathname === item.to || pathname.startsWith(`${item.to}/`)

          return (
            <li key={`${item.to}${item.hash ?? ''}`}>
              <Link
                to={item.hash ? { pathname: item.to, hash: item.hash } : item.to}
                aria-current={active ? 'page' : undefined}
                className={clsx(
                  'flex flex-col items-center gap-1 px-1 pt-2 pb-1.5 text-[10px] font-medium leading-none',
                  active ? 'text-[var(--accent)]' : 'text-[var(--muted)]',
                )}
              >
                <span
                  className={clsx(
                    'relative grid h-8 w-11 place-items-center rounded-full',
                    active && 'bg-[var(--accent-soft)]',
                  )}
                >
                  <item.icon size={20} />
                  {Boolean(item.badge) && (
                    <span className="absolute -top-1 end-0 inline-grid h-4 min-w-4 place-items-center rounded-full bg-brand-700 px-1 text-[10px] font-bold text-white tabular">
                      {item.badge}
                    </span>
                  )}
                </span>
                <span className="max-w-full truncate">{item.label}</span>
              </Link>
            </li>
          )
        })}
      </ul>
    </nav>
  )
}

/** درج التنقّل على الهاتف: لوح منزلق من جهة البداية فوق حجاب معتم */
function Drawer({ children, onClose }: { children: ReactNode; onClose: () => void }) {
  return (
    <div className="fixed inset-0 z-50 lg:hidden">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} aria-hidden />

      <div className="absolute inset-y-0 start-0 flex w-72 max-w-[85%] flex-col overflow-y-auto border-e border-[var(--border)] bg-[var(--surface-raised)] p-3 shadow-xl">
        {children}
      </div>
    </div>
  )
}

/** رابط مباشر في الشريط العلوي، بصفّ الروابط نفسه */
function BarLink({
  to,
  label,
  icon: Icon,
  end,
}: {
  to: string
  label: string
  icon: typeof Home
  end?: boolean
}) {
  return (
    <NavLink to={to} end={end} className={({ isActive }) => clsx('nav-link', isActive && 'nav-link-active')}>
      <Icon size={15} className="shrink-0" />
      {label}
    </NavLink>
  )
}
