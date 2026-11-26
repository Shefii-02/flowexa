// HR Administration → Sales & Incentives — what each person sold, the incentive it earned, and the commission rules, on one page.
import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import HrSalesPage from './HrSalesPage'
import HrIncentivesPage from './HrIncentivesPage'
import { PageHeader } from './hrShared'

export default function HrSalesIncentivesPage() {
  const nav = useNavigate()
  const loc = useLocation()
  const [tab, setTab] = useState<'sales' | 'incentives'>(loc.pathname.endsWith('/incentives') ? 'incentives' : 'sales')
  const go = (t: 'sales' | 'incentives') => { setTab(t); nav(`/hr/admin/${t}`, { replace: true }) }
  return (
    <div className="p-6 mx-auto space-y-5">
      <PageHeader icon="🧾" title="Sales & Incentives" sub="Record sales, set the commission rules, and track the incentives earned" />
      <div className="flex gap-1 border-b border-gray-200">
        {([['sales', '🧾 Sales'], ['incentives', '🎁 Incentive rules & ledger']] as const).map(([k, label]) => (
          <button key={k} onClick={() => go(k)} className={`px-4 py-2 text-sm -mb-px border-b-2 ${tab === k ? 'border-indigo-600 text-indigo-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>{label}</button>
        ))}
      </div>
      {tab === 'sales' ? <HrSalesPage embedded /> : <HrIncentivesPage embedded />}
    </div>
  )
}
