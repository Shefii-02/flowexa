import { Link, Navigate, useParams } from 'react-router-dom'
import { usePermission } from '@/store'
import DashboardTab from './DashboardTab'
import VouchersTab from './VouchersTab'
import LedgersTab from './LedgersTab'
import ReportsTab from './ReportsTab'
import GstTab from './GstTab'
import SettingsTab from './SettingsTab'
import ItemsTab from './ItemsTab'
import SalesTab from './SalesTab'
import PaymentsTab from './PaymentsTab'
import AssetsTab from './AssetsTab'
import AdjustingTab from './AdjustingTab'
import PettyCashTab from './PettyCashTab'
import PayrollTab from './PayrollTab'
import YearEndTab from './YearEndTab'

const TABS: [string, string][] = [
  ['', '📊 Dashboard'], ['sales', '💼 Sales'], ['vouchers', '🧾 Vouchers'], ['payments', '💳 Payments'], ['ledgers', '📒 Chart of accounts'], ['items', '📦 Items'],
  ['assets', '🏢 Fixed assets'], ['adjusting', '🔧 Adjusting entries'], ['petty-cash', '🪙 Petty cash'], ['payroll', '👥 Payroll'],
  ['reports', '📑 Reports'], ['gst', '🏛️ GST'], ['year-end', '🔒 Year-end'], ['settings', '⚙️ Settings'],
]

export default function AccountsPage() {
  const { tab = '' } = useParams()
  const canRead = usePermission('accounts.read')
  const canManage = usePermission('accounts.manage')
  const canPayroll = usePermission('accounts.payroll')
  if (!canRead && !canManage) {
    return <div className="card"><div className="card-body text-sm text-gray-500">You don't have access to Accounts. Ask an admin to grant the "View Accounts &amp; GST" permission.</div></div>
  }
  // each tab is shown only if the signed-in staff member can actually use it
  const tabs = TABS.filter(([k]) => (k !== 'payroll' || canPayroll) && (k !== 'settings' || canManage))
  if (!tabs.some(([k]) => k === tab)) return <Navigate to="/accounts" replace />
  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">Accounts</h1>
        <p className="text-sm text-gray-500">Income, expenses, GST and books — in one place.</p>
      </div>
      <div className="flex gap-1 border-b overflow-x-auto">
        {tabs.map(([k, l]) => (
          <Link key={k} to={k ? `/accounts/${k}` : '/accounts'}
            className={`px-3 py-2 text-sm whitespace-nowrap border-b-2 -mb-px ${tab === k ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-800'}`}>{l}</Link>
        ))}
      </div>
      {tab === '' && <DashboardTab />}
      {tab === 'sales' && <SalesTab />}
      {tab === 'vouchers' && <VouchersTab />}
      {tab === 'payments' && <PaymentsTab />}
      {tab === 'assets' && <AssetsTab />}
      {tab === 'adjusting' && <AdjustingTab />}
      {tab === 'petty-cash' && <PettyCashTab />}
      {tab === 'payroll' && <PayrollTab />}
      {tab === 'year-end' && <YearEndTab />}
      {tab === 'ledgers' && <LedgersTab />}
      {tab === 'items' && <ItemsTab />}
      {tab === 'reports' && <ReportsTab />}
      {tab === 'gst' && <GstTab />}
      {tab === 'settings' && <SettingsTab />}
    </div>
  )
}
