// src/pages/reports/ReportsPage.tsx
import { useEffect, useState } from 'react'
import { reportApi } from '@/api'
import { StatCard, Spinner, Badge } from '@/components/ui'
import { fmt } from '@/utils'

export default function ReportsPage() {
  const [tab,     setTab]     = useState<'platform'|'purchases'>('platform')
  const [data,    setData]    = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [from,    setFrom]    = useState(() => new Date(Date.now() - 30*24*60*60*1000).toISOString().slice(0,10))
  const [to,      setTo]      = useState(() => new Date().toISOString().slice(0,10))

  const load = () => {
    setLoading(true); setData(null)
    const fn = tab === 'platform' ? reportApi.platform : reportApi.purchases
    fn({ from, to }).then(r => setData(r.data)).finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [tab])

  return (
    <div className="space-y-5">
      <div><h1 className="page-title">Reports</h1><p className="page-sub">Platform-wide analytics and purchase reports</p></div>

      {/* Tabs */}
      <div className="flex gap-1 border-b border-gray-200">
        {(['platform','purchases'] as const).map(t => (
          <button key={t} onClick={() => setTab(t)}
            className={`px-4 py-2 text-sm font-medium capitalize border-b-2 transition-colors ${tab===t ? 'border-brand-500 text-brand-600' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>
            {t === 'platform' ? 'Platform overview' : 'Purchase history'}
          </button>
        ))}
      </div>

      {/* Date range */}
      <div className="flex gap-3 items-center">
        <div className="flex items-center gap-2 text-sm">
          <label className="text-gray-500">From</label>
          <input type="date" className="input max-w-[160px]" value={from} onChange={e => setFrom(e.target.value)} />
          <label className="text-gray-500">To</label>
          <input type="date" className="input max-w-[160px]" value={to} onChange={e => setTo(e.target.value)} />
        </div>
        <button onClick={load} className="btn btn-secondary btn-sm">Apply</button>
      </div>

      {loading ? <div className="flex justify-center py-12"><Spinner size="lg" /></div> : !data ? null : (

        tab === 'platform' ? (
          <div className="space-y-5">
            {/* Companies */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <StatCard label="Total companies"  value={data.companies?.total}     icon="🏢" />
              <StatCard label="Active"           value={data.companies?.active}    icon="✅" color="text-green-600" />
              <StatCard label="Trial"            value={data.companies?.trial}     icon="⏳" color="text-yellow-600" />
              <StatCard label="Suspended"        value={data.companies?.suspended} icon="🔒" color="text-red-600" />
            </div>
            {/* Revenue */}
            <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
              <StatCard label="Total revenue"    value={`₹${fmt.number(data.revenue?.total ?? 0)}`}  icon="💰" color="text-brand-600" />
              <StatCard label="Period revenue"   value={`₹${fmt.number(data.revenue?.period ?? 0)}`} icon="📈" />
              <StatCard label="Transactions"     value={data.revenue?.transactions ?? 0}              icon="🧾" />
            </div>
            {/* Messages */}
            <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
              <StatCard label="Total messages"   value={fmt.number(data.messages?.total ?? 0)}    icon="💬" />
              <StatCard label="Period outbound"  value={fmt.number(data.messages?.outbound ?? 0)} icon="📤" />
              <StatCard label="Period inbound"   value={fmt.number(data.messages?.inbound ?? 0)}  icon="📥" />
            </div>
            {/* Plans */}
            {data.plans?.length > 0 && (
              <div className="card">
                <div className="card-header"><h3 className="card-title">Plan distribution</h3></div>
                <div className="table-wrapper">
                  <table className="table">
                    <thead><tr><th>Plan</th><th>Subscribers</th><th>Revenue</th></tr></thead>
                    <tbody>
                      {data.plans.map((p: any) => (
                        <tr key={p.name}>
                          <td className="font-medium">{p.name}</td>
                          <td>{p.subscribers}</td>
                          <td className="text-brand-600 font-medium">₹{fmt.number(p.revenue)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>
        ) : (
          <div className="space-y-5">
            {/* Purchase summary */}
            <div className="grid grid-cols-3 gap-4">
              <StatCard label="Plan revenue"   value={`₹${fmt.number(data.summary?.plan_revenue ?? 0)}`}  icon="📦" color="text-brand-600" />
              <StatCard label="Topup revenue"  value={`₹${fmt.number(data.summary?.topup_revenue ?? 0)}`} icon="💬" />
              <StatCard label="Total revenue"  value={`₹${fmt.number(data.summary?.total_revenue ?? 0)}`} icon="💰" color="text-green-600" />
            </div>
            {/* Plan purchases table */}
            <div className="card">
              <div className="card-header"><h3 className="card-title">Plan purchases</h3></div>
              <div className="table-wrapper">
                <table className="table">
                  <thead><tr><th>Company</th><th>Plan</th><th>Duration</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                  <tbody>
                    {(data.plan_purchases?.data || []).map((p: any, i: number) => (
                      <tr key={i}>
                        <td className="font-medium">{p.company}</td>
                        <td>{p.plan}</td>
                        <td className="text-xs capitalize">{p.duration_type}</td>
                        <td className="font-medium text-brand-600">₹{fmt.number(p.amount_paid)}</td>
                        <td><Badge variant={p.status==='active'?'green':p.status==='expired'?'red':'gray'}>{p.status}</Badge></td>
                        <td className="text-xs text-gray-400">{fmt.datetime(p.created_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
            {/* Wallet topups */}
            {data.wallet_topups?.length > 0 && (
              <div className="card">
                <div className="card-header"><h3 className="card-title">Wallet top-ups</h3></div>
                <div className="table-wrapper">
                  <table className="table">
                    <thead><tr><th>Company</th><th>Amount (msgs)</th><th>Description</th><th>Date</th></tr></thead>
                    <tbody>
                      {data.wallet_topups.map((t: any, i: number) => (
                        <tr key={i}>
                          <td className="font-medium">{t.company}</td>
                          <td className="font-medium text-green-600">+{fmt.number(t.amount)}</td>
                          <td className="text-xs text-gray-500">{t.description}</td>
                          <td className="text-xs text-gray-400">{fmt.datetime(t.created_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </div>
        )
      )}
    </div>
  )
}
