import { Link, Navigate, useParams } from 'react-router-dom'
import { usePermission } from '@/store'
import DashboardTab from './DashboardTab'
import VouchersTab from './VouchersTab'
import LedgersTab from './LedgersTab'
import ReportsTab from './ReportsTab'
import GstTab from './GstTab'
import SettingsTab from './SettingsTab'
import ItemsTab from './ItemsTab'

const TABS: [string, string][] = [
  ['', '📊 Dashboard'], ['vouchers', '🧾 Vouchers'], ['ledgers', '📒 Ledgers'], ['items', '📦 Items'], ['reports', '📑 Reports'], ['gst', '🏛️ GST'], ['settings', '⚙️ Settings'],
]

export default function AccountsPage() {
  const { tab = '' } = useParams()
  const canRead = usePermission('accounts.read')
  const canManage = usePermission('accounts.manage')
  if (!canRead && !canManage) {
    return <div className="card"><div className="card-body text-sm text-gray-500">You don't have access to Accounts. Ask an admin to grant the "View Accounts &amp; GST" permission.</div></div>
  }
  if (!TABS.some(([k]) => k === tab)) return <Navigate to="/accounts" replace />
  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">Accounts</h1>
        <p className="text-sm text-gray-500">Income, expenses, GST and books — in one place.</p>
      </div>
      <div className="flex gap-1 border-b overflow-x-auto">
        {TABS.map(([k, l]) => (
          <Link key={k} to={k ? `/accounts/${k}` : '/accounts'}
            className={`px-3 py-2 text-sm whitespace-nowrap border-b-2 -mb-px ${tab === k ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-800'}`}>{l}</Link>
        ))}
      </div>
      {tab === '' && <DashboardTab />}
      {tab === 'vouchers' && <VouchersTab />}
      {tab === 'ledgers' && <LedgersTab />}
      {tab === 'items' && <ItemsTab />}
      {tab === 'reports' && <ReportsTab />}
      {tab === 'gst' && <GstTab />}
      {tab === 'settings' && <SettingsTab />}
    </div>
  )
}
