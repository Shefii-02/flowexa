import { useEffect, useMemo, useState } from 'react'
import {
  ResponsiveContainer, LineChart, Line, BarChart, Bar,
  XAxis, YAxis, Tooltip, CartesianGrid,
} from 'recharts'
import { api } from '@/api/client'

type Summary = {
  range: { from: string; to: string }
  totals: {
    all: number; in_range: number; new_today: number; new_week: number; new_month: number
    unassigned: number; enrolled: number; conversion_rate: number
  }
  by_stage: Record<string, number>
  by_source: Record<string, number>
  by_priority: Record<string, number>
  by_agent: { agent: string; total: number }[]
  avg_hours_to_assign: number | null
  avg_hours_to_enroll: number | null
  daily: { date: string; total: number }[]
}

const iso = (d: Date) => d.toISOString().slice(0, 10)

function Tile({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
  return (
    <div className="bg-white border border-gray-200 rounded-xl px-4 py-3">
      <div className="text-2xl font-bold text-gray-900 tabular-nums">{value}</div>
      <div className="text-xs text-gray-500 mt-0.5">{label}</div>
      {hint && <div className="text-[11px] text-gray-400 mt-0.5">{hint}</div>}
    </div>
  )
}

function BreakdownCard({ title, data }: { title: string; data: Record<string, number> }) {
  const entries = Object.entries(data).sort(([, a], [, b]) => b - a)
  const max = Math.max(1, ...entries.map(([, v]) => v))
  return (
    <div className="bg-white border border-gray-200 rounded-xl p-4">
      <h3 className="text-sm font-semibold text-gray-700 mb-3">{title}</h3>
      {entries.length === 0 ? <p className="text-xs text-gray-400">No data.</p> : (
        <div className="space-y-2">
          {entries.map(([k, v]) => (
            <div key={k}>
              <div className="flex justify-between text-xs mb-0.5"><span className="capitalize text-gray-600">{k}</span><span className="font-medium">{v}</span></div>
              <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                <div className="h-full bg-indigo-500 rounded-full" style={{ width: `${(v / max) * 100}%` }} />
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

export default function LeadsSummaryPage() {
  const today = new Date()
  const start = new Date(); start.setDate(today.getDate() - 29)
  const [from, setFrom] = useState(iso(start))
  const [to, setTo] = useState(iso(today))
  const [data, setData] = useState<Summary | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    setLoading(true)
    api.get('/leads/summary', { params: { from, to } })
      .then(r => setData(r.data))
      .finally(() => setLoading(false))
  }, [from, to])

  const daily = useMemo(
    () => (data?.daily ?? []).map(d => ({ date: d.date.slice(5), total: d.total })),
    [data],
  )
  const agentBars = useMemo(() => (data?.by_agent ?? []).slice(0, 8), [data])

  return (
    <div className="p-6 space-y-6 max-w-6xl">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">🎯 Leads Summary</h1>
          <p className="page-sub">Key lead metrics at a glance — the Basic view</p>
        </div>
        <div className="flex items-end gap-2">
          <label className="text-xs text-gray-500">From<input type="date" value={from} onChange={e => setFrom(e.target.value)} className="block mt-1 text-sm border border-gray-200 rounded-lg px-2 py-1.5" /></label>
          <label className="text-xs text-gray-500">To<input type="date" value={to} onChange={e => setTo(e.target.value)} className="block mt-1 text-sm border border-gray-200 rounded-lg px-2 py-1.5" /></label>
        </div>
      </div>

      {loading || !data ? (
        <div className="text-center py-16 text-gray-400">Loading…</div>
      ) : (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Tile label="Total leads" value={data.totals.all} />
            <Tile label="In range" value={data.totals.in_range} />
            <Tile label="New today" value={data.totals.new_today} />
            <Tile label="New this week" value={data.totals.new_week} />
            <Tile label="Unassigned" value={data.totals.unassigned} />
            <Tile label="Enrolled" value={data.totals.enrolled} />
            <Tile label="Conversion" value={`${data.totals.conversion_rate}%`} />
            <Tile label="Avg time to assign" value={data.avg_hours_to_assign != null ? `${data.avg_hours_to_assign}h` : '—'}
              hint={data.avg_hours_to_enroll != null ? `${data.avg_hours_to_enroll}h to enroll` : undefined} />
          </div>

          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <h3 className="text-sm font-semibold text-gray-700 mb-3">New leads per day</h3>
            <ResponsiveContainer width="100%" height={240}>
              <LineChart data={daily}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="date" fontSize={11} /><YAxis fontSize={11} allowDecimals={false} />
                <Tooltip />
                <Line type="monotone" dataKey="total" stroke="#6366f1" strokeWidth={2} dot={false} name="Leads" />
              </LineChart>
            </ResponsiveContainer>
          </div>

          <div className="grid md:grid-cols-3 gap-4">
            <BreakdownCard title="By stage" data={data.by_stage} />
            <BreakdownCard title="By source" data={data.by_source} />
            <BreakdownCard title="By priority" data={data.by_priority} />
          </div>

          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <h3 className="text-sm font-semibold text-gray-700 mb-3">Leads by agent</h3>
            <ResponsiveContainer width="100%" height={240}>
              <BarChart data={agentBars}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="agent" fontSize={11} /><YAxis fontSize={11} allowDecimals={false} />
                <Tooltip />
                <Bar dataKey="total" fill="#10b981" radius={[4, 4, 0, 0]} name="Leads" />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </>
      )}
    </div>
  )
}
