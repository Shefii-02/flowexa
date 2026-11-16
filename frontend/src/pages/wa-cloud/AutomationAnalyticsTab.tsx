import { useEffect, useState } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { api } from '@/api/client'
import { TYPE_META } from './ReplyRoutingTab'

type A = {
  totals: { events: number; handled: number; fallback: number; skipped: number; failed: number; handled_rate: number }
  by_type: Record<string, number>
  by_number: { wa_phone_number_id: number | null; label: string; total: number; by_type: Record<string, number> }[]
  daily: ({ date: string } & Record<string, number | string>)[]
  handovers: { user_id: number; name: string; count: number }[]
  top_routes: { route_id: number; name: string; count: number }[]
  rules: { by_type: { rule_type: string; success: number; failed: number }[]; daily: { date: string; success: number; failed: number }[]; top: { rule_id: number; name: string; count: number }[] }
}
const TYPES = ['chatbot', 'ai_agent', 'handover', 'auto_reply', 'manual']
const RULE_LABEL: Record<string, string> = { welcome_message: 'Welcome', out_of_office: 'Out of office', lead_qualifier: 'Lead qualifier', keyword_trigger: 'Keyword', follow_up_reminder: 'Follow-up reminder', follow_up_agent: 'Follow-up agent', inactivity_trigger: 'Inactivity' }

const Kpi = ({ label, value, sub, color }: { label: string; value: string | number; sub?: string; color: string }) => (
  <div className="bg-white border border-gray-200 rounded-xl p-4"><div className="text-xs text-gray-500">{label}</div><div className="text-2xl font-extrabold mt-1" style={{ color }}>{value}</div>{sub && <div className="text-[11px] text-gray-400 mt-0.5">{sub}</div>}</div>
)
const Card = ({ title, children }: { title: string; children: React.ReactNode }) => <div className="bg-white border border-gray-200 rounded-xl p-4"><div className="text-sm font-semibold text-gray-900 mb-3">{title}</div>{children}</div>
const Empty = () => <div className="py-10 text-center text-sm text-gray-400">No data in this period yet</div>

export default function AutomationAnalyticsTab() {
  const [days, setDays] = useState(30)
  const [num, setNum] = useState('')
  const [numbers, setNumbers] = useState<{ id: number; label?: string; display_number?: string | null }[]>([])
  const [a, setA] = useState<A | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => { api.get('/wa-cloud/reply-routes/options').then(r => setNumbers(r.data.phone_numbers ?? [])).catch(() => {}) }, [])
  useEffect(() => {
    setLoading(true)
    api.get('/wa-cloud/reply-routes/analytics', { params: { days, ...(num ? { wa_phone_number_id: num } : {}) } }).then(r => setA(r.data)).catch(() => setA(null)).finally(() => setLoading(false))
  }, [days, num])

  const pie = TYPES.map(t => ({ name: TYPE_META[t].label, value: a?.by_type?.[t] ?? 0, color: TYPE_META[t].color })).filter(x => x.value > 0)
  const sel = 'border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white'

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div><h2 className="text-lg font-bold text-gray-900">Routing & automation analytics</h2><p className="text-sm text-gray-500">Where incoming conversations went, who got the handovers, and how your rules performed.</p></div>
        <div className="flex gap-2">
          <select value={num} onChange={e => setNum(e.target.value)} className={sel}><option value="">All numbers</option>{numbers.map(n => <option key={n.id} value={n.id}>{n.label || n.display_number || `#${n.id}`}</option>)}</select>
          <select value={days} onChange={e => setDays(Number(e.target.value))} className={sel}>{[7, 30, 90].map(d => <option key={d} value={d}>Last {d} days</option>)}</select>
        </div>
      </div>

      {loading ? <div className="text-center py-16 text-gray-400">Loading…</div> : !a ? <div className="text-center py-16 text-gray-400">Could not load analytics.</div> : (
        <div className="space-y-4">
          <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
            <Kpi label="Routed conversations" value={a.totals.events} color="#4f46e5" />
            <Kpi label="Answered" value={a.totals.handled} sub={`${a.totals.handled_rate}% answered or handed over`} color="#16a34a" />
            <Kpi label="Fallbacks used" value={a.totals.fallback} sub="AI could not answer" color="#f59e0b" />
            <Kpi label="Left for staff" value={a.totals.skipped} color="#64748b" />
            <Kpi label="Failed" value={a.totals.failed} sub="no staff / error" color="#dc2626" />
          </div>

          <div className="grid lg:grid-cols-3 gap-4">
            <div className="lg:col-span-2"><Card title="Conversations per day, by response type">
              {a.daily.length === 0 ? <Empty /> : <ResponsiveContainer width="100%" height={260}><BarChart data={a.daily}><CartesianGrid strokeDasharray="3 3" vertical={false} /><XAxis dataKey="date" tick={{ fontSize: 11 }} tickFormatter={(d: string) => d.slice(5)} /><YAxis allowDecimals={false} tick={{ fontSize: 11 }} /><Tooltip /><Legend />
                {TYPES.map(t => <Bar key={t} dataKey={t} name={TYPE_META[t].label} stackId="a" fill={TYPE_META[t].color} />)}</BarChart></ResponsiveContainer>}
            </Card></div>
            <Card title="Share by response type">
              {pie.length === 0 ? <Empty /> : <ResponsiveContainer width="100%" height={260}><PieChart><Pie data={pie} dataKey="value" nameKey="name" innerRadius={55} outerRadius={90} paddingAngle={2}>{pie.map(p => <Cell key={p.name} fill={p.color} />)}</Pie><Tooltip /><Legend /></PieChart></ResponsiveContainer>}
            </Card>
          </div>

          <div className="grid lg:grid-cols-2 gap-4">
            <Card title="By phone number">
              {a.by_number.length === 0 ? <Empty /> : <div className="space-y-2">{a.by_number.map(n => (
                <div key={String(n.wa_phone_number_id)}>
                  <div className="flex justify-between text-sm"><span className="font-medium text-gray-800">{n.label}</span><span className="text-gray-500">{n.total}</span></div>
                  <div className="flex h-2.5 rounded-full overflow-hidden bg-gray-100 mt-1">{TYPES.map(t => (n.by_type?.[t] ?? 0) > 0 && <div key={t} title={`${TYPE_META[t].label}: ${n.by_type[t]}`} style={{ width: `${(n.by_type[t] / n.total) * 100}%`, background: TYPE_META[t].color }} />)}</div>
                </div>))}</div>}
            </Card>
            <Card title="Sales-team handovers (per person)">
              {a.handovers.length === 0 ? <Empty /> : <ResponsiveContainer width="100%" height={Math.max(160, a.handovers.length * 34)}><BarChart data={a.handovers} layout="vertical"><CartesianGrid strokeDasharray="3 3" horizontal={false} /><XAxis type="number" allowDecimals={false} tick={{ fontSize: 11 }} /><YAxis type="category" dataKey="name" width={110} tick={{ fontSize: 12 }} /><Tooltip /><Bar dataKey="count" name="Chats" fill="#16a34a" radius={[0, 4, 4, 0]} /></BarChart></ResponsiveContainer>}
            </Card>
          </div>

          <div className="grid lg:grid-cols-2 gap-4">
            <Card title="Automation rules — fired by type">
              {a.rules.by_type.length === 0 ? <Empty /> : <ResponsiveContainer width="100%" height={240}><BarChart data={a.rules.by_type.map(r => ({ ...r, name: RULE_LABEL[r.rule_type] ?? r.rule_type }))}><CartesianGrid strokeDasharray="3 3" vertical={false} /><XAxis dataKey="name" tick={{ fontSize: 11 }} /><YAxis allowDecimals={false} tick={{ fontSize: 11 }} /><Tooltip /><Legend /><Bar dataKey="success" name="Succeeded" stackId="r" fill="#16a34a" /><Bar dataKey="failed" name="Failed" stackId="r" fill="#dc2626" /></BarChart></ResponsiveContainer>}
            </Card>
            <div className="space-y-4">
              <Card title="Most-used routing rules">
                {a.top_routes.length === 0 ? <Empty /> : <ul className="divide-y divide-gray-100">{a.top_routes.map(r => <li key={r.route_id} className="flex justify-between py-1.5 text-sm"><span className="text-gray-800 truncate pr-3">{r.name}</span><span className="font-semibold text-gray-600">{r.count}</span></li>)}</ul>}
              </Card>
              <Card title="Most-fired automation rules">
                {a.rules.top.length === 0 ? <Empty /> : <ul className="divide-y divide-gray-100">{a.rules.top.map(r => <li key={r.rule_id} className="flex justify-between py-1.5 text-sm"><span className="text-gray-800 truncate pr-3">{r.name}</span><span className="font-semibold text-gray-600">{r.count}</span></li>)}</ul>}
              </Card>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
