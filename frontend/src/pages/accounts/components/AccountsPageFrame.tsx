// Shared frame for every Accounts page: permission check, breadcrumb, title and description (all from accountsNav).
import type { ReactNode } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { usePermission } from '@/store'
import { ACCOUNTS_NAV } from '../accountsNav'

export default function AccountsPageFrame({ path, children }: { path: string; children: ReactNode }) {
  const canPayroll = usePermission('accounts.payroll')
  const canManage = usePermission('accounts.manage')
  const s = ACCOUNTS_NAV.find((x) => x.path === path)
  if (!s) return <>{children}</>
  if ((s.needs === 'payroll' && !canPayroll) || (s.needs === 'manage' && !canManage)) return <Navigate to="/accounts" replace />
  return (
    <div className="space-y-4">
      <div>
        <nav aria-label="Breadcrumb" className="text-xs text-gray-400">
          <Link to="/accounts" className="hover:text-gray-600">Accounts</Link>
          {s.group && <> <span aria-hidden>/</span> <span>{s.group}</span></>}
          {s.path && <> <span aria-hidden>/</span> <span className="text-gray-500">{s.label}</span></>}
        </nav>
        <h1 className="text-xl font-semibold text-gray-900">{s.icon} {s.label}</h1>
        <p className="text-sm text-gray-500">{s.desc}</p>
      </div>
      {children}
    </div>
  )
}
