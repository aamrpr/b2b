import { NavLink, Outlet } from 'react-router-dom'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { PageTitle } from '@/components/ui'

export function ShippingLayout() {
  const { t } = useI18n()

  const tabs = [
    { to: '/shipping', label: t.shipping.shipments, end: true },
    { to: '/shipping/carriers', label: t.shipping.carriers },
  ]

  return (
    <div>
      <PageTitle title={t.shipping.title} />

      <nav className="flex flex-wrap gap-1 mb-6 border-b border-[var(--border)] pb-2">
        {tabs.map((tab) => (
          <NavLink
            key={tab.to}
            to={tab.to}
            end={tab.end}
            className={({ isActive }) =>
              clsx(
                'rounded-lg px-3 py-2 text-sm transition',
                isActive ? 'bg-brand-500/10 text-accent font-medium' : 'muted hover:bg-[var(--surface)]',
              )
            }
          >
            {tab.label}
          </NavLink>
        ))}
      </nav>

      <Outlet />
    </div>
  )
}
