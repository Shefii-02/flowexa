import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid,
  Tooltip, ResponsiveContainer, Legend, PieChart, Pie, Cell,
} from 'recharts'
import { analyticsApi } from '@/api'
import { Spinner } from '@/components/ui'
import { useAppSelector, usePermission } from '@/store'
import { fmt } from '@/utils'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Overview {
  contacts:  { total: number; opted_in: number; new_today: number; new_month: number }
  leads:     { total: number; new: number; in_progress: number; enrolled: number; lost: number; new_today: number; conversion_rate: number }
  messages:  { total_sent: number; sent_today: number; sent_this_month: number; inbound_total: number }
  wallet:    { balance: number; total_used: number; total_purchased: number }
  campaigns: { total: number; running: number; completed: number }
}
interface CampaignItem {
  id: number; name: string; status: string; total_contacts: number
  delivered: number; read: number; failed: number; delivery_rate: number; read_rate: number; created_at: string
}
interface CampaignData {
  recent: CampaignItem[]
  by_status: Record<string, number>
  monthly: { month: string; total: number; messages_sent: number; delivered: number; total_read: number }[]
}
interface StaffMember {
  id: number; name: string; email: string; department: string | null; role: string; max_leads: number
  leads: { total: number; new: number; contacted: number; follow_up: number; enrolled: number; lost: number; active: number }
  conversion_rate: number; capacity_percent: number
}
interface StaffData { staff: StaffMember[]; top_converter: StaffMember | null; highest_capacity: StaffMember | null }
interface LeadsData {
  total: number; conversion_rate: number
  by_stage: Record<string, number>; by_category: Record<string, number>; by_source: Record<string, number>
  monthly_new: { month: string; total: number }[]
}
interface MessagesData {
  total_inbound: number; total_outbound: number
  by_type: Record<string, number>; by_status: Record<string, number>
  daily_30_days: Record<string, { inbound: number; outbound: number }>
}
interface WalletData {
  balance: number; total_used: number; total_purchased: number; burn_rate_daily: number
  monthly: Record<string, { credit: number; debit: number }>
  recent_transactions: { id: number; type: string; amount: number; description: string; created_at: string }[]
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const BRAND = '#6366f1'
const GREEN = '#16a34a'
const AMBER = '#d97706'
const RED   = '#dc2626'
const GRAY  = '#6b7280'

const STAGE_COLORS: Record<string, string> = {
  new: BRAND, contacted: '#0ea5e9', follow_up: AMBER, enrolled: GREEN, lost: RED,
}
const PIE_PALETTE = [BRAND, '#0ea5e9', GREEN, AMBER, RED, '#8b5cf6', '#ec4899', GRAY]

function pct(n: number, total: number) {
  return total > 0 ? Math.round((n / total) * 100) : 0
}

function MiniBar({ value, max, color = BRAND }: { value: number; max: number; color?: string }) {
  const w = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0
  return (
    <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden w-full">
      <div style={{ width: `${w}%`, background: color }} className="h-full rounded-full transition-all" />
    </div>
  )
}

function StatCard({ icon, label, value, sub, subColor = '' }: { icon: string; label: string; value: string | number; sub?: string; subColor?: string }) {
  return (
    <div className="card p-5 space-y-3">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium text-gray-500 uppercase tracking-wide">{label}</span>
        <span className="text-2xl">{icon}</span>
      </div>
      <div className="text-2xl font-bold text-gray-900">{value}</div>
      {sub && <div className={`text-xs ${subColor || 'text-gray-400'}`}>{sub}</div>}
    </div>
  )
}

function SectionHeader({ title, action, onAction }: { title: string; action?: string; onAction?: () => void }) {
  return (
    <div className="flex items-center justify-between mb-4">
      <h2 className="text-sm font-semibold text-gray-800">{title}</h2>
      {action && <button onClick={onAction} className="text-xs text-indigo-600 hover:underline">{action} →</button>}
    </div>
  )
}

// ── Main component ────────────────────────────────────────────────────────────

export default function DashboardPage() {
  const navigate    = useNavigate()
  const user        = useAppSelector((s) => s.auth.user)
  const canViewStaff = usePermission('staff.view')
  const canViewCamp  = usePermission('campaigns.view')
  const canViewFlow  = usePermission('flow.view')
  const waConfig     = user?.company?.wa_config ?? 'wallet'

  const [overview,  setOverview]  = useState<Overview | null>(null)
  const [campaigns, setCampaigns] = useState<CampaignData | null>(null)
  const [staff,     setStaff]     = useState<StaffData | null>(null)
  const [leads,     setLeads]     = useState<LeadsData | null>(null)
  const [messages,  setMessages]  = useState<MessagesData | null>(null)
  const [wallet,    setWallet]    = useState<WalletData | null>(null)
  const [loading,   setLoading]   = useState(true)

  useEffect(() => {
    Promise.all([
      analyticsApi.overview(),
      analyticsApi.campaigns(),
      analyticsApi.staff(),
      analyticsApi.leads(),
      analyticsApi.messages(),
      analyticsApi.wallet(),
    ]).then(([ov, cam, st, ld, msg, wal]) => {
      setOverview(ov.data)
      setCampaigns(cam.data)
      setStaff(st.data)
      setLeads(ld.data)
      setMessages(msg.data)
      setWallet(wal.data)
    }).finally(() => setLoading(false))
  }, [])

  if (loading) return (
    <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>
  )

  // ── Derived data ─────────────────────────────────────────────────────────

  const hour = new Date().getHours()
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'

  // 30-day message chart data
  const msgChartData = messages
    ? Object.entries(messages.daily_30_days)
        .sort(([a], [b]) => a.localeCompare(b))
        .slice(-14)
        .map(([date, v]) => ({
          date: new Date(date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }),
          Outbound: v.outbound,
          Inbound: v.inbound,
        }))
    : []

  // Lead funnel data for pie
  const leadStageData = leads
    ? Object.entries(leads.by_stage).map(([stage, count]) => ({
        name: stage.replace('_', ' '),
        value: count as number,
        color: STAGE_COLORS[stage] ?? GRAY,
      }))
    : []

  // Campaign monthly trend
  const campTrendData = campaigns?.monthly?.map(m => ({
    month: m.month,
    Sent: m.messages_sent,
    Delivered: m.delivered,
    Read: m.total_read,
  })) ?? []

  // Wallet monthly data
  const walletChartData = wallet
    ? Object.entries(wallet.monthly)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([month, v]) => ({ month, Credit: v.credit, Debit: v.debit }))
    : []

  return (
    <div className="space-y-6 pb-8">

      {/* ── Greeting + alerts ───────────────────────────────────────────── */}
      <div>
        <h1 className="page-title">{greeting}, {user?.name?.split(' ')[0]} 👋</h1>
        <p className="page-sub">Here's your company overview for {user?.company?.name}</p>
      </div>

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

      {overview?.wallet.balance !== undefined && overview.wallet.balance < 500 && waConfig === 'wallet' && (
        <div className="bg-red-50 border border-red-200 rounded-xl px-5 py-3 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span>⚠️</span>
            <p className="text-sm text-red-800 font-medium">
              Low wallet — {fmt.number(overview.wallet.balance)} messages left
            </p>
          </div>
          <button onClick={() => navigate('/wallet')} className="text-xs text-red-700 font-medium hover:underline">
            Recharge →
          </button>
        </div>
      )}

      {/* ── KPI stat cards ───────────────────────────────────────────────── */}
      {overview && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard icon="👥" label="Total contacts"
            value={fmt.number(overview.contacts.total)}
            sub={`+${overview.contacts.new_today} today · ${fmt.number(overview.contacts.new_month)} this month`} />
          <StatCard icon="🎯" label="Active leads"
            value={fmt.number(overview.leads.total)}
            sub={`${overview.leads.conversion_rate}% conversion rate`}
            subColor="text-indigo-600" />
          <StatCard icon="💬" label="Messages sent"
            value={fmt.number(overview.messages.total_sent)}
            sub={`${fmt.number(overview.messages.sent_today)} today · ${fmt.number(overview.messages.sent_this_month)} this month`} />
          {waConfig === 'wallet' ? (
            <StatCard icon="💳" label="Wallet balance"
              value={fmt.number(overview.wallet.balance)}
              sub={`${fmt.number(overview.wallet.total_used)} used of ${fmt.number(overview.wallet.total_purchased)} purchased`}
              subColor={overview.wallet.balance < 500 ? 'text-red-500' : 'text-gray-400'} />
          ) : (
            <StatCard icon="📢" label="Campaigns"
              value={fmt.number(overview.campaigns?.total ?? 0)}
              sub={`${overview.campaigns?.running ?? 0} running · ${overview.campaigns?.completed ?? 0} completed`} />
          )}
        </div>
      )}

      {/* ── Message volume (30-day) + Lead funnel ────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {/* 30-day message bar chart */}
        <div className="card p-5 lg:col-span-2">
          <SectionHeader title="Messages — last 14 days" action="View logs" onAction={() => navigate('/message-logs')} />
          {msgChartData.length > 0 ? (
            <ResponsiveContainer width="100%" height={200}>
              <BarChart data={msgChartData} barSize={14} barGap={2}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" vertical={false} />
                <XAxis dataKey="date" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={32} />
                <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                <Legend iconSize={10} wrapperStyle={{ fontSize: 12 }} />
                <Bar dataKey="Outbound" fill={BRAND} radius={[3,3,0,0]} />
                <Bar dataKey="Inbound"  fill="#a5b4fc" radius={[3,3,0,0]} />
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <div className="h-48 flex items-center justify-center text-sm text-gray-400">No message data yet</div>
          )}
        </div>

        {/* Lead stage pie */}
        <div className="card p-5">
          <SectionHeader title="Lead funnel" action="View leads" onAction={() => navigate('/leads')} />
          {leadStageData.length > 0 ? (
            <>
              <ResponsiveContainer width="100%" height={140}>
                <PieChart>
                  <Pie data={leadStageData} cx="50%" cy="50%" innerRadius={40} outerRadius={65}
                    dataKey="value" paddingAngle={2}>
                    {leadStageData.map((entry, i) => (
                      <Cell key={i} fill={entry.color} />
                    ))}
                  </Pie>
                  <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                </PieChart>
              </ResponsiveContainer>
              <div className="space-y-1.5 mt-2">
                {leadStageData.map((s, i) => (
                  <div key={i} className="flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: s.color }} />
                      <span className="text-gray-600 capitalize">{s.name}</span>
                    </div>
                    <span className="font-medium text-gray-800">{fmt.number(s.value)}</span>
                  </div>
                ))}
              </div>
            </>
          ) : (
            <div className="h-48 flex items-center justify-center text-sm text-gray-400">No lead data yet</div>
          )}
        </div>
      </div>

      {/* ── Campaigns ────────────────────────────────────────────────────── */}
      {canViewCamp && campaigns && (
        <div className="card p-5">
          <SectionHeader title="Recent campaigns" action="All campaigns" onAction={() => navigate('/campaigns')} />

          {/* Status summary pills */}
          <div className="flex gap-2 mb-4 flex-wrap">
            {Object.entries(campaigns.by_status ?? {}).map(([status, count]) => (
              <span key={status} className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                {status === 'running' ? '🟢' : status === 'completed' ? '✅' : status === 'draft' ? '📝' : '⏸️'} {status} <strong>{count as number}</strong>
              </span>
            ))}
          </div>

          {(campaigns.recent?.length ?? 0) > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-left text-gray-400 border-b border-gray-100">
                    <th className="pb-2 pr-4 font-medium">Campaign</th>
                    <th className="pb-2 pr-4 font-medium">Status</th>
                    <th className="pb-2 pr-4 font-medium text-right">Recipients</th>
                    <th className="pb-2 pr-4 font-medium text-right">Delivered</th>
                    <th className="pb-2 pr-4 font-medium text-right">Read</th>
                    <th className="pb-2 font-medium text-right">Failed</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {campaigns.recent.slice(0, 8).map(c => (
                    <tr key={c.id} className="hover:bg-gray-50">
                      <td className="py-2.5 pr-4">
                        <div className="font-medium text-gray-800 truncate max-w-[180px]">{c.name}</div>
                        <div className="text-gray-400">{new Date(c.created_at).toLocaleDateString('en-IN')}</div>
                      </td>
                      <td className="py-2.5 pr-4">
                        <span className={`inline-flex px-2 py-0.5 rounded-full font-medium ${
                          c.status === 'running' ? 'bg-green-100 text-green-700' :
                          c.status === 'completed' ? 'bg-indigo-100 text-indigo-700' :
                          c.status === 'draft' ? 'bg-gray-100 text-gray-600' : 'bg-yellow-100 text-yellow-700'
                        }`}>{c.status}</span>
                      </td>
                      <td className="py-2.5 pr-4 text-right text-gray-700">{fmt.number(c.total_contacts)}</td>
                      <td className="py-2.5 pr-4">
                        <div className="text-right text-gray-700">{c.delivery_rate}%</div>
                        <MiniBar value={c.delivered} max={c.total_contacts} color={GREEN} />
                      </td>
                      <td className="py-2.5 pr-4">
                        <div className="text-right text-gray-700">{c.read_rate}%</div>
                        <MiniBar value={c.read} max={c.total_contacts} color={BRAND} />
                      </td>
                      <td className="py-2.5 text-right">
                        <span className={c.failed > 0 ? 'text-red-500 font-medium' : 'text-gray-400'}>{fmt.number(c.failed)}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="text-center py-8 text-sm text-gray-400">
              <p>No campaigns yet.</p>
              <button onClick={() => navigate('/campaigns')} className="mt-2 text-indigo-600 hover:underline text-xs">Create your first campaign →</button>
            </div>
          )}
        </div>
      )}

      {/* ── Campaign trend + Wallet ───────────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {/* Campaign monthly trend */}
        {canViewCamp && campTrendData.length > 0 && (
          <div className="card p-5">
            <SectionHeader title="Campaign performance (6 months)" />
            <ResponsiveContainer width="100%" height={180}>
              <LineChart data={campTrendData}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" vertical={false} />
                <XAxis dataKey="month" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={32} />
                <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                <Legend iconSize={10} wrapperStyle={{ fontSize: 12 }} />
                <Line type="monotone" dataKey="Sent"      stroke={GRAY}  strokeWidth={2} dot={false} />
                <Line type="monotone" dataKey="Delivered" stroke={GREEN} strokeWidth={2} dot={false} />
                <Line type="monotone" dataKey="Read"      stroke={BRAND} strokeWidth={2} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          </div>
        )}

        {/* Wallet monthly usage */}
        {waConfig === 'wallet' && walletChartData.length > 0 && (
          <div className="card p-5">
            <SectionHeader title="Wallet usage (6 months)" action="View wallet" onAction={() => navigate('/wallet')} />
            <div className="flex gap-4 mb-3">
              {wallet && (
                <>
                  <div><div className="text-xs text-gray-400">Balance</div><div className="font-bold text-gray-800">{fmt.number(wallet.balance)}</div></div>
                  <div><div className="text-xs text-gray-400">Daily burn</div><div className="font-bold text-amber-600">{fmt.number(wallet.burn_rate_daily)}/day</div></div>
                  <div><div className="text-xs text-gray-400">Total used</div><div className="font-bold text-gray-800">{fmt.number(wallet.total_used)}</div></div>
                </>
              )}
            </div>
            <ResponsiveContainer width="100%" height={140}>
              <BarChart data={walletChartData} barSize={16} barGap={2}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" vertical={false} />
                <XAxis dataKey="month" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={32} />
                <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                <Legend iconSize={10} wrapperStyle={{ fontSize: 12 }} />
                <Bar dataKey="Credit" fill={GREEN} radius={[3,3,0,0]} />
                <Bar dataKey="Debit"  fill={RED}   radius={[3,3,0,0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </div>

      {/* ── Staff performance ─────────────────────────────────────────────── */}
      {canViewStaff && staff && staff.staff.length > 0 && (
        <div className="card p-5">
          <SectionHeader title="Staff performance" action="Manage staff" onAction={() => navigate('/staff')} />

          {/* Top performers summary */}
          {(staff.top_converter || staff.highest_capacity) && (
            <div className="grid grid-cols-2 gap-3 mb-4">
              {staff.top_converter && (
                <div className="bg-green-50 border border-green-200 rounded-lg p-3">
                  <div className="text-xs text-green-600 font-medium mb-1">🏆 Top converter</div>
                  <div className="font-semibold text-gray-800 text-sm">{staff.top_converter.name}</div>
                  <div className="text-xs text-gray-500">{staff.top_converter.conversion_rate}% conversion</div>
                </div>
              )}
              {staff.highest_capacity && (
                <div className="bg-amber-50 border border-amber-200 rounded-lg p-3">
                  <div className="text-xs text-amber-600 font-medium mb-1">📊 Highest capacity</div>
                  <div className="font-semibold text-gray-800 text-sm">{staff.highest_capacity.name}</div>
                  <div className="text-xs text-gray-500">{staff.highest_capacity.capacity_percent}% capacity used</div>
                </div>
              )}
            </div>
          )}

          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="text-left text-gray-400 border-b border-gray-100">
                  <th className="pb-2 pr-4 font-medium">Staff member</th>
                  <th className="pb-2 pr-4 font-medium">Role</th>
                  <th className="pb-2 pr-4 font-medium text-right">Total leads</th>
                  <th className="pb-2 pr-4 font-medium text-right">Active</th>
                  <th className="pb-2 pr-4 font-medium text-right">Enrolled</th>
                  <th className="pb-2 pr-4 font-medium text-right">Conversion</th>
                  <th className="pb-2 font-medium">Capacity</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {staff.staff.map(s => (
                  <tr key={s.id} className="hover:bg-gray-50">
                    <td className="py-2.5 pr-4">
                      <div className="font-medium text-gray-800">{s.name}</div>
                      {s.department && <div className="text-gray-400">{s.department}</div>}
                    </td>
                    <td className="py-2.5 pr-4 text-gray-500">{s.role}</td>
                    <td className="py-2.5 pr-4 text-right text-gray-700">{fmt.number(s.leads.total)}</td>
                    <td className="py-2.5 pr-4 text-right text-indigo-600 font-medium">{fmt.number(s.leads.active)}</td>
                    <td className="py-2.5 pr-4 text-right text-green-600 font-medium">{fmt.number(s.leads.enrolled)}</td>
                    <td className="py-2.5 pr-4 text-right">
                      <span className={`font-semibold ${s.conversion_rate >= 50 ? 'text-green-600' : s.conversion_rate >= 25 ? 'text-amber-600' : 'text-gray-500'}`}>
                        {s.conversion_rate}%
                      </span>
                    </td>
                    <td className="py-2.5 w-32">
                      <div className="flex items-center gap-2">
                        <div className="flex-1">
                          <MiniBar
                            value={s.leads.active}
                            max={s.max_leads || s.leads.active || 1}
                            color={s.capacity_percent > 90 ? RED : s.capacity_percent > 70 ? AMBER : GREEN}
                          />
                        </div>
                        <span className="text-gray-400 w-8 text-right">{s.capacity_percent}%</span>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ── Leads breakdown ───────────────────────────────────────────────── */}
      {leads && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">

          {/* By source */}
          {Object.keys(leads.by_source).length > 0 && (
            <div className="card p-5">
              <SectionHeader title="Leads by source" />
              <div className="space-y-2">
                {Object.entries(leads.by_source)
                  .sort(([, a], [, b]) => (b as number) - (a as number))
                  .slice(0, 6)
                  .map(([source, count], i) => (
                    <div key={source}>
                      <div className="flex justify-between text-xs mb-1">
                        <span className="text-gray-600 capitalize">{source || 'Unknown'}</span>
                        <span className="font-medium text-gray-800">{fmt.number(count as number)}</span>
                      </div>
                      <MiniBar value={count as number} max={leads.total} color={PIE_PALETTE[i % PIE_PALETTE.length]} />
                    </div>
                  ))}
              </div>
            </div>
          )}

          {/* By category */}
          {Object.keys(leads.by_category).length > 0 && (
            <div className="card p-5">
              <SectionHeader title="Leads by category" />
              <div className="space-y-2">
                {Object.entries(leads.by_category)
                  .sort(([, a], [, b]) => (b as number) - (a as number))
                  .slice(0, 6)
                  .map(([cat, count], i) => (
                    <div key={cat}>
                      <div className="flex justify-between text-xs mb-1">
                        <span className="text-gray-600 capitalize">{cat || 'Uncategorised'}</span>
                        <span className="font-medium text-gray-800">{fmt.number(count as number)}</span>
                      </div>
                      <MiniBar value={count as number} max={leads.total} color={PIE_PALETTE[i % PIE_PALETTE.length]} />
                    </div>
                  ))}
              </div>
            </div>
          )}

          {/* Monthly new leads trend */}
          {leads.monthly_new.length > 0 && (
            <div className="card p-5">
              <SectionHeader title="New leads (6 months)" />
              <ResponsiveContainer width="100%" height={160}>
                <BarChart data={leads.monthly_new.slice(-6)} barSize={20}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" vertical={false} />
                  <XAxis dataKey="month" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                  <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={28} />
                  <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
                  <Bar dataKey="total" fill={BRAND} name="New leads" radius={[3,3,0,0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </div>
      )}

      {/* ── Message breakdown ─────────────────────────────────────────────── */}
      {messages && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div className="card p-5">
            <SectionHeader title="Messages by type" />
            {Object.keys(messages.by_type).length > 0 ? (
              <div className="space-y-2">
                {Object.entries(messages.by_type)
                  .sort(([, a], [, b]) => (b as number) - (a as number))
                  .map(([type, count], i) => {
                    const total = messages.total_outbound + messages.total_inbound
                    return (
                      <div key={type}>
                        <div className="flex justify-between text-xs mb-1">
                          <span className="text-gray-600 capitalize">{type}</span>
                          <span className="font-medium text-gray-800">{pct(count as number, total)}%</span>
                        </div>
                        <MiniBar value={count as number} max={total} color={PIE_PALETTE[i % PIE_PALETTE.length]} />
                      </div>
                    )
                  })}
              </div>
            ) : <div className="text-sm text-gray-400">No data</div>}
          </div>

          <div className="card p-5">
            <SectionHeader title="Message status" />
            {Object.keys(messages.by_status).length > 0 ? (
              <div className="space-y-2">
                {Object.entries(messages.by_status)
                  .sort(([, a], [, b]) => (b as number) - (a as number))
                  .map(([status, count], i) => {
                    const total = messages.total_outbound
                    return (
                      <div key={status}>
                        <div className="flex justify-between text-xs mb-1">
                          <span className="text-gray-600 capitalize">{status}</span>
                          <span className="font-medium text-gray-800">{fmt.number(count as number)}</span>
                        </div>
                        <MiniBar
                          value={count as number}
                          max={total}
                          color={status === 'delivered' ? GREEN : status === 'read' ? BRAND : status === 'failed' ? RED : GRAY}
                        />
                      </div>
                    )
                  })}
              </div>
            ) : <div className="text-sm text-gray-400">No data</div>}
          </div>

          <div className="card p-5">
            <SectionHeader title="Inbound vs Outbound" />
            <div className="flex gap-6 mb-4">
              <div>
                <div className="text-xs text-gray-400">Outbound</div>
                <div className="text-xl font-bold text-indigo-600">{fmt.number(messages.total_outbound)}</div>
              </div>
              <div>
                <div className="text-xs text-gray-400">Inbound</div>
                <div className="text-xl font-bold text-green-600">{fmt.number(messages.total_inbound)}</div>
              </div>
            </div>
            <ResponsiveContainer width="100%" height={110}>
              <PieChart>
                <Pie
                  data={[
                    { name: 'Outbound', value: messages.total_outbound },
                    { name: 'Inbound',  value: messages.total_inbound  },
                  ]}
                  cx="50%" cy="50%" innerRadius={30} outerRadius={50}
                  dataKey="value" paddingAngle={3}
                >
                  <Cell fill={BRAND} />
                  <Cell fill={GREEN} />
                </Pie>
                <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8 }} />
              </PieChart>
            </ResponsiveContainer>
          </div>
        </div>
      )}

      {/* ── Quick actions ─────────────────────────────────────────────────── */}
      <div className="card p-5">
        <SectionHeader title="Quick actions" />
        <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
          {[
            { icon: '👥', label: 'Import contacts',  to: '/contacts' },
            { icon: '📢', label: 'New campaign',      to: '/campaigns',    hide: !canViewCamp },
            { icon: '🌿', label: 'Flow builder',      to: '/flow-builders', hide: !canViewFlow },
            { icon: '💬', label: 'WA Message sender', to: '/wa-chat/message-sender' },
            { icon: '💳', label: 'Recharge wallet',   to: '/wallet',       hide: waConfig !== 'wallet' },
            { icon: '📊', label: 'WA Chat',           to: '/wa-chat/chats' },
          ].filter(a => !a.hide).map((a) => (
            <button key={a.label} onClick={() => navigate(a.to)}
              className="flex flex-col items-center gap-2 p-4 rounded-xl border border-gray-200 hover:border-indigo-300 hover:bg-indigo-50 transition-all text-center">
              <span className="text-2xl">{a.icon}</span>
              <span className="text-xs text-gray-600 font-medium leading-tight">{a.label}</span>
            </button>
          ))}
        </div>
      </div>

    </div>
  )
}
