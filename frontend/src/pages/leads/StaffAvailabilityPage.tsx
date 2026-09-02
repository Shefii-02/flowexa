// src/pages/leads/StaffAvailabilityPage.tsx
import { useEffect, useState } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchStaffAvailabilityThunk } from '@/store/slices'
import { leadAssignmentApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import type { StaffAvailabilityRecord } from '@/types'

type SortKey = 'score' | 'workload' | 'availability' | 'conversions'

const statusDot: Record<string, string> = {
  online:  'bg-green-400',
  away:    'bg-yellow-400',
  offline: 'bg-gray-300',
  busy:    'bg-blue-400',
}

const statusLabel: Record<string, string> = {
  online:  '🟢 Online',
  away:    '🟡 Away',
  offline: '🔴 Offline',
  busy:    '🔵 Busy',
}

export default function StaffAvailabilityPage() {
  const dispatch = useAppDispatch()
  const staffList = useAppSelector(s => s.leadAssignment.staffAvailability)
  const [sort, setSort] = useState<SortKey>('score')

  useEffect(() => { dispatch(fetchStaffAvailabilityThunk()) }, [dispatch])

  const sorted = [...staffList].sort((a, b) => {
    const av = a.availability
    const bv = b.availability
    if (sort === 'score')         return (bv?.performance_score ?? 0) - (av?.performance_score ?? 0)
    if (sort === 'workload')      return (av?.current_leads_count ?? 0) - (bv?.current_leads_count ?? 0)
    if (sort === 'availability')  return ((bv?.is_online ? 1 : 0) - (av?.is_online ? 1 : 0))
    if (sort === 'conversions')   return (bv?.today_conversions ?? 0) - (av?.today_conversions ?? 0)
    return 0
  })

  const handleToggle = async () => {
    try {
      await leadAssignmentApi.toggleAvailability()
      dispatch(fetchStaffAvailabilityThunk())
      toast.success('Availability updated')
    } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <h1 className="text-xl font-bold text-gray-900">Staff Availability</h1>
        <div className="flex items-center gap-3">
          <span className="text-sm text-gray-500">Sort by:</span>
          {(['score', 'workload', 'availability', 'conversions'] as SortKey[]).map(k => (
            <button
              key={k}
              onClick={() => setSort(k)}
              className={`px-3 py-1 rounded-full text-xs font-medium border transition-colors ${
                sort === k ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-600 border-gray-200'
              }`}
            >
              {k.charAt(0).toUpperCase() + k.slice(1)}
            </button>
          ))}
          <button onClick={handleToggle} className="btn btn-ghost text-sm">Toggle My Availability</button>
          <button onClick={() => dispatch(fetchStaffAvailabilityThunk())} className="btn btn-ghost text-sm">Refresh</button>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        {sorted.map((staff: StaffAvailabilityRecord) => {
          const av = staff.availability
          const maxLeads = 20 // placeholder — could come from staff.max_leads if added to response
          const used = av?.current_leads_count ?? 0
          const pct = Math.min(100, Math.round((used / maxLeads) * 100))
          const st = av?.status ?? 'offline'

          return (
            <div key={staff.id} className="bg-white rounded-xl border border-gray-200 p-4 space-y-3 hover:shadow-md transition-shadow">
              {/* Header */}
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-2">
                  <div className="w-9 h-9 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 font-semibold text-sm">
                    {staff.name.charAt(0).toUpperCase()}
                  </div>
                  <div>
                    <p className="font-semibold text-gray-900 text-sm">{staff.name}</p>
                    <p className="text-xs text-gray-500">{staff.role?.label ?? staff.role?.name ?? '—'}</p>
                  </div>
                </div>
                <div className="flex items-center gap-1.5">
                  <span className={`w-2 h-2 rounded-full ${statusDot[st]}`} />
                  <span className="text-xs text-gray-500">{statusLabel[st]}</span>
                </div>
              </div>

              {/* Capacity bar */}
              <div>
                <div className="flex justify-between text-xs text-gray-500 mb-1">
                  <span>Active Leads: {used}</span>
                  <span>{pct}%</span>
                </div>
                <div className="w-full bg-gray-100 rounded-full h-1.5">
                  <div
                    className={`h-1.5 rounded-full transition-all ${pct > 75 ? 'bg-red-400' : pct > 50 ? 'bg-yellow-400' : 'bg-green-400'}`}
                    style={{ width: `${pct}%` }}
                  />
                </div>
              </div>

              {/* Stats */}
              <div className="grid grid-cols-2 gap-2 text-xs">
                <div className="bg-gray-50 rounded-lg p-2">
                  <p className="text-gray-400">Avg Reply</p>
                  <p className="font-semibold text-gray-700">{av?.avg_response_time_minutes?.toFixed(1) ?? '—'}m</p>
                </div>
                <div className="bg-gray-50 rounded-lg p-2">
                  <p className="text-gray-400">Conversion</p>
                  <p className="font-semibold text-gray-700">{av?.conversion_rate?.toFixed(1) ?? 0}%</p>
                </div>
                <div className="bg-gray-50 rounded-lg p-2">
                  <p className="text-gray-400">Today Leads</p>
                  <p className="font-semibold text-gray-700">{av?.today_leads_count ?? 0}</p>
                </div>
                <div className="bg-gray-50 rounded-lg p-2">
                  <p className="text-gray-400">Today Conv.</p>
                  <p className="font-semibold text-green-600">{av?.today_conversions ?? 0} 🔥</p>
                </div>
              </div>

              {/* Score */}
              <div className="flex items-center justify-between">
                <span className="text-xs text-gray-400">Performance score</span>
                <span className="text-sm font-bold text-brand-600">{av?.performance_score?.toFixed(0) ?? 50}/100</span>
              </div>
            </div>
          )
        })}

        {sorted.length === 0 && (
          <div className="col-span-4 text-center py-16 text-gray-400">
            <p className="text-4xl mb-2">👤</p>
            <p>No staff members found</p>
          </div>
        )}
      </div>
    </div>
  )
}
