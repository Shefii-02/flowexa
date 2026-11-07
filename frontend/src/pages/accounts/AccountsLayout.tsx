// Access gate shared by every /accounts/... route; the pages render inside <Outlet />.
import { Outlet } from 'react-router-dom'
import { usePermission } from '@/store'

export default function AccountsLayout() {
  const canRead = usePermission('accounts.read')
  const canManage = usePermission('accounts.manage')
  if (!canRead && !canManage) {
    return <div className="card"><div className="card-body text-sm text-gray-500">You don't have access to Accounts. Ask an admin to grant the "View Accounts &amp; GST" permission.</div></div>
  }
  return <Outlet />
}
