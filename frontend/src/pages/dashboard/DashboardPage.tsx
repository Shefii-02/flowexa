// src/pages/dashboard/DashboardPage.tsx
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { analyticsApi } from '@/api'
import { StatCard, Spinner, Badge } from '@/components/ui'
import { useAppSelector } from '@/store'
import { fmt, stageConfig, campaignStatusConfig } from '@/utils'

interface Overview {
  contacts:  { total: number; opted_in: number; new_today: number; new_month: number }
  leads:     { total: number; new: number; in_progress: number; enrolled: number; conversion_rate: number }
  messages:  { total_sent: number; sent_today: number; inbound_total: number }
  wallet:    { balance: number; total_used: number }
  campaigns: { total: number; running: number; completed: number }
}

export default function DashboardPage() {
  const navigate = useNavigate()
  const user     = useAppSelector((s) => s.auth.user)
  const [data,    setData]    = useState<Overview | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    analyticsApi.overview()
      .then((r) => setData(r.data))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return (
    <div className="flex items-center justify-center h-48"><Spinner size="lg" /></div>
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="page-title">Good morning, {user?.name?.split(' ')[0]} 👋</h1>
        <p className="page-sub">Here's what's happening with {user?.company?.name}</p>
      </div>

      {/* Trial banner */}
      {user?.company?.status === 'trial' && user.company.trial_ends_at && (
        <div className="bg-amber-50 border border-amber-200 rounded-xl px-5 py-3 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span>⏳</span>
            <p className="text-sm text-amber-800 font-medium">
              Trial ends {fmt.date(user.company.trial_ends_at)}
            </p>
          </div>
          <button onClick={() => navigate('/wallet')} className="text-xs text-amber-700 font-medium hover:underline">
            Upgrade now →
          </button>
        </div>
      )}

      {/* Wallet low banner */}
      {user?.company?.wallet?.is_low && (
        <div className="bg-red-50 border border-red-200 rounded-xl px-5 py-3 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span>⚠️</span>
            <p className="text-sm text-red-800 font-medium">
              Low balance — {fmt.number(user.company.wallet.balance)} messages remaining
            </p>
          </div>
          <button onClick={() => navigate('/wallet')} className="text-xs text-red-700 font-medium hover:underline">
            Recharge →
          </button>
        </div>
      )}

      {/* Stats grid */}
      {data && (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <StatCard label="Total contacts"   value={fmt.number(data.contacts.total)}   sub={`+${data.contacts.new_today} today`} icon="👥" />
            <StatCard label="Active leads"     value={fmt.number(data.leads.total)}       sub={`${fmt.percent(data.leads.conversion_rate)} converted`} icon="🎯" color="text-brand-600" />
            <StatCard label="Messages sent"    value={fmt.number(data.messages.total_sent)} sub={`${data.messages.sent_today} today`} icon="💬" />
            <StatCard label="Wallet balance"   value={fmt.number(data.wallet.balance)}   sub={`${fmt.number(data.wallet.total_used)} used`} icon="💳"
              color={data.wallet.balance < 500 ? 'text-red-600' : 'text-brand-600'} />
          </div>

          {/* Lead funnel + campaigns */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">

            {/* Lead stages */}
            <div className="card">
              <div className="card-header">
                <h3 className="card-title">Lead pipeline</h3>
                <button onClick={() => navigate('/leads')} className="text-xs text-brand-600 hover:underline">View all →</button>
              </div>
              <div className="card-body space-y-3">
                {([
                  ['new', data.leads.new],
                  ['in_progress', data.leads.in_progress],
                  ['enrolled', data.leads.enrolled],
                ] as [string, number][]).map(([stage, count]) => {
                  const cfg = stage === 'in_progress'
                    ? { label: 'In progress', badge: 'badge-yellow' }
                    : stageConfig[stage]
                  const pct = data.leads.total ? Math.round((count / data.leads.total) * 100) : 0
                  return (
                    <div key={stage}>
                      <div className="flex justify-between items-center mb-1">
                        <span className={`badge ${cfg.badge}`}>{cfg.label}</span>
                        <span className="text-sm font-medium text-gray-700">{fmt.number(count)}</span>
                      </div>
                      <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div className="h-full bg-brand-500 rounded-full transition-all" style={{ width: `${pct}%` }} />
                      </div>
                    </div>
                  )
                })}
                <div className="pt-2 border-t border-gray-100 flex justify-between text-sm">
                  <span className="text-gray-500">Conversion rate</span>
                  <span className="font-semibold text-brand-600">{fmt.percent(data.leads.conversion_rate)}</span>
                </div>
              </div>
            </div>

            {/* Campaigns */}
            <div className="card">
              <div className="card-header">
                <h3 className="card-title">Campaigns</h3>
                <button onClick={() => navigate('/campaigns')} className="text-xs text-brand-600 hover:underline">View all →</button>
              </div>
              <div className="card-body space-y-3">
                {([
                  ['Total', data.campaigns.total, 'badge-gray'],
                  ['Running', data.campaigns.running, 'badge-yellow'],
                  ['Completed', data.campaigns.completed, 'badge-green'],
                ] as [string, number, string][]).map(([label, count, badge]) => (
                  <div key={label} className="flex items-center justify-between py-1">
                    <span className={`badge ${badge}`}>{label}</span>
                    <span className="text-sm font-semibold text-gray-700">{count}</span>
                  </div>
                ))}
                <div className="pt-3">
                  <button
                    onClick={() => navigate('/campaigns')}
                    className="w-full btn btn-primary justify-center text-xs py-1.5"
                  >
                    + New campaign
                  </button>
                </div>
              </div>
            </div>
          </div>

          {/* Quick actions */}
          <div className="card">
            <div className="card-header"><h3 className="card-title">Quick actions</h3></div>
            <div className="card-body">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                {[
                  { icon: '👥', label: 'Import contacts', to: '/contacts' },
                  { icon: '📢', label: 'New campaign',    to: '/campaigns' },
                  { icon: '🌿', label: 'Edit flow',       to: '/flow' },
                  { icon: '💳', label: 'Recharge wallet', to: '/wallet' },
                ].map((a) => (
                  <button
                    key={a.label}
                    onClick={() => navigate(a.to)}
                    className="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:border-brand-300 hover:bg-brand-50 transition-all text-center"
                  >
                    <span className="text-2xl">{a.icon}</span>
                    <span className="text-xs text-gray-600 font-medium">{a.label}</span>
                  </button>
                ))}
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
