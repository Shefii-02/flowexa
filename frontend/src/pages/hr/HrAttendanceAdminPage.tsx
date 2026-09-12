// HR Administration → Attendance — every staff member's daily clock in/out, editable, plus
// adding a missed entry. (Personal self-service attendance lives at /hr/attendance.)
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { PageHeader, Modal, hm, err, inp, hhmm, forInput } from './hrShared'

export default function HrAttendanceAdminPage() {
  const [rows, setRows] = useState<any[]>([])
  const [staff, setStaff] = useState<any[]>([])
  const [filter, setFilter] = useState({ date: new Date().toISOString().slice(0, 10), user_id: '', status: '' })
  const [editing, setEditing] = useState<any | null>(null)
  const [adding, setAdding] = useState(false)

  const load = useCallback(() => {
    const params: Record<string, string> = {}
    if (filter.date) params.date = filter.date
    if (filter.user_id) params.user_id = filter.user_id
    if (filter.status) params.status = filter.status
    api.get('/hr/attendance', { params }).then(r => setRows(r.data?.data ?? [])).catch(() => {})
  }, [filter])
  useEffect(() => { load() }, [load])
  useEffect(() => { api.get('/hr/staff-profiles').then(r => setStaff((r.data?.data ?? []).map((x: any) => x.user))).catch(() => {}) }, [])

  const saveEdit = async () => {
    try {
      await api.patch(`/hr/attendance/${editing.id}`, {
        clock_in_at: editing.clock_in_at || null,
        clock_out_at: editing.clock_out_at || null,
        status: editing.status,
        note_message: editing.note_message || null,
        overtime_status: editing.overtime_status,
      })
      setEditing(null); load(); toast.success('Updated')
    } catch (e) { toast.error(err(e)) }
  }

  const addEntry = async (f: any) => {
    try {
      await api.post('/hr/attendance', f)
      setAdding(false); load(); toast.success('Entry added')
    } catch (e) { toast.error(err(e)) }
  }

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-5">
      <PageHeader icon="🗓️" title="Attendance" sub="Every staff member's daily clock in/out — fix or backfill an entry" />

      <div className="space-y-3">
        <div className="flex flex-wrap items-end gap-2">
          <input type="date" value={filter.date} onChange={e => setFilter(f => ({ ...f, date: e.target.value }))} className={inp} />
          <select value={filter.user_id} onChange={e => setFilter(f => ({ ...f, user_id: e.target.value }))} className={inp}>
            <option value="">All staff</option>
            {staff.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          <select value={filter.status} onChange={e => setFilter(f => ({ ...f, status: e.target.value }))} className={inp}>
            <option value="">Any status</option>
            {['present', 'absent', 'on_leave', 'half_day', 'weekly_off'].map(s => <option key={s}>{s}</option>)}
          </select>
          <button onClick={() => setAdding(true)} className="text-sm bg-indigo-600 text-white rounded-lg px-3 py-2">+ Add / fix entry</button>
        </div>

        <div className="bg-white border border-gray-200 rounded-xl overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="text-left text-gray-400 border-b border-gray-100">
              <th className="p-2">Staff</th><th className="p-2">Date</th><th className="p-2">In</th><th className="p-2">Out</th><th className="p-2">Status</th><th className="p-2">Late</th><th className="p-2">OT</th><th className="p-2">Worked</th><th className="p-2">Note</th><th className="p-2"></th>
            </tr></thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} className="border-b border-gray-50">
                  <td className="p-2">{r.user?.name}</td>
                  <td className="p-2 text-gray-500">{r.work_date?.slice(0, 10)}</td>
                  <td className="p-2">{hhmm(r.clock_in_at)}</td>
                  <td className="p-2">{hhmm(r.clock_out_at)}</td>
                  <td className="p-2"><span className={`text-xs px-1.5 py-0.5 rounded ${r.status === 'present' ? 'bg-green-100 text-green-700' : r.status === 'on_leave' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500'}`}>{r.status}</span></td>
                  <td className="p-2 text-amber-600">{r.late_minutes || ''}</td>
                  <td className="p-2">{r.overtime_minutes ? `${hm(r.overtime_minutes)} (${r.overtime_status})` : ''}</td>
                  <td className="p-2">{r.worked_minutes ? hm(r.worked_minutes) : ''}</td>
                  <td className="p-2 text-xs text-gray-400 max-w-[180px] truncate">{r.note_message}</td>
                  <td className="p-2"><button onClick={() => setEditing({ ...r, clock_in_at: forInput(r.clock_in_at), clock_out_at: forInput(r.clock_out_at) })} className="text-xs text-indigo-600 hover:underline">Edit</button></td>
                </tr>
              ))}
              {rows.length === 0 && <tr><td colSpan={10} className="p-4 text-center text-gray-400">No records.</td></tr>}
            </tbody>
          </table>
        </div>

        {editing && (
          <Modal title={`Fix — ${editing.user?.name}`} onClose={() => setEditing(null)}>
            <label className="text-xs text-gray-500">Clock in<input type="datetime-local" value={editing.clock_in_at} onChange={e => setEditing((p: any) => ({ ...p, clock_in_at: e.target.value }))} className={`block mt-1 w-full ${inp}`} /></label>
            <label className="text-xs text-gray-500">Clock out<input type="datetime-local" value={editing.clock_out_at} onChange={e => setEditing((p: any) => ({ ...p, clock_out_at: e.target.value }))} className={`block mt-1 w-full ${inp}`} /></label>
            <label className="text-xs text-gray-500">Status
              <select value={editing.status} onChange={e => setEditing((p: any) => ({ ...p, status: e.target.value }))} className={`block mt-1 w-full ${inp}`}>
                {['present', 'absent', 'on_leave', 'half_day', 'weekly_off'].map(s => <option key={s}>{s}</option>)}
              </select>
            </label>
            <label className="text-xs text-gray-500">Overtime
              <select value={editing.overtime_status} onChange={e => setEditing((p: any) => ({ ...p, overtime_status: e.target.value }))} className={`block mt-1 w-full ${inp}`}>
                {['none', 'pending', 'approved', 'rejected'].map(s => <option key={s}>{s}</option>)}
              </select>
            </label>
            <input value={editing.note_message ?? ''} onChange={e => setEditing((p: any) => ({ ...p, note_message: e.target.value }))} placeholder="Note" className={`w-full ${inp}`} />
            <button onClick={saveEdit} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Save</button>
          </Modal>
        )}
        {adding && <AddEntryModal staff={staff} onClose={() => setAdding(false)} onSave={addEntry} defaultDate={filter.date} />}
      </div>
    </div>
  )
}

function AddEntryModal({ staff, onClose, onSave, defaultDate }: { staff: any[]; onClose: () => void; onSave: (f: any) => void; defaultDate: string }) {
  const [f, setF] = useState({ user_id: '', work_date: defaultDate, clock_in_at: '', clock_out_at: '', status: 'present', note_message: '' })
  return (
    <Modal title="Add / fix attendance" onClose={onClose}>
      <select value={f.user_id} onChange={e => setF(p => ({ ...p, user_id: e.target.value }))} className={`w-full ${inp}`}>
        <option value="">Select staff *</option>
        {staff.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
      </select>
      <label className="text-xs text-gray-500">Date<input type="date" value={f.work_date} onChange={e => setF(p => ({ ...p, work_date: e.target.value }))} className={`block mt-1 w-full ${inp}`} /></label>
      <label className="text-xs text-gray-500">Clock in<input type="datetime-local" value={f.clock_in_at} onChange={e => setF(p => ({ ...p, clock_in_at: e.target.value }))} className={`block mt-1 w-full ${inp}`} /></label>
      <label className="text-xs text-gray-500">Clock out<input type="datetime-local" value={f.clock_out_at} onChange={e => setF(p => ({ ...p, clock_out_at: e.target.value }))} className={`block mt-1 w-full ${inp}`} /></label>
      <select value={f.status} onChange={e => setF(p => ({ ...p, status: e.target.value }))} className={`w-full ${inp}`}>
        {['present', 'absent', 'on_leave', 'half_day', 'weekly_off'].map(s => <option key={s}>{s}</option>)}
      </select>
      <input value={f.note_message} onChange={e => setF(p => ({ ...p, note_message: e.target.value }))} placeholder="Note" className={`w-full ${inp}`} />
      <button onClick={() => f.user_id ? onSave({ ...f, clock_in_at: f.clock_in_at || undefined, clock_out_at: f.clock_out_at || undefined }) : toast.error('Pick a staff member')} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Save</button>
    </Modal>
  )
}
