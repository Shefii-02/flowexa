// src/pages/leads/AssignmentPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { fetchAssignmentsThunk, fetchAssignmentStatsThunk, updateAssignment } from '@/store/slices'
import { leadAssignmentApi } from '@/api'
import { Badge, StatCard, Pagination, TableSkeleton } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import type { LeadAssignment, LeadAssignmentStatus } from '@/types'

const statusConfig: Record<LeadAssignmentStatus, { label: string; color: string }> = {
  pending:     { label: 'Pending',     color: 'bg-yellow-100 text-yellow-700' },
  notified:    { label: 'Notified',    color: 'bg-blue-100 text-blue-700' },
  accepted:    { label: 'Accepted',    color: 'bg-green-100 text-green-700' },
  assigned:    { label: 'Assigned',    color: 'bg-indigo-100 text-indigo-700' },
  ai_handling: { label: 'AI Handling', color: 'bg-purple-100 text-purple-700' },
  ai_offered:  { label: 'AI Offered',  color: 'bg-violet-100 text-violet-700' },
  transferred: { label: 'Transferred', color: 'bg-orange-100 text-orange-700' },
  completed:   { label: 'Completed',   color: 'bg-green-100 text-green-800' },
  dropped:     { label: 'Dropped',     color: 'bg-gray-100 text-gray-600' },
}

const sourceLabel: Record<string, string> = {
  wa_chat:      '💬 WA Chat',
  meta_api:     '📘 Meta API',
  campaign:     '📢 Campaign',
  organic:      '🌱 Organic',
  flow_builder: '🔄 Flow',
  manual:       '✋ Manual',
}

const priorityColor = (p: number) => {
  if (p <= 1) return 'bg-red-100 text-red-700'
  if (p <= 2) return 'bg-orange-100 text-orange-700'
  if (p <= 3) return 'bg-yellow-100 text-yellow-700'
  return 'bg-gray-100 text-gray-600'
}

export default function AssignmentPage() {
  const dispatch = useAppDispatch()
  const { assignments, total, stats, loading } = useAppSelector(s => s.leadAssignment)

  const [page, setPage]         = useState(1)
  const [statusFilter, setStatus] = useState('')
  const [transferId, setTransferId] = useState<number | null>(null)
  const [staffList, setStaffList]   = useState<any[]>([])
  const [toStaffId, setToStaffId]   = useState('')

  const load = useCallback(() => {
    dispatch(fetchAssignmentsThunk({ page, status: statusFilter || undefined }))
    dispatch(fetchAssignmentStatsThunk())
  }, [dispatch, page, statusFilter])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    leadAssignmentApi.staffAvailability()
      .then(r => setStaffList(r.data.data ?? []))
      .catch(() => {})
  }, [])

  const handleComplete = async (id: number) => {
    try {
      const { data } = await leadAssignmentApi.complete(id)
      dispatch(updateAssignment(data.data))
      toast.success('Marked as completed')
    } catch (e) { toast.error(getError(e)) }
  }

  const handleTransfer = async () => {
    if (!transferId || !toStaffId) return
    try {
      const { data } = await leadAssignmentApi.transfer(transferId, { to_staff_id: Number(toStaffId), reason: 'Manual transfer' })
      dispatch(updateAssignment(data.data))
      toast.success('Lead transferred')
      setTransferId(null)
      setToStaffId('')
    } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold text-gray-900">Assignment Queue</h1>
        <button onClick={load} className="btn btn-ghost text-sm">Refresh</button>
      </div>

      {/* Stats row */}
      {stats && (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
          <StatCard label="Today"       value={stats.total_today} />
          <StatCard label="Auto"        value={stats.auto_assigned} />
          <StatCard label="Notif."      value={stats.notification_assigned} />
          <StatCard label="AI Handling" value={stats.ai_handling} />
          <StatCard label="SLA Breach"  value={stats.sla_breached} />
          <StatCard label="Conversion"  value={stats.conversion_rate} />
        </div>
      )}

      {/* Filters */}
      <div className="flex gap-2 flex-wrap">
        {['', 'pending', 'notified', 'assigned', 'ai_handling', 'completed', 'dropped'].map(s => (
          <button
            key={s}
            onClick={() => { setStatus(s); setPage(1) }}
            className={`px-3 py-1 rounded-full text-xs font-medium border transition-colors ${
              statusFilter === s
                ? 'bg-brand-600 text-white border-brand-600'
                : 'bg-white text-gray-600 border-gray-200 hover:border-brand-300'
            }`}
          >
            {s ? statusConfig[s as LeadAssignmentStatus]?.label : 'All'}
          </button>
        ))}
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {loading ? (
          <TableSkeleton rows={8} cols={8} />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                {['Contact', 'Phone', 'Source', 'Score', 'P', 'Assigned To', 'Status', 'Actions'].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {assignments.length === 0 ? (
                <tr><td colSpan={8} className="text-center py-12 text-gray-400">No assignments found</td></tr>
              ) : assignments.map(a => (
                <tr key={a.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-gray-900">{a.contact?.name ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-500 font-mono text-xs">{a.contact?.phone}</td>
                  <td className="px-4 py-3 text-xs">{sourceLabel[a.source_type] ?? a.source_type}</td>
                  <td className="px-4 py-3">
                    <span className="text-xs font-bold text-brand-600">{a.contact?.lead_score ?? 0}</span>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`text-xs px-1.5 py-0.5 rounded font-medium ${priorityColor(a.priority)}`}>
                      P{a.priority}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-gray-600">{a.staff?.name ?? <span className="text-gray-400 italic">Unassigned</span>}</td>
                  <td className="px-4 py-3">
                    <span className={`text-xs px-2 py-1 rounded-full font-medium ${statusConfig[a.status]?.color}`}>
                      {statusConfig[a.status]?.label}
                    </span>
                    {a.sla_breached && <span className="ml-1 text-xs text-red-500">⚠ SLA</span>}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-1">
                      {!['completed', 'dropped'].includes(a.status) && (
                        <>
                          <button
                            onClick={() => handleComplete(a.id)}
                            className="text-xs px-2 py-1 bg-green-50 text-green-700 rounded hover:bg-green-100"
                          >Complete</button>
                          <button
                            onClick={() => { setTransferId(a.id); setToStaffId('') }}
                            className="text-xs px-2 py-1 bg-blue-50 text-blue-700 rounded hover:bg-blue-100"
                          >Transfer</button>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />

      {/* Transfer modal */}
      {transferId !== null && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-full max-w-sm shadow-xl">
            <h3 className="font-semibold text-gray-900 mb-4">Transfer Lead</h3>
            <select
              value={toStaffId}
              onChange={e => setToStaffId(e.target.value)}
              className="input w-full mb-4"
            >
              <option value="">Select staff…</option>
              {staffList.map((s: any) => (
                <option key={s.id} value={s.id}>{s.name}</option>
              ))}
            </select>
            <div className="flex gap-2 justify-end">
              <button onClick={() => setTransferId(null)} className="btn btn-ghost">Cancel</button>
              <button onClick={handleTransfer} className="btn btn-primary" disabled={!toStaffId}>Transfer</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
