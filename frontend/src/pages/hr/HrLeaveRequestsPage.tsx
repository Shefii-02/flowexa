// HR Administration → Leave Requests — review and approve/reject the whole team's leave.
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { PageHeader, err, inp } from './hrShared'

export default function HrLeaveRequestsPage() {
  const [rows, setRows] = useState<any[]>([])
  const [staff, setStaff] = useState<any[]>([])
  const [f, setF] = useState({ status: '', user_id: '', date: '' })
  const load = useCallback(() => {
    api.get('/hr/leave', { params: {
      scope: 'team',
      status: f.status || undefined,
      user_id: f.user_id || undefined,
      date: f.date || undefined,
    } }).then(r => setRows(r.data?.data ?? [])).catch(() => {})
  }, [f])
  useEffect(() => { load() }, [load])
  useEffect(() => { api.get('/staff').then(r => setStaff(r.data?.data ?? r.data ?? [])).catch(() => {}) }, [])
  const review = async (id: number, decision: 'approved' | 'rejected') => {
    const note = decision === 'rejected' ? (prompt('Reason?') ?? '') : ''
    try { await api.post(`/hr/leave/${id}/review`, { decision, note }); load() } catch (e) { toast.error(err(e)) }
  }
  return (
    <div className="p-6 max-w-6xl mx-auto space-y-3">
      <PageHeader icon="🌴" title="Leave Requests" sub="Review and approve or reject the team's leave, day by day or staff by staff" />

      <div className="bg-white border border-gray-200 rounded-xl p-3 flex flex-wrap items-center gap-2">
        <input type="date" value={f.date} onChange={e => setF(p => ({ ...p, date: e.target.value }))} className={`${inp} text-xs`} />
        <select value={f.user_id} onChange={e => setF(p => ({ ...p, user_id: e.target.value }))} className={`${inp} text-xs`}>
          <option value="">All staff</option>
          {staff.map((s: any) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <select value={f.status} onChange={e => setF(p => ({ ...p, status: e.target.value }))} className={`${inp} text-xs`}>
          <option value="">Any status</option>
          {['pending', 'approved', 'rejected', 'cancelled'].map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        {(f.date || f.user_id || f.status) && (
          <button onClick={() => setF({ status: '', user_id: '', date: '' })} className="text-xs text-gray-500 hover:underline">Clear</button>
        )}
      </div>
      <div className="space-y-2">
        {rows.map(r => (
          <div key={r.id} className="bg-white border border-gray-200 rounded-xl p-3 flex items-center justify-between gap-3">
            <div>
              <div className="text-sm font-medium">{r.user?.name} · {r.leave_type?.name} · {r.days}d</div>
              <div className="text-xs text-gray-400">{r.start_date} → {r.end_date}{r.reason ? ` · ${r.reason}` : ''}</div>
            </div>
            {r.status === 'pending'
              ? <div className="flex gap-1.5"><button onClick={() => review(r.id, 'approved')} className="text-xs px-2.5 py-1 bg-green-600 text-white rounded">Approve</button><button onClick={() => review(r.id, 'rejected')} className="text-xs px-2.5 py-1 border border-red-200 text-red-600 rounded">Reject</button></div>
              : <span className={`text-xs px-2 py-0.5 rounded-full ${r.status === 'approved' ? 'bg-green-100 text-green-700' : r.status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-400'}`}>{r.status}</span>}
          </div>
        ))}
        {rows.length === 0 && <p className="text-sm text-gray-400 text-center py-6">No team leave requests.</p>}
      </div>
    </div>
  )
}
