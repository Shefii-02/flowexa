// src/components/dashboard/StartYourDay.tsx
// "Start your day" on the dashboard: clock in / break / clock out in one place, today's status, and a leave request that shows what each
// leave type still has (paid vs other) and refuses what cannot be taken.
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { api } from '@/api/client'
import { Badge, Button, Drawer, Input } from '@/components/ui'
import { getPosition, SelfieCapture } from '@/pages/hr/AttendancePage'
import { getError } from '@/utils'

type LeaveType = { id: number; name: string; is_paid: boolean; max_days_per_year: number | null; used: number; left: number | null }
const time = (s?: string | null) => (s ? new Date(s).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—')
const hm = (min?: number) => (min ? `${Math.floor(min / 60)}h ${min % 60}m` : '0h 0m')
const hello = () => { const h = new Date().getHours(); return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening' }
const dayCount = (a: string, b: string, half: boolean) => (!a || !b || b < a ? 0 : half ? 0.5 : Math.round((new Date(b).getTime() - new Date(a).getTime()) / 86400000) + 1)

function LeaveRequest({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const [types, setTypes] = useState<LeaveType[]>([])
  const [f, setF] = useState({ leave_type_id: '', start_date: new Date().toISOString().slice(0, 10), end_date: new Date().toISOString().slice(0, 10), half_day: false, reason: '' })
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (open) api.get('/hr/leave/types').then((r) => setTypes(r.data?.data ?? [])).catch(() => {}) }, [open])
  const type = types.find((t) => String(t.id) === f.leave_type_id)
  const days = dayCount(f.start_date, f.end_date, f.half_day)
  const problem =
    !f.leave_type_id ? 'Choose a leave type.'
    : !f.start_date || !f.end_date ? 'Choose the dates.'
    : f.end_date < f.start_date ? 'The end date is before the start date.'
    : f.half_day && f.start_date !== f.end_date ? 'A half day is a single date.'
    : type && type.left !== null && days > type.left ? `Only ${type.left} day(s) of ${type.name} left this year — you asked for ${days}.`
    : null
  const paid = types.filter((t) => t.is_paid), other = types.filter((t) => !t.is_paid)
  const submit = async () => {
    if (problem) { toast.error(problem); return }
    setBusy(true)
    try { await api.post('/hr/leave', { ...f, leave_type_id: +f.leave_type_id, reason: f.reason || undefined }); toast.success('Leave requested.'); onDone(); onClose() }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const opt = (t: LeaveType) => <option key={t.id} value={t.id} disabled={t.left === 0}>{t.name} — {t.left === null ? 'no limit' : `${t.left} of ${t.max_days_per_year} left`}</option>
  return (
    <Drawer open={open} onClose={onClose} title="Request leave" size="sm"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={submit} loading={busy} disabled={!!problem}>Submit request</Button></>}>
      <div className="space-y-3">
        <div><label className="block text-xs font-medium text-gray-600 mb-1">Leave type</label>
          <select className="input" value={f.leave_type_id} onChange={(e) => setF({ ...f, leave_type_id: e.target.value })}>
            <option value="">Choose…</option>
            {paid.length > 0 && <optgroup label="Paid leave">{paid.map(opt)}</optgroup>}
            {other.length > 0 && <optgroup label="Other leave (unpaid)">{other.map(opt)}</optgroup>}
          </select>
          {type && <p className="text-[11px] text-gray-500 mt-1">{type.is_paid ? 'Paid — no salary deduction.' : 'Unpaid — the days may be deducted from salary.'}</p>}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Input label="From" type="date" value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value, end_date: f.end_date < e.target.value ? e.target.value : f.end_date })} />
          <Input label="To" type="date" min={f.start_date} value={f.end_date} onChange={(e) => setF({ ...f, end_date: e.target.value })} />
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.half_day} onChange={(e) => setF({ ...f, half_day: e.target.checked, end_date: e.target.checked ? f.start_date : f.end_date })} /> Half day</label>
        <Input label="Reason" placeholder="Why do you need leave?" value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} />
        <p className={`text-xs ${problem ? 'text-red-600' : 'text-gray-500'}`}>{problem ?? `${days} day${days === 1 ? '' : 's'} requested.`}</p>
      </div>
    </Drawer>
  )
}

export default function StartYourDay({ name }: { name?: string }) {
  const [me, setMe] = useState<any>(null)
  const [hidden, setHidden] = useState(false)
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState('')
  const [breakType, setBreakType] = useState('')
  const [camera, setCamera] = useState<{ resolve: (f: File | null) => void } | null>(null)
  const [leaveOpen, setLeaveOpen] = useState(false)
  const [types, setTypes] = useState<LeaveType[]>([])

  const load = useCallback(() => {
    api.get('/hr/attendance/me').then((r) => setMe(r.data)).catch((e) => { if (e?.response?.status === 404 || e?.response?.status === 403) setHidden(true) })
    api.get('/hr/leave/types').then((r) => setTypes(r.data?.data ?? [])).catch(() => {})
  }, [])
  useEffect(() => { load() }, [load])

  const act = async (path: string, body: Record<string, unknown> = {}) => {
    setBusy(true)
    try {
      const t = me?.today
      if (path.endsWith('/clock-in') && !!t?.clock_in_at && !note.trim()) { toast.error('Please tell us why you are clocking in again.'); return }
      const key = path.split('/hr/attendance/')[1].replace('/', '_').replace('-', '_')   // clock_in | clock_out | break_start | break_end
      let selfie: File | null = null
      if (me?.settings?.[`selfie_required_${key}`]) {
        selfie = await new Promise<File | null>((resolve) => setCamera({ resolve }))
        setCamera(null)
        if (!selfie) { toast.error('A selfie is required for this action.'); return }
      }
      const pos = await getPosition()
      const payload: Record<string, unknown> = { ...body, ...(pos ?? {}), source: 'web', note: note || undefined }
      if (selfie) {
        const fd = new FormData()
        Object.entries(payload).forEach(([k, v]) => { if (v !== undefined && v !== null) fd.append(k, String(v)) })
        fd.append('selfie', selfie)
        await api.post(path, fd)
      } else { await api.post(path, payload) }
      setNote(''); load()
    } catch (e: any) {
      toast.error(e.response?.data?.message ?? (Object.values(e.response?.data?.errors ?? {})[0] as string[] | undefined)?.[0] ?? 'Failed')
    } finally { setBusy(false) }
  }

  if (hidden || !me) return null
  const t = me.today
  const clockedIn = !!t?.clock_in_at && !t?.clock_out_at
  const onBreak = !!me.open_break
  const done = !!t?.clock_out_at
  const paid = types.filter((x) => x.is_paid), other = types.filter((x) => !x.is_paid)
  const chip = (x: LeaveType) => <span key={x.id} className="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">{x.name}: <b>{x.left === null ? '∞' : x.left}</b></span>

  return (
    <div className="card p-5" data-testid="start-your-day">
      {camera && <SelfieCapture onDone={(f) => camera.resolve(f)} />}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-lg font-semibold text-gray-900">{done ? 'Day done' : clockedIn ? (onBreak ? 'You are on a break' : 'You are working') : 'Start your day'}{name ? `, ${name}` : ''} {done ? '✅' : clockedIn ? '💼' : '🌅'}</p>
          <p className="text-xs text-gray-500">{hello()} · Office {String(me.settings?.office_start ?? '').slice(0, 5)}–{String(me.settings?.office_end ?? '').slice(0, 5)}{me.shift?.name ? ` · ${me.shift.name} shift` : ''}</p>
        </div>
        <div className="flex gap-6 text-center">
          <div><p className="text-lg font-bold">{time(t?.clock_in_at)}</p><p className="text-[11px] text-gray-400">Clock in</p></div>
          <div><p className="text-lg font-bold">{time(t?.clock_out_at)}</p><p className="text-[11px] text-gray-400">Clock out</p></div>
          <div><p className="text-lg font-bold">{hm(t?.worked_minutes)}</p><p className="text-[11px] text-gray-400">Worked</p></div>
        </div>
      </div>

      {t?.note_message && <p className="mt-3 text-xs rounded-lg bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2">{t.note_message}</p>}

      <div className="mt-4 flex flex-wrap items-center gap-2">
        {!clockedIn && !onBreak && <button onClick={() => act('/hr/attendance/clock-in')} disabled={busy} className="px-5 py-2.5 bg-green-600 text-white rounded-lg text-sm font-semibold disabled:opacity-50">📍 {done ? 'Clock in again' : 'Clock in'}</button>}
        {clockedIn && !onBreak && (<>
          <select value={breakType} onChange={(e) => setBreakType(e.target.value)} className="text-sm border border-gray-200 rounded-lg px-2 py-2">
            <option value="">Break type</option>{(me.break_types ?? []).map((b: any) => <option key={b.id} value={b.id}>{b.name}{b.max_minutes ? ` (${b.max_minutes}m)` : ''}</option>)}
          </select>
          <button onClick={() => act('/hr/attendance/break/start', { break_type_id: breakType ? +breakType : undefined })} disabled={busy} className="px-4 py-2.5 bg-amber-500 text-white rounded-lg text-sm font-semibold disabled:opacity-50">☕ Break in</button>
          <button onClick={() => act('/hr/attendance/clock-out')} disabled={busy} className="px-5 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold disabled:opacity-50">Clock out</button>
        </>)}
        {onBreak && <button onClick={() => act('/hr/attendance/break/end')} disabled={busy} className="px-5 py-2.5 bg-amber-600 text-white rounded-lg text-sm font-semibold disabled:opacity-50">▶️ Break out</button>}
        <button onClick={() => setLeaveOpen(true)} className="px-4 py-2.5 border border-gray-200 text-gray-700 rounded-lg text-sm font-medium">🏖️ Request leave</button>
        {(clockedIn || onBreak || (done && !clockedIn)) && (
          <input value={note} onChange={(e) => setNote(e.target.value)} placeholder={done && !clockedIn ? 'Why clocking in again? (required)' : 'Note (late / early / overtime reason)…'}
            className={`flex-1 min-w-[220px] text-sm rounded-lg px-3 py-2 border ${done && !clockedIn ? 'border-amber-300 bg-amber-50' : 'border-gray-200'}`} />
        )}
      </div>

      {types.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-xs text-gray-500">
          {paid.length > 0 && <span className="flex flex-wrap items-center gap-1.5"><Badge variant="green">Paid leave left</Badge>{paid.map(chip)}</span>}
          {other.length > 0 && <span className="flex flex-wrap items-center gap-1.5"><Badge variant="gray">Other leave</Badge>{other.map(chip)}</span>}
        </div>
      )}

      <LeaveRequest open={leaveOpen} onClose={() => setLeaveOpen(false)} onDone={load} />
    </div>
  )
}
