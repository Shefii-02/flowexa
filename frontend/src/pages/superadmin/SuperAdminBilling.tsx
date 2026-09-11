// src/pages/superadmin/SuperAdminBilling.tsx
import { useEffect, useState } from 'react'
import { superadminApi } from '@/api'
import { StatCard, Spinner, Badge } from '@/components/ui'
import { fmt } from '@/utils'

const statusVariant = (s: string): any =>
  s === 'active' ? 'green'
    : s === 'trial' ? 'yellow'
    : s === 'paid' ? 'green'
    : s === 'pending' ? 'gray'
    : s === 'upgraded' ? 'blue'
    : 'red'

export default function SuperAdminBilling() {
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    superadminApi.billing()
      .then(r => setData(r.data))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>
  if (!data) return null

  const c = data.companies ?? {}
  const rev = data.revenue ?? {}
  const momDelta = rev.last_month ? ((rev.this_month - rev.last_month) / rev.last_month) * 100 : null

  return (
    <div className="space-y-6">
      <div>
        <h1 className="page-title">Billing & subscriptions</h1>
        <p className="page-sub">Recurring revenue, plan mix, payments and upcoming renewals</p>
      </div>

      {/* Headline numbers */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="MRR" value={fmt.currency(data.mrr ?? 0)} icon="🔁" color="text-brand-600" />
        <StatCard label="ARR (run-rate)" value={fmt.currency(data.arr ?? 0)} icon="📈" />
        <StatCard label="Revenue this month" value={fmt.currency(rev.this_month ?? 0)} icon="💰"
          sub={momDelta === null ? undefined : `${momDelta >= 0 ? '▲' : '▼'} ${Math.abs(momDelta).toFixed(0)}% vs last month`} />
        <StatCard label="Revenue all-time" value={fmt.currency(rev.all_time ?? 0)} icon="🏦" />
      </div>

      {/* Company status breakdown */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Accounts by status</h3></div>
        <div className="card-body">
          <div className="grid grid-cols-3 sm:grid-cols-5 gap-4">
            {[
              ['Total', c.total, 'bg-gray-100 text-gray-700'],
              ['Active', c.active, 'bg-green-100 text-green-700'],
              ['Trial', c.trial, 'bg-yellow-100 text-yellow-700'],
              ['Expired', c.expired, 'bg-orange-100 text-orange-700'],
              ['Suspended', c.suspended, 'bg-red-100 text-red-700'],
            ].map(([label, count, cls]) => (
              <div key={label as string} className={`rounded-xl p-4 text-center ${cls}`}>
                <p className="text-2xl font-bold">{count ?? 0}</p>
                <p className="text-xs font-medium mt-1">{label}</p>
              </div>
            ))}
          </div>
          <p className="text-xs text-gray-400 mt-3">
            Grace period after expiry: {data.grace_days} day(s) — accounts stay usable, then lock until renewal.
          </p>
        </div>
      </div>

      {/* MRR by plan */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">MRR by plan</h3></div>
        <div className="table-wrapper">
          <table className="table">
            <thead><tr><th>Plan</th><th>Active companies</th><th className="text-right">Monthly value</th><th className="text-right">Share</th></tr></thead>
            <tbody>
              {(data.by_plan ?? []).map((r: any) => (
                <tr key={r.plan}>
                  <td className="font-medium">{r.plan}</td>
                  <td>{r.companies}</td>
                  <td className="text-right">{fmt.currency(r.mrr)}</td>
                  <td className="text-right text-gray-500">{data.mrr ? ((r.mrr / data.mrr) * 100).toFixed(0) : 0}%</td>
                </tr>
              ))}
              {(data.by_plan ?? []).length === 0 && (
                <tr><td colSpan={4} className="text-center text-gray-400 py-6">No active paid subscriptions yet</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Upcoming expiries */}
      <div className="card">
        <div className="card-header"><h3 className="card-title">Renewals due (next 14 days)</h3></div>
        <div className="table-wrapper">
          <table className="table">
            <thead><tr><th>Company</th><th>Plan</th><th>Status</th><th>Expires</th><th className="text-right">Days left</th></tr></thead>
            <tbody>
              {(data.upcoming_expiries ?? []).map((e: any) => (
                <tr key={e.id}>
                  <td className="font-medium">{e.name}</td>
                  <td>{e.plan ?? '—'}</td>
                  <td><Badge variant={statusVariant(e.status)}>{e.status}</Badge></td>
                  <td className="text-xs text-gray-500">{fmt.date(e.expires_at)}</td>
                  <td className={`text-right font-medium ${e.days_left <= 3 ? 'text-red-600' : 'text-gray-700'}`}>{e.days_left}</td>
                </tr>
              ))}
              {(data.upcoming_expiries ?? []).length === 0 && (
                <tr><td colSpan={5} className="text-center text-gray-400 py-6">Nothing expiring in the next 14 days</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Recent payment orders */}
        <div className="card">
          <div className="card-header"><h3 className="card-title">Recent payment orders</h3></div>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Company</th><th>Kind</th><th className="text-right">Amount</th><th>Status</th><th>When</th></tr></thead>
              <tbody>
                {(data.recent_orders ?? []).map((o: any) => (
                  <tr key={o.id}>
                    <td className="font-medium">{o.company ?? '—'}</td>
                    <td className="text-xs text-gray-500">{o.kind === 'wallet_topup' ? 'Wallet top-up' : 'Plan'}</td>
                    <td className="text-right">{fmt.currency(o.amount)}</td>
                    <td><Badge variant={statusVariant(o.status)}>{o.status}</Badge></td>
                    <td className="text-xs text-gray-400">{fmt.datetime(o.created_at)}</td>
                  </tr>
                ))}
                {(data.recent_orders ?? []).length === 0 && (
                  <tr><td colSpan={5} className="text-center text-gray-400 py-6">No payments yet</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Plan change log */}
        <div className="card">
          <div className="card-header"><h3 className="card-title">Plan change log</h3></div>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Company</th><th>Plan</th><th className="text-right">Paid</th><th>Status</th><th>Start</th></tr></thead>
              <tbody>
                {(data.recent_changes ?? []).map((r: any) => (
                  <tr key={r.id}>
                    <td className="font-medium">{r.company ?? '—'}</td>
                    <td>{r.plan ?? '—'}</td>
                    <td className="text-right">{r.amount > 0 ? fmt.currency(r.amount) : '—'}</td>
                    <td><Badge variant={statusVariant(r.status)}>{r.status}</Badge></td>
                    <td className="text-xs text-gray-400">{r.starts_at ? fmt.date(r.starts_at) : '—'}</td>
                  </tr>
                ))}
                {(data.recent_changes ?? []).length === 0 && (
                  <tr><td colSpan={5} className="text-center text-gray-400 py-6">No plan changes yet</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  )
}
