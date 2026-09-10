import { useEffect, useMemo, useState } from 'react'
import {
  ResponsiveContainer, LineChart, Line, BarChart, Bar,
  XAxis, YAxis, Tooltip, CartesianGrid, Legend,
} from 'recharts'
import { api } from '@/api/client'
import { PageHeader } from '@/pages/wa-chat/components/PageHeader'

type DailyPoint = { inbound: number; outbound: number }
type Summary = {
  range: { from: string; to: string }
  messages: {
    total_inbound: number; total_outbound: number
    by_status: Record<string, number>; by_type: Record<string, number>
    daily: Record<string, DailyPoint>
  }
  calls: {
    total: number; total_inbound: number; total_outbound: number
    missed: number; connected: number; total_duration_s: number; avg_duration_s: number
    by_status: Record<string, number>; daily: Record<string, DailyPoint>
  }
  inbox: {
    total_conversations: number; unassigned: number; open: number
    by_agent: { agent: string; total: number }[]
  }
}
type Agent = { id: number; name: string }

const PRESETS: { label: string; days: number }[] = [
  { label: 'Today', days: 0 }, { label: '7d', days: 6 }, { label: '30d', days: 29 }, { label: '90d', days: 89 },
]

const iso = (d: Date) => d.toISOString().slice(0, 10)

function dailyToSeries(daily: Record<string, DailyPoint>) {
  return Object.entries(daily)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([date, v]) => ({ date: date.slice(5), inbound: v.inbound, outbound: v.outbound }))
}

const fmtDuration = (s: number) => {
  if (!s) return '0s'
  const m = Math.floor(s / 60); const r = s % 60
  return m ? `${m}m ${r}s` : `${r}s`
}

function Tile({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
  return (
    <div className="bg-white border border-gray-200 rounded-xl px-4 py-3">
      <div className="text-2xl font-bold text-gray-900">{value}</div>
      <div className="text-xs text-gray-500 mt-0.5">{label}</div>
      {hint && <div className="text-[11px] text-gray-400 mt-0.5">{hint}</div>}
    </div>
  )
}

export default function WaCloudInboxAnalytics() {
  const today = new Date()
  const start = new Date(); start.setDate(today.getDate() - 29)

  const [from, setFrom] = useState(iso(start))
  const [to, setTo] = useState(iso(today))
  const [direction, setDirection] = useState('')
  const [status, setStatus] = useState('')
  const [agentId, setAgentId] = useState('')
  const [labelId, setLabelId] = useState('')

  const [agents, setAgents] = useState<Agent[]>([])
  const [labels, setLabels] = useState<{ id: number; name: string }[]>([])
  const [data, setData] = useState<Summary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const asArray = (v: unknown) => (Array.isArray(v) ? v : [])
    api.get('/wa-cloud/inbox-analytics/agents')
      .then(r => setAgents(asArray(r.data?.data)))
      .catch(() => {})
    api.get('/labels')
      .then(r => setLabels(asArray(r.data?.labels ?? r.data?.data ?? r.data)))
      .catch(() => {})
  }, [])

  useEffect(() => {
    setLoading(true)
    setError(null)
    const params: Record<string, string> = { from, to }
    if (direction) params.direction = direction
    if (status) params.status = status
    if (agentId) params.agent_id = agentId
    if (labelId) params.label_id = labelId
    api.get('/wa-cloud/inbox-analytics', { params })
      .then(r => setData(r.data))
      .catch(() => setError('Could not load analytics. Please try again.'))
      .finally(() => setLoading(false))
  }, [from, to, direction, status, agentId, labelId])

  const applyPreset = (days: number) => {
    const s = new Date(); s.setDate(new Date().getDate() - days)
    setFrom(iso(s)); setTo(iso(new Date()))
  }

  const msgSeries = useMemo(() => data ? dailyToSeries(data.messages.daily) : [], [data])
  const callStatusSeries = useMemo(
    () => data ? Object.entries(data.calls.by_status).map(([status, total]) => ({ status, total })) : [],
    [data],
  )

  const selctCls = 'text-sm border border-gray-200 rounded-lg px-2 py-1.5 bg-white'

  return (
    <div className="p-6 space-y-6">
      <PageHeader title="WA Cloud Analytics" subtitle="Message, call and inbox activity for your WhatsApp Cloud number" />

      {/* Filters */}
      <div className="bg-white border border-gray-200 rounded-xl p-4 flex flex-wrap items-end gap-3">
        <div className="flex gap-1">
          {PRESETS.map(p => (
            <button key={p.label} onClick={() => applyPreset(p.days)}
              className="text-xs px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200">{p.label}</button>
          ))}
        </div>
        <label className="text-xs text-gray-500">From
          <input type="date" value={from} onChange={e => setFrom(e.target.value)} className={`block mt-1 ${selctCls}`} />
        </label>
        <label className="text-xs text-gray-500">To
          <input type="date" value={to} onChange={e => setTo(e.target.value)} className={`block mt-1 ${selctCls}`} />
        </label>
        <label className="text-xs text-gray-500">Direction
          <select value={direction} onChange={e => setDirection(e.target.value)} className={`block mt-1 ${selctCls}`}>
            <option value="">All</option><option value="inbound">Inbound</option><option value="outbound">Outbound</option>
          </select>
        </label>
        <label className="text-xs text-gray-500">Status
          <input value={status} onChange={e => setStatus(e.target.value)} placeholder="e.g. read, failed, completed"
            className={`block mt-1 ${selctCls}`} />
        </label>
        <label className="text-xs text-gray-500">Agent
          <select value={agentId} onChange={e => setAgentId(e.target.value)} className={`block mt-1 ${selctCls}`}>
            <option value="">All agents</option>
            {agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500">Label
          <select value={labelId} onChange={e => setLabelId(e.target.value)} className={`block mt-1 ${selctCls}`}>
            <option value="">All labels</option>
            {labels.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
          </select>
        </label>
        {(direction || status || agentId || labelId) && (
          <button onClick={() => { setDirection(''); setStatus(''); setAgentId(''); setLabelId('') }}
            className="text-xs px-2.5 py-1.5 border border-gray-200 rounded-lg bg-white">Clear</button>
        )}
      </div>

      {error ? (
        <div className="text-center text-sm text-red-500 py-16">{error}</div>
      ) : loading || !data ? (
        <div className="text-center text-sm text-gray-400 py-16">Loading…</div>
      ) : (
        <>
          {/* Message tiles */}
          <div>
            <h3 className="text-sm font-semibold text-gray-700 mb-2">Messages</h3>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              <Tile label="Incoming" value={data.messages.total_inbound} />
              <Tile label="Outgoing" value={data.messages.total_outbound} />
              <Tile label="Delivered" value={data.messages.by_status['delivered'] ?? 0} />
              <Tile label="Read" value={data.messages.by_status['read'] ?? 0} />
              <Tile label="Failed" value={data.messages.by_status['failed'] ?? 0} />
              {Object.entries(data.messages.by_type).slice(0, 3).map(([t, n]) => <Tile key={t} label={`Type: ${t}`} value={n} />)}
            </div>
          </div>

          {/* Message trend */}
          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <h3 className="text-sm font-semibold text-gray-700 mb-3">Messages per day</h3>
            <ResponsiveContainer width="100%" height={260}>
              <LineChart data={msgSeries}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="date" fontSize={11} /><YAxis fontSize={11} allowDecimals={false} />
                <Tooltip /><Legend />
                <Line type="monotone" dataKey="inbound" stroke="#3b82f6" name="Incoming" strokeWidth={2} dot={false} />
                <Line type="monotone" dataKey="outbound" stroke="#1D9E75" name="Outgoing" strokeWidth={2} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          </div>

          {/* Call tiles */}
          <div>
            <h3 className="text-sm font-semibold text-gray-700 mb-2">Calls</h3>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              <Tile label="Total calls" value={data.calls.total} />
              <Tile label="Incoming" value={data.calls.total_inbound} />
              <Tile label="Outgoing" value={data.calls.total_outbound} />
              <Tile label="Connected" value={data.calls.connected} />
              <Tile label="Missed / rejected" value={data.calls.missed} />
              <Tile label="Total talk time" value={fmtDuration(data.calls.total_duration_s)} />
              <Tile label="Avg call length" value={fmtDuration(data.calls.avg_duration_s)} />
            </div>
            {data.calls.total === 0 && (
              <p className="text-xs text-gray-400 mt-2">
                No call data yet. Calls appear here once the WhatsApp Business Calling API is enabled on your number and the
                <code className="mx-1">calls</code> webhook field is subscribed.
              </p>
            )}
          </div>

          {/* Call breakdown + agent table */}
          <div className="grid md:grid-cols-2 gap-4">
            <div className="bg-white border border-gray-200 rounded-xl p-4">
              <h3 className="text-sm font-semibold text-gray-700 mb-3">Calls by status</h3>
              <ResponsiveContainer width="100%" height={220}>
                <BarChart data={callStatusSeries}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="status" fontSize={11} /><YAxis fontSize={11} allowDecimals={false} />
                  <Tooltip />
                  <Bar dataKey="total" fill="#8b5cf6" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>

            <div className="bg-white border border-gray-200 rounded-xl p-4">
              <h3 className="text-sm font-semibold text-gray-700 mb-3">Conversations by agent</h3>
              <div className="flex gap-3 mb-3">
                <Tile label="Conversations" value={data.inbox.total_conversations} />
                <Tile label="Unassigned" value={data.inbox.unassigned} />
                <Tile label="Open" value={data.inbox.open} />
              </div>
              <table className="w-full text-sm">
                <thead><tr className="text-left text-gray-400 border-b border-gray-100"><th className="py-1.5">Agent</th><th className="py-1.5 text-right">Conversations</th></tr></thead>
                <tbody>
                  {data.inbox.by_agent.map(r => (
                    <tr key={r.agent} className="border-b border-gray-50">
                      <td className="py-1.5">{r.agent}</td><td className="py-1.5 text-right">{r.total}</td>
                    </tr>
                  ))}
                  {data.inbox.by_agent.length === 0 && <tr><td colSpan={2} className="py-3 text-center text-gray-400">No conversations in range.</td></tr>}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
