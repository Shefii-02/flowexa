// src/pages/analytics/AnalyticsPage.tsx
import { useEffect, useState } from 'react'
import { analyticsApi } from '@/api'
import { StatCard, Spinner } from '@/components/ui'
import { fmt } from '@/utils'
import {
  ResponsiveContainer, BarChart, Bar, LineChart, Line,
  XAxis, YAxis, Tooltip, CartesianGrid, PieChart, Pie, Cell, Legend,
} from 'recharts'

const COLORS = ['#1D9E75','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#06b6d4']

const Section = ({ title, children }: { title: string; children: React.ReactNode }) => (
  <div className="card">
    <div className="card-header"><h3 className="card-title">{title}</h3></div>
    <div className="card-body">{children}</div>
  </div>
)

const TABS = [
  { id: 'overview',   label: '📊 Overview' },
  { id: 'messages',   label: '💬 Messages' },
  { id: 'campaigns',  label: '📢 Campaigns' },
  { id: 'leads',      label: '🎯 Leads' },
  { id: 'staff',      label: '👥 Staff' },
] as const

type TabId = (typeof TABS)[number]['id']

export default function AnalyticsPage() {
  const [tab,        setTab]        = useState<TabId>('overview')
  const [overview,   setOverview]   = useState<any>(null)
  const [campaigns,  setCampaigns]  = useState<any>(null)
  const [leadsData,  setLeadsData]  = useState<any>(null)
  const [messages,   setMessages]   = useState<any>(null)
  const [staffData,  setStaffData]  = useState<any>(null)
  const [loading,    setLoading]    = useState(true)

  useEffect(() => {
    Promise.all([
      analyticsApi.overview(),
      analyticsApi.campaigns(),
      analyticsApi.leads(),
      analyticsApi.messages(),
      analyticsApi.staff(),
    ]).then(([o, c, l, m, s]) => {
      setOverview(o.data)
      setCampaigns(c.data)
      setLeadsData(l.data)
      setMessages(m.data)
      setStaffData(s.data)
    }).finally(() => setLoading(false))
  }, [])

  if (loading) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>

  const dailyMsgData = messages?.daily_30_days
    ? Object.entries(messages.daily_30_days).map(([date, v]: any) => ({
        date: date.slice(5), inbound: v.inbound, outbound: v.outbound,
      }))
    : []

  const leadsStageData = leadsData?.by_stage
    ? Object.entries(leadsData.by_stage).map(([stage, count]) => ({
        name: stage.replace(/_/g,' '), value: count as number,
      }))
    : []

  const campaignMonthData = campaigns?.monthly?.map((m: any) => ({
    month: m.month,
    sent:  m.messages_sent || 0,
    delivered: m.delivered || 0,
    read:  m.total_read || 0,
  })) || []

  return (
    <div className="space-y-5">
      <div><h1 className="page-title">Analytics</h1><p className="page-sub">Platform performance overview</p></div>

      {/* Tab bar */}
      <div className="flex gap-1 border-b border-gray-200 overflow-x-auto">
        {TABS.map(t => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={[
              'px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors',
              tab === t.id
                ? 'border-indigo-500 text-indigo-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
            ].join(' ')}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* ── Overview tab ── */}
      {tab === 'overview' && (
        <div className="space-y-5">
          {overview && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <StatCard label="Total contacts"    value={fmt.number(overview.contacts?.total)} sub={`+${overview.contacts?.new_today} today`} icon="👥" />
              <StatCard label="Total leads"       value={fmt.number(overview.leads?.total)}    sub={`${overview.leads?.conversion_rate}% converted`} icon="🎯" color="text-brand-600" />
              <StatCard label="Messages sent"     value={fmt.number(overview.messages?.total_sent)} sub={`${overview.messages?.sent_today} today`} icon="💬" />
              <StatCard label="Wallet balance"    value={fmt.number(overview.wallet?.balance)} sub={`${fmt.number(overview.wallet?.total_used)} used`} icon="💳" />
            </div>
          )}
          {dailyMsgData.length > 0 && (
            <Section title="Message volume — last 30 days">
              <ResponsiveContainer width="100%" height={220}>
                <LineChart data={dailyMsgData}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="date" tick={{ fontSize: 11 }} interval={4} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                  <Legend />
                  <Line type="monotone" dataKey="outbound" stroke="#1D9E75" strokeWidth={2} dot={false} name="Outbound" />
                  <Line type="monotone" dataKey="inbound"  stroke="#3b82f6" strokeWidth={2} dot={false} name="Inbound" />
                </LineChart>
              </ResponsiveContainer>
            </Section>
          )}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {leadsStageData.length > 0 && (
              <Section title="Lead pipeline">
                <div className="flex items-center gap-4">
                  <ResponsiveContainer width="50%" height={180}>
                    <PieChart>
                      <Pie data={leadsStageData} cx="50%" cy="50%" innerRadius={45} outerRadius={70} dataKey="value" paddingAngle={3}>
                        {leadsStageData.map((_: any, i: number) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
                      </Pie>
                      <Tooltip contentStyle={{ fontSize: 12 }} />
                    </PieChart>
                  </ResponsiveContainer>
                  <div className="flex-1 space-y-2">
                    {leadsStageData.map((d: any, i: number) => (
                      <div key={d.name} className="flex items-center justify-between text-sm">
                        <div className="flex items-center gap-2">
                          <span className="w-2.5 h-2.5 rounded-full" style={{ background: COLORS[i % COLORS.length] }} />
                          <span className="capitalize text-gray-600">{d.name}</span>
                        </div>
                        <span className="font-medium">{fmt.number(d.value)}</span>
                      </div>
                    ))}
                    <div className="pt-2 border-t text-sm flex justify-between">
                      <span className="text-gray-500">Conversion</span>
                      <span className="font-semibold text-brand-600">{leadsData?.conversion_rate}%</span>
                    </div>
                  </div>
                </div>
              </Section>
            )}
            {campaignMonthData.length > 0 && (
              <Section title="Campaign performance — 6 months">
                <ResponsiveContainer width="100%" height={180}>
                  <BarChart data={campaignMonthData}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                    <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                    <YAxis tick={{ fontSize: 11 }} />
                    <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                    <Legend />
                    <Bar dataKey="sent"      fill="#1D9E75" name="Sent" radius={[3,3,0,0]} />
                    <Bar dataKey="delivered" fill="#3b82f6" name="Delivered" radius={[3,3,0,0]} />
                    <Bar dataKey="read"      fill="#f59e0b" name="Read" radius={[3,3,0,0]} />
                  </BarChart>
                </ResponsiveContainer>
              </Section>
            )}
          </div>
        </div>
      )}

      {/* ── Messages tab ── */}
      {tab === 'messages' && (
        <div className="space-y-5">
          {messages && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <StatCard label="Total sent"      value={fmt.number(messages.total_sent)}      icon="📤" />
              <StatCard label="Sent today"      value={fmt.number(messages.sent_today)}      icon="📅" />
              <StatCard label="Inbound total"   value={fmt.number(messages.inbound_total)}   icon="📥" />
              <StatCard label="Sent this month" value={fmt.number(messages.sent_this_month)} icon="📊" />
            </div>
          )}
          {dailyMsgData.length > 0 && (
            <Section title="Daily message volume — last 30 days">
              <ResponsiveContainer width="100%" height={280}>
                <LineChart data={dailyMsgData}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="date" tick={{ fontSize: 11 }} interval={2} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                  <Legend />
                  <Line type="monotone" dataKey="outbound" stroke="#1D9E75" strokeWidth={2} dot={false} name="Outbound" />
                  <Line type="monotone" dataKey="inbound"  stroke="#3b82f6" strokeWidth={2} dot={false} name="Inbound" />
                </LineChart>
              </ResponsiveContainer>
            </Section>
          )}
          {messages?.by_type && Object.keys(messages.by_type).length > 0 && (
            <Section title="Messages by type">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                {Object.entries(messages.by_type).map(([type, count]: any) => (
                  <div key={type} className="bg-gray-50 rounded-xl p-4 text-center">
                    <p className="text-2xl font-bold text-gray-900 tabular-nums">{fmt.number(count)}</p>
                    <p className="text-xs text-gray-500 mt-1 capitalize">{type}</p>
                  </div>
                ))}
              </div>
            </Section>
          )}
        </div>
      )}

      {/* ── Campaigns tab ── */}
      {tab === 'campaigns' && (
        <div className="space-y-5">
          {campaigns && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <StatCard label="Total campaigns"     value={fmt.number(campaigns.total)}      icon="📢" />
              <StatCard label="Running"             value={fmt.number(campaigns.running)}    icon="▶️" color="text-green-600" />
              <StatCard label="Completed"           value={fmt.number(campaigns.completed)}  icon="✅" />
              <StatCard label="Total messages sent" value={fmt.number(campaigns.messages_sent)} icon="💬" />
            </div>
          )}
          {campaignMonthData.length > 0 && (
            <Section title="Monthly campaign volume">
              <ResponsiveContainer width="100%" height={280}>
                <BarChart data={campaignMonthData}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                  <Legend />
                  <Bar dataKey="sent"      fill="#1D9E75" name="Sent" radius={[3,3,0,0]} />
                  <Bar dataKey="delivered" fill="#3b82f6" name="Delivered" radius={[3,3,0,0]} />
                  <Bar dataKey="read"      fill="#f59e0b" name="Read" radius={[3,3,0,0]} />
                </BarChart>
              </ResponsiveContainer>
            </Section>
          )}
          {campaigns?.top_campaigns?.length > 0 && (
            <Section title="Top campaigns">
              <div className="table-wrapper">
                <table className="table">
                  <thead><tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Delivered</th><th>Read</th></tr></thead>
                  <tbody>
                    {campaigns.top_campaigns.map((c: any) => (
                      <tr key={c.id}>
                        <td className="font-medium">{c.name}</td>
                        <td><span className="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 capitalize">{c.status}</span></td>
                        <td>{fmt.number(c.messages_sent)}</td>
                        <td>{fmt.number(c.delivered)}</td>
                        <td>{fmt.number(c.total_read)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Section>
          )}
        </div>
      )}

      {/* ── Leads tab ── */}
      {tab === 'leads' && (
        <div className="space-y-5">
          {leadsData && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <StatCard label="Total leads"   value={fmt.number(leadsData.total)}           icon="🎯" />
              <StatCard label="New today"     value={fmt.number(leadsData.new_today)}        icon="📅" />
              <StatCard label="New this month" value={fmt.number(leadsData.new_month)}       icon="📆" />
              <StatCard label="Conversion"    value={`${leadsData.conversion_rate ?? 0}%`}  icon="✅" color="text-brand-600" />
            </div>
          )}
          {leadsStageData.length > 0 && (
            <Section title="Lead pipeline by stage">
              <div className="flex items-center gap-6">
                <ResponsiveContainer width="40%" height={220}>
                  <PieChart>
                    <Pie data={leadsStageData} cx="50%" cy="50%" innerRadius={55} outerRadius={85} dataKey="value" paddingAngle={3}>
                      {leadsStageData.map((_: any, i: number) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
                    </Pie>
                    <Tooltip contentStyle={{ fontSize: 12 }} />
                  </PieChart>
                </ResponsiveContainer>
                <div className="flex-1 space-y-3">
                  {leadsStageData.map((d: any, i: number) => (
                    <div key={d.name} className="flex items-center gap-3">
                      <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ background: COLORS[i % COLORS.length] }} />
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center justify-between mb-0.5">
                          <span className="text-sm capitalize text-gray-700">{d.name}</span>
                          <span className="text-sm font-semibold tabular-nums">{fmt.number(d.value)}</span>
                        </div>
                        <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                          <div className="h-full rounded-full transition-all" style={{ width: `${Math.round((d.value / (leadsData?.total || 1)) * 100)}%`, background: COLORS[i % COLORS.length] }} />
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </Section>
          )}
          {leadsData?.by_source && Object.keys(leadsData.by_source).length > 0 && (
            <Section title="Leads by source">
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                {Object.entries(leadsData.by_source).map(([src, count]: any) => (
                  <div key={src} className="bg-gray-50 rounded-xl p-4 text-center">
                    <p className="text-2xl font-bold text-gray-900 tabular-nums">{fmt.number(count)}</p>
                    <p className="text-xs text-gray-500 mt-1 capitalize">{src.replace(/_/g, ' ')}</p>
                  </div>
                ))}
              </div>
            </Section>
          )}
        </div>
      )}

      {/* ── Staff tab ── */}
      {tab === 'staff' && (
        <div className="space-y-5">
          {staffData?.staff?.length > 0 ? (
            <Section title="Staff performance">
              <div className="table-wrapper">
                <table className="table">
                  <thead><tr><th>Name</th><th>Department</th><th>Total leads</th><th>Enrolled</th><th>Conversion</th><th>Capacity</th></tr></thead>
                  <tbody>
                    {staffData.staff.map((s: any) => (
                      <tr key={s.id}>
                        <td className="font-medium">{s.name}</td>
                        <td className="text-gray-500">{s.department || '—'}</td>
                        <td>{fmt.number(s.leads?.total)}</td>
                        <td>{fmt.number(s.leads?.enrolled)}</td>
                        <td><span className="font-medium text-brand-600">{s.conversion_rate}%</span></td>
                        <td>
                          <div className="flex items-center gap-2">
                            <div className="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden max-w-[60px]">
                              <div className="h-full bg-brand-500 rounded-full" style={{ width: `${s.capacity_percent}%` }} />
                            </div>
                            <span className="text-xs text-gray-500">{s.capacity_percent}%</span>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Section>
          ) : (
            <div className="py-16 text-center text-sm text-gray-400">No staff data available.</div>
          )}
        </div>
      )}
    </div>
  )
}
