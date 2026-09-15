import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'

type Weekly = { label: string; sold: number; percent: number | null }
type LeaderRow = { user_id: number; name: string; percent: number; rank: number; is_me: boolean }
type SalesMe = {
  month: string; target: number; achieved: number; remaining: number
  progress: number | null; days_left: number; weekly: Weekly[]; leaderboard: LeaderRow[]
}

const money = (v: number) => `₹${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(v)}`
const monthLabel = (m: string) => {
  const d = new Date(`${m}-01T00:00:00`)
  return d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
}

/** Self-service sales target view — progress this month, weekly breakdown, days left, and
 *  team standing. Mirrors the mobile app's Target screen (GET /hr/sales/me). */
export default function MyTargetPage() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7))
  const [data, setData] = useState<SalesMe | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(() => {
    setLoading(true)
    api.get('/hr/sales/me', { params: { month } }).then(r => setData(r.data)).finally(() => setLoading(false))
  }, [month])
  useEffect(() => { load() }, [load])

  if (loading || !data) return <div className="p-6 text-center text-gray-400">Loading…</div>

  const pct = data.progress
  const noTarget = data.target <= 0

  return (
    <div className="p-6 max-w-2xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">🎯 My Target</h1>
          <p className="page-sub">{monthLabel(data.month)}</p>
        </div>
        <input type="month" value={month} onChange={e => setMonth(e.target.value)}
          className="text-sm border border-gray-300 rounded-lg px-2 py-1" />
      </div>

      {noTarget ? (
        <div className="bg-white border border-gray-200 rounded-2xl p-8 text-center text-gray-400 text-sm">
          No monthly target has been set for you yet.
        </div>
      ) : (
        <>
          <div className="bg-indigo-600 rounded-2xl p-6 text-white flex items-center justify-between">
            <div>
              <p className="text-xs text-indigo-200">Sales target this month</p>
              <p className="text-2xl font-bold mt-1">{pct?.toFixed(0)}% <span className="text-sm font-normal text-indigo-200">of {money(data.target)}</span></p>
              <p className="text-xs text-indigo-200 mt-1">{money(data.achieved)} achieved · {money(data.remaining)} remaining</p>
            </div>
            <div className="relative w-16 h-16 shrink-0">
              <svg viewBox="0 0 36 36" className="w-16 h-16 -rotate-90">
                <circle cx="18" cy="18" r="16" fill="none" stroke="rgba(255,255,255,0.2)" strokeWidth="4" />
                <circle cx="18" cy="18" r="16" fill="none" stroke="#fff" strokeWidth="4"
                  strokeDasharray={`${((pct ?? 0) / 100) * 100.5} 100.5`} strokeLinecap="round" />
              </svg>
              <span className="absolute inset-0 flex items-center justify-center text-xs font-semibold">{pct?.toFixed(0)}%</span>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="bg-white border border-gray-200 rounded-xl p-4 text-center">
              <div className="text-lg font-bold text-gray-900">{money(data.remaining)}</div>
              <div className="text-[11px] text-gray-400">Remaining</div>
            </div>
            <div className="bg-white border border-gray-200 rounded-xl p-4 text-center">
              <div className="text-lg font-bold text-gray-900">{data.days_left} days</div>
              <div className="text-[11px] text-gray-400">Left this month</div>
            </div>
          </div>

          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <h3 className="text-sm font-semibold text-gray-700 mb-3">Weekly breakdown</h3>
            <div className="space-y-2.5">
              {data.weekly.map(w => (
                <div key={w.label} className="flex items-center gap-3">
                  <span className="text-xs text-gray-400 w-10 shrink-0">{w.label}</span>
                  <div className="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div className="h-full rounded-full"
                      style={{ width: `${Math.min(100, w.percent ?? 0)}%`, background: w.percent == null ? 'transparent' : w.percent >= 80 ? '#16a34a' : '#d97706' }} />
                  </div>
                  <span className="text-xs text-gray-600 w-10 text-right shrink-0">{w.percent == null ? '–' : `${w.percent.toFixed(0)}%`}</span>
                </div>
              ))}
            </div>
          </div>
        </>
      )}

      {data.leaderboard.length > 0 && (
        <div className="bg-white border border-gray-200 rounded-xl p-4">
          <h3 className="text-sm font-semibold text-gray-700 mb-3">Team standing</h3>
          <div className="space-y-1.5">
            {data.leaderboard.map(r => (
              <div key={r.user_id} className={`flex items-center gap-3 text-sm px-2 py-1.5 rounded-lg ${r.is_me ? 'bg-indigo-50' : ''}`}>
                <span className="text-xs text-gray-400 w-4">{r.rank}</span>
                <span className="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 text-[11px] font-semibold flex items-center justify-center shrink-0">
                  {r.name.split(' ').filter(Boolean).map(w => w[0]?.toUpperCase()).slice(0, 2).join('')}
                </span>
                <span className="flex-1 truncate">{r.is_me ? `${r.name} (you)` : r.name}</span>
                <span className="font-semibold">{r.percent.toFixed(0)}%</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
