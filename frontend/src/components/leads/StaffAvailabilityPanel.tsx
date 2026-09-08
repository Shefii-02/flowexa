import { useCallback, useEffect, useState } from 'react'
import { leadAssignmentApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

// Folded-in staff availability — shown inside the Assignment Rules page (both
// Basic and Advanced). Replaces the old standalone /leads/staff-availability tab.

type SortKey = 'score' | 'workload' | 'availability' | 'conversions'

const statusDot: Record<string, string> = {
  online: 'bg-green-400', away: 'bg-yellow-400', offline: 'bg-gray-300', busy: 'bg-blue-400',
}
const statusLabel: Record<string, string> = {
  online: '🟢 Online', away: '🟡 Away', offline: '🔴 Offline', busy: '🔵 Busy',
}

type Avail = {
  is_online?: boolean; is_available?: boolean; status?: string
  current_leads_count?: number; today_leads_count?: number; today_conversions?: number
  avg_response_time_minutes?: number; conversion_rate?: number; performance_score?: number
}
type Staff = { id: number; name: string; role?: { name?: string; label?: string } | null; availability?: Avail | null }

export function StaffAvailabilityPanel({ compact = false }: { compact?: boolean }) {
  const [list, setList] = useState<Staff[]>([])
  const [sort, setSort] = useState<SortKey>('score')
  const [loading, setLoading] = useState(true)

  const load = useCallback(() => {
    setLoading(true)
    leadAssignmentApi.staffAvailability()
      .then(r => setList(Array.isArray(r.data?.data) ? r.data.data : []))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  const toggleMine = async () => {
    try { await leadAssignmentApi.toggleAvailability(); load(); toast.success('Availability updated') }
    catch (e) { toast.error(getError(e)) }
  }

  const sorted = [...list].sort((a, b) => {
    const av = a.availability, bv = b.availability
    if (sort === 'score') return (bv?.performance_score ?? 0) - (av?.performance_score ?? 0)
    if (sort === 'workload') return (av?.current_leads_count ?? 0) - (bv?.current_leads_count ?? 0)
    if (sort === 'availability') return (bv?.is_online ? 1 : 0) - (av?.is_online ? 1 : 0)
    return (bv?.today_conversions ?? 0) - (av?.today_conversions ?? 0)
  })

  return (
    <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <h2 className="font-semibold text-gray-800">Staff Availability</h2>
        <div className="flex items-center gap-2 text-xs">
          <span className="text-gray-400">Sort:</span>
          {(['score', 'workload', 'availability', 'conversions'] as SortKey[]).map(k => (
            <button key={k} onClick={() => setSort(k)}
              className={`px-2 py-0.5 rounded-full border ${sort === k ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-500 border-gray-200'}`}>
              {k[0].toUpperCase() + k.slice(1)}
            </button>
          ))}
          <button onClick={toggleMine} className="px-2 py-0.5 rounded-full border border-gray-200 text-gray-600">Toggle mine</button>
          <button onClick={load} className="px-2 py-0.5 rounded-full border border-gray-200 text-gray-600">↻</button>
        </div>
      </div>

      {loading ? (
        <p className="text-sm text-gray-400 py-6 text-center">Loading…</p>
      ) : sorted.length === 0 ? (
        <p className="text-sm text-gray-400 py-6 text-center">No staff members found.</p>
      ) : (
        <div className={`grid gap-3 ${compact ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4'}`}>
          {sorted.map(s => {
            const av = s.availability
            const used = av?.current_leads_count ?? 0
            const pct = Math.min(100, Math.round((used / 20) * 100))
            const st = av?.status ?? 'offline'
            return (
              <div key={s.id} className="border border-gray-200 rounded-xl p-3 space-y-2">
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-2 min-w-0">
                    <div className="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 font-semibold text-xs shrink-0">
                      {s.name.charAt(0).toUpperCase()}
                    </div>
                    <div className="min-w-0">
                      <p className="font-semibold text-gray-900 text-sm truncate">{s.name}</p>
                      <p className="text-[11px] text-gray-400 truncate">{s.role?.label ?? s.role?.name ?? '—'}</p>
                    </div>
                  </div>
                  <span className="flex items-center gap-1 text-[11px] text-gray-500 shrink-0"><span className={`w-2 h-2 rounded-full ${statusDot[st]}`} />{statusLabel[st]}</span>
                </div>
                <div>
                  <div className="flex justify-between text-[11px] text-gray-500 mb-0.5"><span>Active: {used}</span><span>{pct}%</span></div>
                  <div className="w-full bg-gray-100 rounded-full h-1.5">
                    <div className={`h-1.5 rounded-full ${pct > 75 ? 'bg-red-400' : pct > 50 ? 'bg-yellow-400' : 'bg-green-400'}`} style={{ width: `${pct}%` }} />
                  </div>
                </div>
                <div className="flex justify-between text-[11px] text-gray-500">
                  <span>Today: {av?.today_leads_count ?? 0}L / {av?.today_conversions ?? 0}C</span>
                  <span className="font-bold text-brand-600">{av?.performance_score?.toFixed(0) ?? 50}/100</span>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </section>
  )
}
