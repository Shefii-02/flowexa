// HR Administration → Configuration → Office Details — working hours, geofence, payroll rules.
import { useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { PageHeader, err, inp } from './hrShared'

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

export default function HrOfficeDetailsPage() {
  const [s, setS] = useState<any>(null)
  const [hours, setHours] = useState<any[]>([])
  useEffect(() => {
    api.get('/hr/settings').then(r => setS(r.data?.data)).catch(() => {})
    api.get('/working-hours').then(r => setHours((r.data?.hours ?? []).map((h: any) => ({ weekday: h.weekday, is_open: !!h.is_open, start_time: String(h.start_time).slice(0, 5), end_time: String(h.end_time).slice(0, 5) })))).catch(() => {})
  }, [])
  const setF = (k: string, v: unknown) => setS((p: any) => ({ ...p, [k]: v }))
  const setHour = (wd: number, patch: any) => setHours(hs => hs.map(h => h.weekday === wd ? { ...h, ...patch } : h))
  const save = async () => {
    try {
      await api.put('/working-hours', { hours })
      await api.put('/hr/settings', {
        office_lat: s.office_lat ? Number(s.office_lat) : null,
        office_lng: s.office_lng ? Number(s.office_lng) : null,
        geofence_radius_m: Number(s.geofence_radius_m),
        office_start: String(s.office_start).slice(0, 5),
        office_end: String(s.office_end).slice(0, 5),
        early_window_minutes: Number(s.early_window_minutes),
        grace_minutes: Number(s.grace_minutes),
        overtime_multiplier: Number(s.overtime_multiplier),
        late_penalty_amount: Number(s.late_penalty_amount ?? 0),
        payroll_working_days: Number(s.payroll_working_days ?? 26),
        require_late_note: s.require_late_note, require_early_leave_note: s.require_early_leave_note,
        overtime_needs_approval: s.overtime_needs_approval, auto_availability: s.auto_availability,
        leave_auto_approve: s.leave_auto_approve, deduct_unpaid_leave: s.deduct_unpaid_leave, deduct_absent_days: s.deduct_absent_days,
        timezone: s.timezone,
      })
      toast.success('Saved')
    } catch (e) { toast.error(err(e)) }
  }
  const useMyLocation = () => navigator.geolocation?.getCurrentPosition(p => { setF('office_lat', p.coords.latitude.toFixed(7)); setF('office_lng', p.coords.longitude.toFixed(7)) })

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-5">
      <PageHeader icon="🏢" title="Office Details" sub="Working hours, geofence and payroll rules for the whole company" />

      {!s ? <div className="text-gray-400">Loading…</div> : (
        <div className="space-y-4 max-w-xl">
          <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-2">
            <h3 className="text-sm font-semibold text-gray-700">Working hours — per day</h3>
            {hours.sort((a, b) => a.weekday - b.weekday).map(h => (
              <div key={h.weekday} className="flex items-center gap-3 text-sm">
                <label className="flex items-center gap-2 w-28"><input type="checkbox" checked={h.is_open} onChange={e => setHour(h.weekday, { is_open: e.target.checked })} />{DAYS[h.weekday]}</label>
                <input type="time" disabled={!h.is_open} value={h.start_time} onChange={e => setHour(h.weekday, { start_time: e.target.value })} className="border border-gray-200 rounded px-2 py-1 disabled:opacity-40" />
                <span className="text-gray-400">–</span>
                <input type="time" disabled={!h.is_open} value={h.end_time} onChange={e => setHour(h.weekday, { end_time: e.target.value })} className="border border-gray-200 rounded px-2 py-1 disabled:opacity-40" />
              </div>
            ))}
          </div>

          <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
            <h3 className="text-sm font-semibold text-gray-700">Geofence &amp; timing</h3>
            <div className="grid grid-cols-3 gap-3">
              <label className="text-xs text-gray-500">Office lat<input value={s.office_lat ?? ''} onChange={e => setF('office_lat', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
              <label className="text-xs text-gray-500">Office lng<input value={s.office_lng ?? ''} onChange={e => setF('office_lng', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
              <label className="text-xs text-gray-500">Radius (m)<input type="number" value={s.geofence_radius_m} onChange={e => setF('geofence_radius_m', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
            </div>
            <button onClick={useMyLocation} className="text-xs text-indigo-600 hover:underline">📍 Use my location</button>
            <div className="grid grid-cols-2 gap-3">
              <label className="text-xs text-gray-500">Default start (fallback)<input type="time" value={String(s.office_start).slice(0, 5)} onChange={e => setF('office_start', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
              <label className="text-xs text-gray-500">Default end (fallback)<input type="time" value={String(s.office_end).slice(0, 5)} onChange={e => setF('office_end', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
              <label className="text-xs text-gray-500">Early "success" window (min)<input type="number" value={s.early_window_minutes} onChange={e => setF('early_window_minutes', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
              <label className="text-xs text-gray-500">Grace / "warning" (min)<input type="number" value={s.grace_minutes} onChange={e => setF('grace_minutes', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
            </div>
          </div>

          <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
            <h3 className="text-sm font-semibold text-gray-700">Payroll rules</h3>
            <div className="grid grid-cols-3 gap-3">
              <label className="text-xs text-gray-500">Working days / month<input type="number" value={s.payroll_working_days ?? 26} onChange={e => setF('payroll_working_days', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
              <label className="text-xs text-gray-500">Late penalty / day<input type="number" value={s.late_penalty_amount ?? 0} onChange={e => setF('late_penalty_amount', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
              <label className="text-xs text-gray-500">Overtime multiplier<input type="number" step="0.1" value={s.overtime_multiplier} onChange={e => setF('overtime_multiplier', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
            </div>
            {[
              ['deduct_unpaid_leave', 'Deduct pay for unpaid leave'],
              ['deduct_absent_days', 'Deduct pay for absent days'],
              ['require_late_note', 'Require a note when clocking in late'],
              ['require_early_leave_note', 'Require a note when leaving early'],
              ['overtime_needs_approval', 'Overtime needs manager approval'],
              ['auto_availability', 'Attendance controls lead-assignment availability'],
              ['leave_auto_approve', 'Auto-approve leave requests'],
            ].map(([k, label]) => (
              <label key={k} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!s[k]} onChange={e => setF(k, e.target.checked)} /> {label}</label>
            ))}
            <label className="text-xs text-gray-500 block">Timezone<input value={s.timezone} onChange={e => setF('timezone', e.target.value)} className={`block mt-1 w-48 ${inp}`} /></label>
          </div>

          <button onClick={save} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium">Save all</button>
        </div>
      )}
    </div>
  )
}
