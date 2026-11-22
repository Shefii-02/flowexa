import { useEffect, useState } from 'react'
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { api } from '@/api/client'
import { Spinner } from '@/components/ui'

interface Kpi { label: string; value: number | string; format?: 'money' | 'percent'; tone?: 'green' | 'red' | 'amber' }
interface Line_ { label: string; color: string; points: { date: string; value: number }[] }
interface Payload {
  title: string; range_days: number; kpis: Kpi[]
  series: { title: string; lines: Line_[] }
  lists: { title: string; columns: string[]; rows: (string | number)[][] }[]
}

const TONE: Record<string, string> = { green: 'text-green-700', red: 'text-red-600', amber: 'text-amber-600' }

export function formatKpi(k: Kpi): string {
  const n = typeof k.value === 'number' ? k.value : Number(k.value)
  if (Number.isNaN(n)) return String(k.value)
  if (k.format === 'money') return `₹${n.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`
  if (k.format === 'percent') return `${n}%`
  return n.toLocaleString('en-IN')
}

/**
 * One dashboard for any section: headline numbers, a 30-day trend and a few top lists. The server (SectionDashboardController) decides
 * the content; each section's page only says which endpoint to call — so Tasks, Meta Ads, Instagram, Messenger and AI Agent each get
 * their own dashboard inside their own sidebar group.
 */
export default function SectionDashboard({ endpoint, params, icon, name }: { endpoint: string; params?: Record<string, string>; icon: string; name: string }) {
  const [data, setData] = useState<Payload | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let live = true
    setLoading(true); setError(null)
    api.get(endpoint, { params })
      .then(r => { if (live) setData(r.data) })
      .catch(e => { if (live) setError(e?.response?.data?.message ?? 'Could not load the dashboard.') })
      .finally(() => { if (live) setLoading(false) })
    return () => { live = false }
  }, [endpoint, JSON.stringify(params)])  // eslint-disable-line react-hooks/exhaustive-deps

  // one row per day, one key per line, for recharts
  const chart = data ? data.series.lines[0]?.points.map((p, i) => ({
    date: p.date.slice(5),
    ...Object.fromEntries(data.series.lines.map(l => [l.label, l.points[i]?.value ?? 0])),
  })) ?? [] : []

  return (
    <div className="p-6 space-y-6" data-testid="section-dashboard">
      <div>
        <h1 className="page-title">{icon} {name} Dashboard</h1>
        <p className="page-sub">{data?.title ?? 'Loading…'}</p>
      </div>

      {loading ? <div className="flex justify-center py-16"><Spinner size="lg" /></div>
        : error ? <div className="card p-6 text-sm text-red-600" data-testid="dash-error">{error}</div>
        : data && (
          <>
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
              {data.kpis.map(k => (
                <div key={k.label} className="card p-4" data-testid="kpi">
                  <div className="text-[11px] font-medium text-gray-500 uppercase tracking-wide">{k.label}</div>
                  <div className={`text-2xl font-bold mt-1 ${k.tone ? TONE[k.tone] : 'text-gray-900'}`}>{formatKpi(k)}</div>
                </div>
              ))}
            </div>

            <div className="card p-5">
              <h2 className="text-sm font-semibold text-gray-800 mb-3">{data.series.title} <span className="text-xs text-gray-400 font-normal">· last {data.range_days} days</span></h2>
              <div style={{ width: '100%', height: 240 }}>
                <ResponsiveContainer>
                  <LineChart data={chart}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                    <XAxis dataKey="date" tick={{ fontSize: 11 }} interval={4} />
                    <YAxis tick={{ fontSize: 11 }} allowDecimals={false} width={44} />
                    <Tooltip />
                    <Legend />
                    {data.series.lines.map(l => <Line key={l.label} type="monotone" dataKey={l.label} stroke={l.color} strokeWidth={2} dot={false} />)}
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
              {data.lists.map(list => (
                <div key={list.title} className="card p-5" data-testid="dash-list">
                  <h2 className="text-sm font-semibold text-gray-800 mb-3">{list.title}</h2>
                  {list.rows.length === 0 ? <p className="text-xs text-gray-400">Nothing here yet.</p> : (
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <thead><tr className="text-left text-[11px] uppercase text-gray-400">
                          {list.columns.map((c, i) => <th key={c} className={`pb-2 font-medium ${i > 0 ? 'text-right' : ''}`}>{c}</th>)}
                        </tr></thead>
                        <tbody className="divide-y divide-gray-100">
                          {list.rows.map((r, ri) => (
                            <tr key={ri}>{r.map((cell, ci) => <td key={ci} className={`py-2 ${ci > 0 ? 'text-right text-gray-600' : 'text-gray-900'}`}>{typeof cell === 'number' ? cell.toLocaleString('en-IN') : cell}</td>)}</tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </>
        )}
    </div>
  )
}
