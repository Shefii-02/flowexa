// HR Administration → Staff Setup — per-staff duty time, work mode, pay rate and monthly target.
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { PageHeader, err } from './hrShared'

export default function HrStaffSetupPage() {
  const [rows, setRows] = useState<any[]>([])
  const load = useCallback(() => { api.get('/hr/staff-profiles').then(r => setRows(r.data?.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { load() }, [load])
  const save = async (userId: number, patch: Record<string, unknown>) => {
    try { await api.put(`/hr/staff-profiles/${userId}`, patch); load() } catch (e) { toast.error(err(e)) }
  }
  return (
    <div className="p-6 max-w-6xl mx-auto space-y-5">
      <PageHeader icon="⚙️" title="Staff Setup" sub="Duty time, work mode, pay rate and monthly target — per staff member" />

      <div className="bg-white border border-gray-200 rounded-xl overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-400 border-b border-gray-100">
            <th className="p-2">Staff</th><th className="p-2">Check-in</th><th className="p-2">Work mode</th><th className="p-2">Duty start</th><th className="p-2">Duty end</th><th className="p-2">Hourly rate</th><th className="p-2">Monthly salary</th><th className="p-2">Monthly target</th>
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
                  <select defaultValue={r.attendance_type} onChange={e => save(r.user.id, { attendance_type: e.target.value })} className="text-xs border border-gray-200 rounded px-1">
                    <option value="manual">Manual</option><option value="gps">GPS</option>
                  </select>
                </td>
                <td className="p-2">
                  <select defaultValue={r.work_mode} onChange={e => save(r.user.id, { work_mode: e.target.value })} className="text-xs border border-gray-200 rounded px-1">
                    <option value="wfo">WFO</option><option value="wfh">WFH</option><option value="hybrid">Hybrid</option>
                  </select>
                </td>
                <td className="p-2"><input type="time" defaultValue={r.duty_start ? String(r.duty_start).slice(0, 5) : ''} onBlur={e => save(r.user.id, { duty_start: e.target.value || null })} className="text-xs border border-gray-200 rounded px-1 w-24" /></td>
                <td className="p-2"><input type="time" defaultValue={r.duty_end ? String(r.duty_end).slice(0, 5) : ''} onBlur={e => save(r.user.id, { duty_end: e.target.value || null })} className="text-xs border border-gray-200 rounded px-1 w-24" /></td>
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
