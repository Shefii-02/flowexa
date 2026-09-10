import { useEffect, useState } from 'react'
import {
  BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid,
} from 'recharts'
import { waChatAnalyticsApi } from '@/api'
import { getError } from '@/utils'
import { Spinner } from '@/components/ui'

interface BySession {
  id: string; name: string; status: string
  sent: number; received: number; today: number; failed: number; reachable: boolean
}
interface Analytics {
  connected: boolean
  overview: {
    sessions_total: number; sessions_connected: number
    messages_sent: number; messages_received: number; messages_today: number; messages_failed: number
  }
  by_session: BySession[]
  hourly_activity: { hour: number; sent: number; received: number }[]
  top_chats: { chat_id: string; chat_name: string | null; count: number; last_active: string | null }[]
}

const CONNECTED = ['ready', 'connected', 'working', 'authenticated']

function Stat({ label, value, tone }: { label: string; value: number | string; tone?: 'danger' | 'muted' }) {
  return (
    <div className="bg-white border border-gray-200 rounded-xl p-4">
      <p className="text-xs text-gray-400">{label}</p>
      <p className={`text-2xl font-bold mt-1 ${tone === 'danger' ? 'text-red-600' : tone === 'muted' ? 'text-gray-400' : 'text-gray-900'}`}>
        {value}
      </p>
    </div>
  )
}

export default function WaChatAnalytics() {
  const [data, setData] = useState<Analytics | null>(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => {
    waChatAnalyticsApi.get()
      .then(r => setData(r.data))
      .catch(e => setErr(getError(e)))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <div className="flex justify-center py-16"><Spinner size="lg" /></div>
  if (err) return <div className="p-6 text-sm text-red-600">{err}</div>
  if (!data) return null

  const o = data.overview
  const hourly = data.hourly_activity.map(h => ({ ...h, label: String(h.hour).padStart(2, '0') }))
  const topChats = data.top_chats.map(c => ({
    name: c.chat_name || c.chat_id.replace(/@.*/, ''),
    count: c.count,
  }))

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div>
        <h1 className="text-xl font-bold text-gray-900">WA Chat Analytics</h1>
        <p className="text-sm text-gray-500 mt-0.5">
          Aggregated across this company&rsquo;s {o.sessions_total} session{o.sessions_total === 1 ? '' : 's'}.
        </p>
      </div>

      {!data.connected && (
        <div className="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl p-3">
          The gateway didn&rsquo;t return stats for any session — figures below may be incomplete.
        </div>
      )}

      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <Stat label="Sessions" value={o.sessions_total} />
        <Stat label="Connected" value={o.sessions_connected} tone={o.sessions_connected ? undefined : 'muted'} />
        <Stat label="Sent (all time)" value={o.messages_sent.toLocaleString()} />
        <Stat label="Received (all time)" value={o.messages_received.toLocaleString()} />
        <Stat label="Today" value={o.messages_today.toLocaleString()} />
        <Stat label="Failed" value={o.messages_failed.toLocaleString()} tone={o.messages_failed ? 'danger' : 'muted'} />
      </div>

      {/* Hourly activity */}
      <div className="bg-white border border-gray-200 rounded-xl p-4">
        <p className="text-sm font-semibold text-gray-700 mb-3">Activity by hour of day</p>
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={hourly} margin={{ top: 4, right: 8, left: -16, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} />
              <XAxis dataKey="label" tick={{ fontSize: 11 }} />
              <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
              <Tooltip />
              <Bar dataKey="received" stackId="a" fill="#93c5fd" name="Received" />
              <Bar dataKey="sent" stackId="a" fill="#6366f1" name="Sent" radius={[3, 3, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Per-session */}
        <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
          <p className="text-sm font-semibold text-gray-700 px-4 py-3 border-b border-gray-100">By session</p>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-xs text-gray-400 border-b border-gray-100">
                  <th className="text-left px-4 py-2 font-medium">Session</th>
                  <th className="px-3 py-2 font-medium text-right">Sent</th>
                  <th className="px-3 py-2 font-medium text-right">Recv</th>
                  <th className="px-3 py-2 font-medium text-right">Today</th>
                  <th className="px-3 py-2 font-medium text-right">Failed</th>
                </tr>
              </thead>
              <tbody>
                {data.by_session.map(s => (
                  <tr key={s.id} className="border-b border-gray-50 last:border-0">
                    <td className="px-4 py-2">
                      <div className="text-gray-800">{s.name}</div>
                      <div className={`text-[11px] ${CONNECTED.includes(s.status) ? 'text-green-600' : 'text-gray-400'}`}>
                        {s.status}{!s.reachable && ' · unreachable'}
                      </div>
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">{s.sent.toLocaleString()}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{s.received.toLocaleString()}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{s.today.toLocaleString()}</td>
                    <td className={`px-3 py-2 text-right tabular-nums ${s.failed ? 'text-red-600' : 'text-gray-300'}`}>
                      {s.failed.toLocaleString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Top chats */}
        <div className="bg-white border border-gray-200 rounded-xl p-4">
          <p className="text-sm font-semibold text-gray-700 mb-3">Top chats</p>
          {topChats.length === 0 ? (
            <p className="text-sm text-gray-400 py-8 text-center">No chat activity yet.</p>
          ) : (
            <div className="h-72">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={topChats} layout="vertical" margin={{ top: 0, right: 16, left: 8, bottom: 0 }}>
                  <XAxis type="number" tick={{ fontSize: 11 }} allowDecimals={false} />
                  <YAxis type="category" dataKey="name" width={120} tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Bar dataKey="count" fill="#6366f1" radius={[0, 3, 3, 0]} name="Messages" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
