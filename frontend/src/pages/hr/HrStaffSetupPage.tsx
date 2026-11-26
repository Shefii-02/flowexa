// HR Administration → Staff Setup — per-staff duty time, work mode, pay rate and monthly target.
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { PageHeader, err } from './hrShared'

export default function HrStaffSetupPage() {
  const [rows, setRows] = useState<any[]>([])
  const load = useCallback(() => { api.get('/hr/staff-profiles').then(r => setRows(r.data?.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { load() }, [load])
  // ── Shifts: named start / end / grace, assigned per staff member below ──
  const [shifts, setShifts] = useState<any[]>([])
  const [draft, setDraft] = useState({ name: '', start_time: '09:00', end_time: '18:00', grace_minutes: '' })
  const loadShifts = useCallback(() => { api.get('/hr/shifts').then(r => setShifts(r.data?.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { loadShifts() }, [loadShifts])
  const addShift = async () => {
    if (!draft.name.trim()) { toast.error('Give the shift a name.'); return }
    try {
      await api.post('/hr/shifts', { name: draft.name.trim(), start_time: draft.start_time, end_time: draft.end_time, grace_minutes: draft.grace_minutes === '' ? null : Number(draft.grace_minutes) })
      setDraft({ name: '', start_time: '09:00', end_time: '18:00', grace_minutes: '' }); loadShifts()
    } catch (e) { toast.error(err(e)) }
  }
  const patchShift = async (id: number, patch: Record<string, unknown>) => { try { await api.patch(`/hr/shifts/${id}`, patch); loadShifts(); load() } catch (e) { toast.error(err(e)) } }
  const delShift = async (sh: any) => {
    if (!window.confirm(`Delete the "${sh.name}" shift?${sh.staff_count ? ` ${sh.staff_count} staff on it will fall back to their own / the company hours.` : ''}`)) return
    try { await api.delete(`/hr/shifts/${sh.id}`); loadShifts(); load() } catch (e) { toast.error(err(e)) }
  }
  const save = async (userId: number, patch: Record<string, unknown>) => {
    try { await api.put(`/hr/staff-profiles/${userId}`, patch); load() } catch (e) { toast.error(err(e)) }
  }
  return (
    <div className="p-6 mx-auto space-y-5">
      <PageHeader icon="⚙️" title="Staff Setup" sub="Duty time, work mode, pay rate and monthly target — per staff member" />

      <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3" data-testid="shifts">
        <div>
          <p className="font-semibold text-gray-800">Shifts</p>
          <p className="text-xs text-gray-500">Staff on a shift are marked on time / late / early / overtime against <b>that shift's</b> start, end and grace — the grace is how many minutes after the start still count as only slightly late (blank = the company grace). An end time earlier than the start is a night shift.</p>
        </div>
        {shifts.length > 0 && (
          <table className="w-full text-sm">
            <thead><tr className="text-left text-gray-400 border-b border-gray-100"><th className="p-1.5">Shift</th><th className="p-1.5">Start</th><th className="p-1.5">End</th><th className="p-1.5">Grace (min)</th><th className="p-1.5">Staff</th><th /></tr></thead>
            <tbody>
              {shifts.map(sh => (
                <tr key={sh.id} className="border-b border-gray-50">
                  <td className="p-1.5"><input defaultValue={sh.name} onBlur={e => e.target.value.trim() && e.target.value !== sh.name && patchShift(sh.id, { name: e.target.value.trim() })} className="text-sm border border-gray-200 rounded px-1.5 py-0.5 w-40" /></td>
                  <td className="p-1.5"><input type="time" defaultValue={sh.start_time ?? ''} onBlur={e => e.target.value && e.target.value !== sh.start_time && patchShift(sh.id, { start_time: e.target.value })} className="text-xs border border-gray-200 rounded px-1 w-24" /></td>
                  <td className="p-1.5"><input type="time" defaultValue={sh.end_time ?? ''} onBlur={e => e.target.value && e.target.value !== sh.end_time && patchShift(sh.id, { end_time: e.target.value })} className="text-xs border border-gray-200 rounded px-1 w-24" /></td>
                  <td className="p-1.5"><input type="number" min={0} max={240} defaultValue={sh.grace_minutes ?? ''} placeholder="company" onBlur={e => patchShift(sh.id, { grace_minutes: e.target.value === '' ? null : Number(e.target.value) })} className="text-xs border border-gray-200 rounded px-1 w-20" /></td>
                  <td className="p-1.5 text-gray-500">{sh.staff_count}</td>
                  <td className="p-1.5 text-right"><button onClick={() => delShift(sh)} className="text-xs text-red-500 hover:underline">Delete</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <div className="flex flex-wrap items-end gap-2">
          <input value={draft.name} onChange={e => setDraft({ ...draft, name: e.target.value })} placeholder="New shift name (Morning, Evening, Night…)" className="text-sm border border-gray-200 rounded px-2 py-1 w-64" />
          <input type="time" value={draft.start_time} onChange={e => setDraft({ ...draft, start_time: e.target.value })} className="text-sm border border-gray-200 rounded px-1 py-1" />
          <input type="time" value={draft.end_time} onChange={e => setDraft({ ...draft, end_time: e.target.value })} className="text-sm border border-gray-200 rounded px-1 py-1" />
          <input type="number" min={0} max={240} value={draft.grace_minutes} onChange={e => setDraft({ ...draft, grace_minutes: e.target.value })} placeholder="Grace min" className="text-sm border border-gray-200 rounded px-2 py-1 w-24" />
          <button onClick={addShift} className="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm">+ Add shift</button>
        </div>
      </div>

      <div className="bg-white border border-gray-200 rounded-xl overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-400 border-b border-gray-100">
            <th className="p-2">Staff</th><th className="p-2">Shift</th><th className="p-2">Check-in</th><th className="p-2">Work mode</th><th className="p-2">Duty start</th><th className="p-2">Duty end</th><th className="p-2">Hourly rate</th><th className="p-2">Monthly salary</th><th className="p-2">Monthly target</th>
          </tr></thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.user.id} className="border-b border-gray-50">
                <td className="p-2 flex items-center gap-2">
                  <span className="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 text-xs flex items-center justify-center overflow-hidden">
                    {r.user.avatar ? <img src={`/storage/${r.user.avatar}`} alt="" className="w-full h-full object-cover" /> : r.user.name?.[0]?.toUpperCase()}
                  </span>
                  {r.user.name}
                </td>
                <td className="p-2">
                  <select defaultValue={r.shift_id ?? ''} onChange={e => save(r.user.id, { shift_id: e.target.value ? Number(e.target.value) : null })} className="text-xs border border-gray-200 rounded px-1">
                    <option value="">— No shift —</option>
                    {shifts.filter(sh => sh.is_active || sh.id === r.shift_id).map(sh => <option key={sh.id} value={sh.id}>{sh.name} ({sh.start_time}–{sh.end_time})</option>)}
                  </select>
                </td>
                <td className="p-2">
                  <select defaultValue={r.attendance_type} onChange={e => save(r.user.id, { attendance_type: e.target.value })} className="text-xs border border-gray-200 rounded px-1">
                    <option value="manual">Manual</option><option value="gps">GPS</option>
                  </select>
                </td>
                <td className="p-2">
                  <select defaultValue={r.work_mode} onChange={e => save(r.user.id, { work_mode: e.target.value })} className="text-xs border border-gray-200 rounded px-1">
                    <option value="wfo">WFO</option><option value="wfh">WFH</option><option value="hybrid">Hybrid</option>
                  </select>
                </td>
                {(() => {
                  const sh = shifts.find(x => x.id === r.shift_id)
                  const start = sh?.start_time ?? (r.duty_start ? String(r.duty_start).slice(0, 5) : '')
                  const end = sh?.end_time ?? (r.duty_end ? String(r.duty_end).slice(0, 5) : '')
                  // key: the boxes refill when the shift (or its times) change
                  return (<>
                    <td className="p-2"><input key={`s${r.shift_id}-${start}`} type="time" disabled={!!sh} title={sh ? `Set by the ${sh.name} shift` : undefined} defaultValue={start} onBlur={e => save(r.user.id, { duty_start: e.target.value || null })} className="text-xs border border-gray-200 rounded px-1 w-24 disabled:bg-gray-50 disabled:text-gray-700" /></td>
                    <td className="p-2"><input key={`e${r.shift_id}-${end}`} type="time" disabled={!!sh} title={sh ? `Set by the ${sh.name} shift` : undefined} defaultValue={end} onBlur={e => save(r.user.id, { duty_end: e.target.value || null })} className="text-xs border border-gray-200 rounded px-1 w-24 disabled:bg-gray-50 disabled:text-gray-700" /></td>
                  </>)
                })()}
                <td className="p-2"><input type="number" defaultValue={r.hourly_rate ?? ''} onBlur={e => save(r.user.id, { hourly_rate: e.target.value ? Number(e.target.value) : null })} className="text-xs border border-gray-200 rounded px-1 w-24" /></td>
                <td className="p-2"><input type="number" defaultValue={r.monthly_salary ?? ''} onBlur={e => save(r.user.id, { monthly_salary: e.target.value ? Number(e.target.value) : null })} className="text-xs border border-gray-200 rounded px-1 w-28" /></td>
                <td className="p-2"><input type="number" defaultValue={r.monthly_target ?? ''} onBlur={e => save(r.user.id, { monthly_target: e.target.value ? Number(e.target.value) : null })} className="text-xs border border-gray-200 rounded px-1 w-28" placeholder="₹ / units" /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
