// src/pages/superadmin/SuperAdminStats.tsx
import { useEffect, useState } from 'react'
import { superadminApi } from '@/api'
import { StatCard, Spinner } from '@/components/ui'
import { fmt } from '@/utils'

export default function SuperAdminStats() {
  const [stats,  setStats]  = useState<any>(null)
  const [dash,   setDash]   = useState<any>(null)
  const [loading,setLoading]= useState(true)

  useEffect(() => {
    Promise.all([superadminApi.stats(), superadminApi.dashboard()])
      .then(([s, d]) => { setStats(s.data); setDash(d.data) })
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>

  return (
    <div className="space-y-6">
      <div><h1 className="page-title">Platform stats</h1><p className="page-sub">Real-time platform overview</p></div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Total companies"  value={fmt.number(stats?.total_companies ?? 0)}  icon="🏢" />
        <StatCard label="Total users"      value={fmt.number(stats?.total_users ?? 0)}      icon="👤" />
        <StatCard label="Total messages"   value={fmt.number(stats?.total_messages ?? 0)}   icon="💬" />
        <StatCard label="Total revenue"    value={`₹${fmt.number(stats?.total_revenue_inr ?? 0)}`} icon="💰" color="text-brand-600" />
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Total contacts"   value={fmt.number(stats?.total_contacts ?? 0)}  icon="👥" />
        <StatCard label="Total leads"      value={fmt.number(stats?.total_leads ?? 0)}      icon="🎯" />
        <StatCard label="Total campaigns"  value={fmt.number(stats?.total_campaigns ?? 0)} icon="📢" />
        <StatCard label="Messages today"   value={fmt.number(stats?.messages_today ?? 0)}  icon="📤" color="text-blue-600" />
      </div>

      {/* Company status breakdown */}
      {dash && (
        <div className="card">
          <div className="card-header"><h3 className="card-title">Company breakdown</h3></div>
          <div className="card-body">
            <div className="grid grid-cols-4 gap-4">
              {[
                ['Total',     dash.companies?.total,     'bg-gray-100 text-gray-700'],
                ['Active',    dash.companies?.active,    'bg-green-100 text-green-700'],
                ['Trial',     dash.companies?.trial,     'bg-yellow-100 text-yellow-700'],
                ['Suspended', dash.companies?.suspended, 'bg-red-100 text-red-700'],
              ].map(([label, count, cls]) => (
                <div key={label as string} className={`rounded-xl p-4 text-center ${cls}`}>
                  <p className="text-2xl font-bold">{count ?? 0}</p>
                  <p className="text-xs font-medium mt-1">{label}</p>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Recent companies */}
      {dash?.recent_companies?.length > 0 && (
        <div className="card">
          <div className="card-header"><h3 className="card-title">Recent companies</h3></div>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Company</th><th>Plan</th><th>Status</th><th>Joined</th></tr></thead>
              <tbody>
                {dash.recent_companies.map((c: any) => (
                  <tr key={c.id}>
                    <td>
                      <p className="font-medium text-gray-900">{c.name}</p>
                      <p className="text-xs text-gray-400">{c.email}</p>
                    </td>
                    <td className="text-xs text-gray-500">{c.plan?.name || '—'}</td>
                    <td>
                      <span className={`badge ${c.status === 'active' ? 'badge-green' : c.status === 'trial' ? 'badge-yellow' : 'badge-red'}`}>
                        {c.status}
                      </span>
                    </td>
                    <td className="text-xs text-gray-400">{new Date(c.created_at).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' })}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
