import { NavLink, Outlet } from 'react-router-dom'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { PageTitle } from '@/components/ui'

export function AccountingLayout() {
  const { t } = useI18n()

  const tabs = [
    { to: '/accounting/trial-balance', label: t.accounting.trialBalance },
    { to: '/accounting/income-statement', label: t.accounting.incomeStatement },
    { to: '/accounting/balance-sheet', label: t.accounting.balanceSheet },
    { to: '/accounting/journal', label: t.accounting.journal },
    { to: '/accounting/accounts', label: t.accounting.chartOfAccounts },
  ]

  return (
    <div>
      <PageTitle title={t.accounting.title} />

      <nav className="flex flex-wrap gap-1 mb-6 border-b border-[var(--border)] pb-2">
        {tabs.map((tab) => (
          <NavLink
            key={tab.to}
            to={tab.to}
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
