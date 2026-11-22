// HR Administration → Configuration → Office Details — working hours, geofence, payroll rules.
import { useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'
import { PageHeader, StaffMultiSelect, err, inp } from './hrShared'

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

const TABS = [
  { key: 'hours', label: 'Working hours' },
  { key: 'geofence', label: 'Geofence & timing' },
  { key: 'selfie', label: 'Selfie verification' },
  { key: 'storage', label: 'Storage & Sheets' },
  { key: 'alerts', label: 'Alerts' },
  { key: 'payroll', label: 'Payroll rules' },
] as const
type TabKey = (typeof TABS)[number]['key']

export default function HrOfficeDetailsPage() {
  const [tab, setTab] = useState<TabKey>('hours')
  const [s, setS] = useState<any>(null)
  const [hours, setHours] = useState<any[]>([])
  // what the storage choices can use for this company (Drive needs the plan + a connected Google account; the sheet needs Sheets + a connection)
  const [storage, setStorage] = useState<any>(null)
  useEffect(() => {
    api.get('/hr/settings').then(r => { setS(r.data?.data); setStorage(r.data?.storage ?? null) }).catch(() => {})
    api.get('/working-hours').then(r => setHours((r.data?.hours ?? []).map((h: any) => ({ weekday: h.weekday, is_open: !!h.is_open, start_time: String(h.start_time).slice(0, 5), end_time: String(h.end_time).slice(0, 5) })))).catch(() => {})
  }, [])
  const setF = (k: string, v: unknown) => setS((p: any) => ({ ...p, [k]: v }))
  const setHour = (wd: number, patch: any) => setHours(hs => hs.map(h => h.weekday === wd ? { ...h, ...patch } : h))
  const save = async () => {
    try {
      await api.put('/working-hours', { hours })
      const saved = await api.put('/hr/settings', {
        office_lat: s.office_lat ? Number(s.office_lat) : null,
        office_lng: s.office_lng ? Number(s.office_lng) : null,
        geofence_radius_m: Number(s.geofence_radius_m),
        office_start: String(s.office_start).slice(0, 5),
        office_end: String(s.office_end).slice(0, 5),
        early_window_minutes: Number(s.early_window_minutes),
        grace_minutes: Number(s.grace_minutes),
        clock_out_early_window_minutes: Number(s.clock_out_early_window_minutes ?? 15),
        clock_out_grace_minutes: Number(s.clock_out_grace_minutes ?? 15),
        overtime_multiplier: Number(s.overtime_multiplier),
        late_penalty_amount: Number(s.late_penalty_amount ?? 0),
        payroll_working_days: Number(s.payroll_working_days ?? 26),
        late_note_mode: s.late_note_mode ?? (s.require_late_note === false ? 'off' : 'required'), require_early_leave_note: s.require_early_leave_note,
        overtime_needs_approval: s.overtime_needs_approval, auto_availability: s.auto_availability,
        leave_auto_approve: s.leave_auto_approve, reopen_auto_approve: s.reopen_auto_approve, deduct_unpaid_leave: s.deduct_unpaid_leave, deduct_absent_days: s.deduct_absent_days,
        timezone: s.timezone,
        geofence_mandatory: !!s.geofence_mandatory,
        selfie_required_clock_in: !!s.selfie_required_clock_in,
        selfie_required_clock_out: !!s.selfie_required_clock_out,
        selfie_required_break_start: !!s.selfie_required_break_start,
        selfie_required_break_end: !!s.selfie_required_break_end,
        selfie_storage: s.selfie_storage ?? 'media_library',
        attendance_sheet_enabled: !!s.attendance_sheet_enabled,
        late_clockin_notify_user_ids: s.late_clockin_notify_user_ids ?? [],
        late_clockout_notify_user_ids: s.late_clockout_notify_user_ids ?? [],
        break_overrun_notify_user_ids: s.break_overrun_notify_user_ids ?? [],
        leave_request_notify_user_ids: s.leave_request_notify_user_ids ?? [],
      })
      if (saved.data?.data) setS((p: any) => ({ ...p, ...saved.data.data }))   // picks up the new sheet link
      toast.success('Saved')
    } catch (e) { toast.error(err(e)) }
  }
  const useMyLocation = () => navigator.geolocation?.getCurrentPosition(p => { setF('office_lat', p.coords.latitude.toFixed(7)); setF('office_lng', p.coords.longitude.toFixed(7)) })

  return (
    <div className="p-6 mx-auto space-y-5">
      <PageHeader icon="🏢" title="Office Details" sub="Working hours, geofence and payroll rules for the whole company" />

      {!s ? <div className="text-gray-400">Loading…</div> : (
        <div className="space-y-4">
          {/* Tabs */}
          <div className="flex gap-1 border-b border-gray-200 overflow-x-auto">
            {TABS.map(t => (
              <button key={t.key} onClick={() => setTab(t.key)}
                className={`px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${tab === t.key ? 'border-brand-500 text-brand-600' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>
                {t.label}
              </button>
            ))}
          </div>

          {tab === 'hours' && (
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
          )}

          {tab === 'geofence' && (
            <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
              <h3 className="text-sm font-semibold text-gray-700">Geofence &amp; timing</h3>
              <div className="grid grid-cols-3 gap-3">
                <label className="text-xs text-gray-500">Office lat<input value={s.office_lat ?? ''} onChange={e => setF('office_lat', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
                <label className="text-xs text-gray-500">Office lng<input value={s.office_lng ?? ''} onChange={e => setF('office_lng', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
                <label className="text-xs text-gray-500">Radius (m)<input type="number" value={s.geofence_radius_m} onChange={e => setF('geofence_radius_m', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
              </div>
              <button onClick={useMyLocation} className="text-xs text-indigo-600 hover:underline">📍 Use my location</button>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!s.geofence_mandatory} onChange={e => setF('geofence_mandatory', e.target.checked)} /> Mandatory for WFO staff — block clock in/out/break outside the radius unless a reason is given (WFH staff are never checked)</label>
              <div className="grid grid-cols-2 gap-3">
                <label className="text-xs text-gray-500">Default start (fallback)<input type="time" value={String(s.office_start).slice(0, 5)} onChange={e => setF('office_start', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
                <label className="text-xs text-gray-500">Default end (fallback)<input type="time" value={String(s.office_end).slice(0, 5)} onChange={e => setF('office_end', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
                <label className="text-xs text-gray-500">Clock-in early "success" window (min)<input type="number" value={s.early_window_minutes} onChange={e => setF('early_window_minutes', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
                <label className="text-xs text-gray-500">Clock-in grace / "late" (min)<input type="number" value={s.grace_minutes} onChange={e => setF('grace_minutes', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
                <label className="text-xs text-gray-500">Clock-out early-leave window (min)<input type="number" value={s.clock_out_early_window_minutes ?? 15} onChange={e => setF('clock_out_early_window_minutes', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
                <label className="text-xs text-gray-500">Clock-out grace / "late" (min)<input type="number" value={s.clock_out_grace_minutes ?? 15} onChange={e => setF('clock_out_grace_minutes', e.target.value)} className={`block mt-1 w-full ${inp}`} /></label>
              </div>
              <div className="rounded-lg border border-gray-200 p-3 space-y-1.5">
                <label className="text-xs text-gray-500 block">Late clock-in reason
                  <select value={s.late_note_mode ?? (s.require_late_note === false ? 'off' : 'required')} onChange={e => setF('late_note_mode', e.target.value)} className={`block mt-1 w-full ${inp}`}>
                    <option value="required">Required — staff can't clock in late without writing a reason</option>
                    <option value="optional">Optional — the app asks for a reason, staff may skip it</option>
                    <option value="off">Off — never ask for a reason</option>
                  </select>
                </label>
                <p className="text-[11px] text-gray-400">Applies when a clock-in is past the "late" grace above. The reason is saved with the attendance record (and its session) and shown in the late clock-in alert. The mobile app follows this setting.</p>
              </div>
            </div>
          )}

          {tab === 'selfie' && (
            <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-2">
              <h3 className="text-sm font-semibold text-gray-700">Selfie verification</h3>
              <p className="text-xs text-gray-400">Require a front-camera photo for specific punches. Where the photos are kept is set on the <button type="button" className="text-blue-600 underline" onClick={() => setTab('storage')}>Storage &amp; Sheets</button> tab.</p>
              {[
                ['selfie_required_clock_in', 'Clock-in selfie'],
                ['selfie_required_clock_out', 'Clock-out selfie'],
                ['selfie_required_break_start', 'Break-in selfie'],
                ['selfie_required_break_end', 'Break-out selfie'],
              ].map(([k, label]) => (
                <label key={k} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!s[k]} onChange={e => setF(k, e.target.checked)} /> {label}</label>
              ))}
            </div>
          )}

          {tab === 'storage' && (
            <div className="space-y-4" data-testid="storage-tab">
              <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
                <h3 className="text-sm font-semibold text-gray-700">Where selfie photos are stored</h3>
                <p className="text-xs text-gray-400">Either way each day gets its own folder (<code>HR Attendance → 2026-11-22</code>) holding everyone's photos for that day.</p>
                {([
                  ['media_library', 'Media Library', 'Folders inside Teamzo (Media Library → HR Attendance). Only owners and admins can open them. Counts toward your media storage.', null],
                  ['google_drive', 'Google Drive', 'Your connected Google Drive. Does not use Teamzo storage.', storage?.google_drive?.available === false ? storage.google_drive.reason : null],
                ] as const).map(([val, title, desc, blocked]) => (
                  <label key={val} className={`flex gap-3 rounded-lg border p-3 text-sm ${(s.selfie_storage ?? 'media_library') === val ? 'border-blue-300 bg-blue-50' : 'border-gray-200'} ${blocked ? 'opacity-60' : 'cursor-pointer'}`}>
                    <input type="radio" name="selfie_storage" data-testid={`storage-${val}`} disabled={!!blocked} checked={(s.selfie_storage ?? 'media_library') === val} onChange={() => setF('selfie_storage', val)} className="mt-1" />
                    <span><b>{title}</b><span className="block text-xs text-gray-500">{desc}</span>{blocked && <span data-testid={`storage-${val}-reason`} className="block text-xs text-amber-700 mt-1">🔒 {blocked}</span>}</span>
                  </label>
                ))}
                <p className="text-xs text-gray-400">If Google Drive is chosen but fails, the photo is kept in the Media Library so a clock-in is never blocked.</p>
              </div>
              <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-2">
                <h3 className="text-sm font-semibold text-gray-700">Also save attendance to a Google Sheet</h3>
                <p className="text-xs text-gray-400">Every clock in, clock out, break start and break end is saved in Teamzo as always and also added as a row to your sheet as it happens (date, time, staff, event, late / early / over-limit, reason, work mode, distance, photo link). Earlier punches are not added.</p>
                <label className={`flex items-center gap-2 text-sm ${storage?.google_sheet?.available === false && !s.attendance_sheet_enabled ? 'opacity-60' : ''}`}>
                  <input type="checkbox" data-testid="sheet-enabled" disabled={storage?.google_sheet?.available === false && !s.attendance_sheet_enabled} checked={!!s.attendance_sheet_enabled} onChange={e => setF('attendance_sheet_enabled', e.target.checked)} /> Save attendance to a Google Sheet
                </label>
                {storage?.google_sheet?.available === false && <p data-testid="sheet-reason" className="text-xs text-amber-700">🔒 {storage.google_sheet.reason}</p>}
                {s.attendance_sheet_enabled && s.attendance_sheet_url && <a data-testid="sheet-link" href={s.attendance_sheet_url} target="_blank" rel="noreferrer" className="text-sm text-blue-600 hover:underline">Open the attendance sheet ↗</a>}
                {s.attendance_sheet_enabled && !s.attendance_sheet_url && <p className="text-xs text-gray-400">The sheet is created when you save.</p>}
              </div>
            </div>
          )}

          {tab === 'alerts' && (
            <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
              <h3 className="text-sm font-semibold text-gray-700">Alerts</h3>
              <p className="text-xs text-gray-400">Who gets a push notification for each event. Leave empty to notify no one.</p>
              {[
                ['late_clockin_notify_user_ids', 'Late clock-in'],
                ['late_clockout_notify_user_ids', 'Early / late clock-out'],
                ['break_overrun_notify_user_ids', 'Break runs over its limit'],
                ['leave_request_notify_user_ids', 'New leave request'],
              ].map(([k, label]) => (
                <div key={k}>
                  <div className="text-xs text-gray-500 mb-1">{label}</div>
                  <StaffMultiSelect value={s[k] ?? []} onChange={ids => setF(k, ids)} />
                </div>
              ))}
            </div>
          )}

          {tab === 'payroll' && (
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
                ['require_early_leave_note', 'Require a note when leaving early'],
                ['overtime_needs_approval', 'Overtime needs manager approval'],
                ['auto_availability', 'Attendance controls lead-assignment availability'],
                ['leave_auto_approve', 'Auto-approve leave requests'],
                ['reopen_auto_approve', 'Auto-approve "accidentally clocked out" reopen requests (skips manager review)'],
              ].map(([k, label]) => (
                <label key={k} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!s[k]} onChange={e => setF(k, e.target.checked)} /> {label}</label>
              ))}
              <label className="text-xs text-gray-500 block">Timezone<input value={s.timezone} onChange={e => setF('timezone', e.target.value)} className={`block mt-1 w-48 ${inp}`} /></label>
            </div>
          )}

          <button onClick={save} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium">Save all</button>
        </div>
      )}
    </div>
  )
}
