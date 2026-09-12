import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

type BreakType = { id: number; name: string; max_minutes: number | null; daily_limit: number | null; requires_gps: boolean }
type BreakSession = { id: number; start_at: string; end_at: string | null; minutes: number | null; over_limit: boolean; break_type?: { name: string } | null }
type Attendance = {
  id: number
  clock_in_at: string | null
  clock_out_at: string | null
  clock_in_status: string | null
  note_color: string | null
  note_message: string | null
  late_minutes: number
  early_leave_minutes: number
  overtime_minutes: number
  overtime_status: string
  worked_minutes: number
  break_minutes: number
  breaks: BreakSession[]
}
type MeResponse = {
  profile: { attendance_type: string; work_mode: string }
  settings: { office_start: string; office_end: string; geofence_radius_m: number }
  schedule: { start: string; end: string }
  today: Attendance | null
  open_break: BreakSession | null
  break_types: BreakType[]
  month: { present_days: number; worked_hours: number; late_days: number; overtime_hours: number; on_leave_days: number }
}
type LeaveType = { id: number; name: string; color: string; is_paid: boolean }
type LeaveRequest = {
  id: number; status: string; start_date: string; end_date: string; days: number; half_day: boolean
  reason: string | null; leave_type?: { name: string; color: string } | null; review_note: string | null
}
type HistoryDay = Attendance & { work_date: string; status: string }

const COLOR: Record<string, string> = {
  danger: 'bg-red-50 text-red-700 border-red-200',
  warning: 'bg-amber-50 text-amber-700 border-amber-200',
  success: 'bg-green-50 text-green-700 border-green-200',
  info: 'bg-blue-50 text-blue-700 border-blue-200',
  primary: 'bg-indigo-50 text-indigo-700 border-indigo-200',
}
const hm = (min: number) => `${Math.floor(min / 60)}h ${min % 60}m`
const time = (s: string | null) => (s ? new Date(s).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—')

function getPosition(): Promise<{ lat: number; lng: number } | null> {
  return new Promise(resolve => {
    if (!navigator.geolocation) return resolve(null)
    navigator.geolocation.getCurrentPosition(
      p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 8000 },
    )
  })
}

export default function AttendancePage() {
  const [tab, setTab] = useState<'attendance' | 'leave'>('attendance')
  const [me, setMe] = useState<MeResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [breakTypeId, setBreakTypeId] = useState<string>('')
  const [note, setNote] = useState('')

  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7))
  const [history, setHistory] = useState<HistoryDay[]>([])
  const [historyLeave, setHistoryLeave] = useState<LeaveRequest[]>([])
  const [historyLoading, setHistoryLoading] = useState(true)

  const load = useCallback(() => {
    setLoading(true)
    api.get('/hr/attendance/me').then(r => setMe(r.data)).finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  const loadHistory = useCallback(() => {
    setHistoryLoading(true)
    api.get('/hr/attendance/me/history', { params: { month } })
      .then(r => { setHistory(r.data?.data ?? []); setHistoryLeave(r.data?.leave ?? []) })
      .finally(() => setHistoryLoading(false))
  }, [month])
  useEffect(() => { loadHistory() }, [loadHistory])

  const act = async (path: string, body: Record<string, unknown> = {}) => {
    setBusy(true)
    try {
      const pos = await getPosition()
      await api.post(path, { ...body, ...(pos ?? {}), source: 'web', note: note || undefined })
      setNote(''); load(); loadHistory()
    } catch (e: any) {
      toast.error(e.response?.data?.message ?? (Object.values(e.response?.data?.errors ?? {})[0] as string[] | undefined)?.[0] ?? 'Failed')
    } finally { setBusy(false) }
  }

  if (loading || !me) return <div className="p-6 text-center text-gray-400">Loading…</div>

  const t = me.today
  const clockedIn = !!t?.clock_in_at && !t?.clock_out_at
  const onBreak = !!me.open_break
  const done = !!t?.clock_out_at

  return (
    <div className="p-6 max-w-2xl mx-auto space-y-5">
      <div>
        <h1 className="page-title">🕒 My Attendance</h1>
        <p className="page-sub">Office {me.settings.office_start.slice(0, 5)}–{me.settings.office_end.slice(0, 5)} · {me.profile.attendance_type === 'gps' ? `GPS check-in (${me.settings.geofence_radius_m}m)` : 'Manual check-in'} · {me.profile.work_mode.toUpperCase()}</p>
      </div>

      <div className="flex gap-1 border-b border-gray-200">
        {(['attendance', 'leave'] as const).map(x => (
          <button key={x} onClick={() => setTab(x)}
            className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px ${tab === x ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500'}`}>
            {x === 'attendance' ? 'Attendance' : 'Leave'}
          </button>
        ))}
      </div>

      {tab === 'attendance' && (
        <>
          {/* Status card */}
          <div className="bg-white border border-gray-200 rounded-2xl p-5">
            {t?.note_message && (
              <div className={`text-xs border rounded-lg px-3 py-2 mb-3 ${COLOR[t.note_color ?? 'info']}`}>{t.note_message}</div>
            )}
            <div className="grid grid-cols-2 gap-3 text-center mb-4">
              <div><div className="text-lg font-bold text-gray-900">{time(t?.clock_in_at ?? null)}</div><div className="text-[11px] text-gray-400">Clock in</div></div>
              <div><div className="text-lg font-bold text-gray-900">{time(t?.clock_out_at ?? null)}</div><div className="text-[11px] text-gray-400">Clock out</div></div>
            </div>

            {(clockedIn || onBreak) && (
              <input value={note} onChange={e => setNote(e.target.value)} placeholder="Note (reason for late / early / overtime)…"
                className="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 mb-3" />
            )}

            <div className="flex flex-wrap gap-2">
              {!t?.clock_in_at && (
                <button onClick={() => act('/hr/attendance/clock-in')} disabled={busy}
                  className="px-5 py-2.5 bg-green-600 text-white rounded-lg text-sm font-semibold disabled:opacity-50">📍 Clock In</button>
              )}
              {clockedIn && !onBreak && (
                <>
                  <div className="flex gap-1.5">
                    <select value={breakTypeId} onChange={e => setBreakTypeId(e.target.value)} className="text-sm border border-gray-200 rounded-lg px-2">
                      <option value="">Break</option>
                      {me.break_types.map(b => <option key={b.id} value={b.id}>{b.name}{b.max_minutes ? ` (${b.max_minutes}m)` : ''}</option>)}
                    </select>
                    <button onClick={() => act('/hr/attendance/break/start', { break_type_id: breakTypeId ? Number(breakTypeId) : undefined })} disabled={busy}
                      className="px-3 py-2 bg-amber-500 text-white rounded-lg text-sm font-semibold disabled:opacity-50">☕ Start Break</button>
                  </div>
                  <button onClick={() => act('/hr/attendance/clock-out')} disabled={busy}
                    className="px-5 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold disabled:opacity-50">Clock Out</button>
                </>
              )}
              {onBreak && (
                <button onClick={() => act('/hr/attendance/break/end')} disabled={busy}
                  className="px-5 py-2.5 bg-amber-600 text-white rounded-lg text-sm font-semibold disabled:opacity-50">▶️ End Break</button>
              )}
              {done && <span className="text-sm text-gray-400 py-2">Shift complete — worked {hm(t!.worked_minutes)}</span>}
            </div>

            {t && (t.late_minutes > 0 || t.overtime_minutes > 0 || t.early_leave_minutes > 0 || t.break_minutes > 0) && (
              <div className="flex gap-4 text-xs text-gray-500 mt-4 flex-wrap">
                {t.late_minutes > 0 && <span>⚠️ Late {t.late_minutes}m</span>}
                {t.early_leave_minutes > 0 && <span>Early out {t.early_leave_minutes}m</span>}
                {t.overtime_minutes > 0 && <span>OT {hm(t.overtime_minutes)} ({t.overtime_status})</span>}
                {t.break_minutes > 0 && <span>Breaks {t.break_minutes}m</span>}
              </div>
            )}
          </div>

          {/* Breaks list */}
          {(t?.breaks?.length ?? 0) > 0 && (
            <div className="bg-white border border-gray-200 rounded-xl p-4">
              <h3 className="text-sm font-semibold text-gray-700 mb-2">Today's breaks</h3>
              {t!.breaks.map(b => (
                <div key={b.id} className="flex justify-between text-xs border-b border-gray-50 py-1.5">
                  <span>{b.break_type?.name ?? 'Break'} · {time(b.start_at)}–{time(b.end_at)}</span>
                  <span className={b.over_limit ? 'text-red-500 font-medium' : 'text-gray-400'}>{b.minutes ?? '…'}m{b.over_limit ? ' (over)' : ''}</span>
                </div>
              ))}
            </div>
          )}

          {/* Month summary */}
          <div className="grid grid-cols-3 sm:grid-cols-5 gap-2">
            {[
              ['Present', me.month.present_days], ['Hours', me.month.worked_hours], ['Late', me.month.late_days],
              ['OT hrs', me.month.overtime_hours], ['Leave', me.month.on_leave_days],
            ].map(([k, v]) => (
              <div key={k} className="bg-white border border-gray-200 rounded-xl px-3 py-2 text-center">
                <div className="text-lg font-bold text-gray-900">{v}</div><div className="text-[11px] text-gray-400">{k}</div>
              </div>
            ))}
          </div>

          {/* Day-by-day history — clock in/out, every break in/out, and leave days */}
          <div className="bg-white border border-gray-200 rounded-xl p-4">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-gray-700">Attendance history</h3>
              <input type="month" value={month} onChange={e => setMonth(e.target.value)}
                className="text-sm border border-gray-200 rounded-lg px-2 py-1" />
            </div>
            {historyLoading ? (
              <p className="text-sm text-gray-400 text-center py-6">Loading…</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="text-left text-gray-400 border-b border-gray-100">
                    <th className="p-2">Date</th><th className="p-2">Clock in</th><th className="p-2">Clock out</th>
                    <th className="p-2">Breaks</th><th className="p-2">Worked</th><th className="p-2">Status</th>
                  </tr></thead>
                  <tbody>
                    {history.map(d => (
                      <tr key={d.id} className="border-b border-gray-50 align-top">
                        <td className="p-2 text-gray-500 whitespace-nowrap">{d.work_date.slice(0, 10)}</td>
                        <td className="p-2">{time(d.clock_in_at)}</td>
                        <td className="p-2">{time(d.clock_out_at)}</td>
                        <td className="p-2">
                          {d.breaks?.length ? d.breaks.map(b => (
                            <div key={b.id} className="text-xs text-gray-500 whitespace-nowrap">
                              {b.break_type?.name ?? 'Break'}: {time(b.start_at)}–{time(b.end_at)}
                            </div>
                          )) : <span className="text-gray-300">—</span>}
                        </td>
                        <td className="p-2">{d.worked_minutes ? hm(d.worked_minutes) : '—'}
                          {d.late_minutes > 0 && <div className="text-[11px] text-amber-600">Late {d.late_minutes}m</div>}
                          {d.overtime_minutes > 0 && <div className="text-[11px] text-indigo-500">OT {hm(d.overtime_minutes)}</div>}
                        </td>
                        <td className="p-2">
                          <span className={`text-xs px-1.5 py-0.5 rounded ${d.status === 'present' ? 'bg-green-100 text-green-700' : d.status === 'on_leave' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500'}`}>{d.status}</span>
                        </td>
                      </tr>
                    ))}
                    {history.length === 0 && historyLeave.length === 0 && (
                      <tr><td colSpan={6} className="p-4 text-center text-gray-400">No records this month.</td></tr>
                    )}
                  </tbody>
                </table>
              </div>
            )}
            {historyLeave.length > 0 && (
              <div className="mt-3 pt-3 border-t border-gray-100 space-y-1.5">
                <p className="text-xs font-medium text-gray-500">Leave this month</p>
                {historyLeave.map(l => (
                  <div key={l.id} className="flex items-center justify-between text-xs">
                    <span>{l.leave_type?.name} · {l.start_date} → {l.end_date} ({l.days}d){l.reason ? ` · ${l.reason}` : ''}</span>
                    <span className={`px-2 py-0.5 rounded-full ${l.status === 'approved' ? 'bg-green-100 text-green-700' : l.status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'}`}>{l.status}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </>
      )}

      {tab === 'leave' && <LeaveTab />}
    </div>
  )
}

function LeaveTab() {
  const [types, setTypes] = useState<LeaveType[]>([])
  const [requests, setRequests] = useState<LeaveRequest[]>([])
  const [form, setForm] = useState({ leave_type_id: '', start_date: '', end_date: '', half_day: false, reason: '' })
  const [showForm, setShowForm] = useState(false)

  const load = useCallback(() => {
    api.get('/hr/leave/types').then(r => setTypes(r.data?.data ?? [])).catch(() => {})
    api.get('/hr/leave', { params: { scope: 'mine' } }).then(r => setRequests(r.data?.data ?? [])).catch(() => {})
  }, [])
  useEffect(() => { load() }, [load])

  const submit = async () => {
    if (!form.leave_type_id || !form.start_date || !form.end_date) { toast.error('Fill all fields'); return }
    try {
      await api.post('/hr/leave', {
        leave_type_id: Number(form.leave_type_id), start_date: form.start_date, end_date: form.end_date,
        half_day: form.half_day, reason: form.reason || undefined,
      })
      setShowForm(false); setForm({ leave_type_id: '', start_date: '', end_date: '', half_day: false, reason: '' }); load()
      toast.success('Leave requested.')
    } catch (e: any) { toast.error(e.response?.data?.message ?? 'Failed') }
  }

  const cancel = async (id: number) => { await api.post(`/hr/leave/${id}/cancel`); load() }

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <button onClick={() => setShowForm(v => !v)} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium">+ Request Leave</button>
      </div>
      {showForm && (
        <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
          <select value={form.leave_type_id} onChange={e => setForm(p => ({ ...p, leave_type_id: e.target.value }))} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="">Leave type</option>
            {types.map(t => <option key={t.id} value={t.id}>{t.name}{t.is_paid ? '' : ' (unpaid)'}</option>)}
          </select>
          <div className="grid grid-cols-2 gap-3">
            <input type="date" value={form.start_date} onChange={e => setForm(p => ({ ...p, start_date: e.target.value }))} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
            <input type="date" value={form.end_date} onChange={e => setForm(p => ({ ...p, end_date: e.target.value }))} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
          </div>
          <label className="text-xs text-gray-500 flex items-center gap-1.5"><input type="checkbox" checked={form.half_day} onChange={e => setForm(p => ({ ...p, half_day: e.target.checked }))} /> Half day</label>
          <textarea value={form.reason} onChange={e => setForm(p => ({ ...p, reason: e.target.value }))} rows={2} placeholder="Reason" className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none" />
          <button onClick={submit} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium">Submit</button>
        </div>
      )}
      <div className="space-y-2">
        {requests.map(r => (
          <div key={r.id} className="bg-white border border-gray-200 rounded-xl p-3 flex items-center justify-between">
            <div>
              <div className="text-sm font-medium">{r.leave_type?.name} · {r.days} day{r.days === 1 ? '' : 's'}</div>
              <div className="text-xs text-gray-400">{r.start_date} → {r.end_date}{r.reason ? ` · ${r.reason}` : ''}</div>
              {r.review_note && <div className="text-xs text-gray-400 italic">“{r.review_note}”</div>}
            </div>
            <div className="flex items-center gap-2">
              <span className={`text-xs px-2 py-0.5 rounded-full ${r.status === 'approved' ? 'bg-green-100 text-green-700' : r.status === 'rejected' ? 'bg-red-100 text-red-700' : r.status === 'cancelled' ? 'bg-gray-100 text-gray-400' : 'bg-amber-100 text-amber-700'}`}>{r.status}</span>
              {['pending', 'approved'].includes(r.status) && new Date(r.end_date) >= new Date(new Date().toDateString()) && (
                <button onClick={() => cancel(r.id)} className="text-xs text-gray-400 hover:text-red-500">Cancel</button>
              )}
            </div>
          </div>
        ))}
        {requests.length === 0 && <p className="text-sm text-gray-400 text-center py-6">No leave requests.</p>}
      </div>
    </div>
  )
}
