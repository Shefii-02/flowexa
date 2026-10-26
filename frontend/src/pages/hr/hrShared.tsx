// Shared helpers + widgets for the HR Administration pages (Attendance, Payroll, Sales,
// Incentives, Leave Requests, Staff Setup, Break Types, Leave Types, Office Details) — each is
// its own routed page (no shared tab strip), but they lean on the same small toolkit.
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

// ── Shared HR types — mirror the Laravel Hr models (hr_attendance, hr_break_sessions,
// hr_break_types). Previously duplicated locally inside AttendancePage.tsx; centralised
// here so any other page (e.g. a personal dashboard widget) can reuse them.
export type BreakType = { id: number; name: string; max_minutes: number | null; daily_limit: number | null; requires_gps: boolean }
export type BreakSession = { id: number; start_at: string; end_at: string | null; minutes: number | null; over_limit: boolean; break_type?: { name: string } | null }
export type Attendance = {
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

// ── GET /hr/dashboard/me — the sales-rep home-screen aggregate (attendance, sales
// target, streak, rank, leads/pipeline/follow-ups/tasks) shared by the mobile app and
// any future web "my dashboard" widget.
export type DashboardFollowUp = {
  id: number; title: string; type: 'call' | 'whatsapp' | 'email'
  contact_name: string | null; contact_phone: string | null
  due_at: string; bucket: 'missed' | 'late' | 'due_today' | 'upcoming'; days_late: number
}
export type DashboardTask = { id: number; title: string; status: 'open' | 'done' | 'cancelled'; priority: string; due_at: string | null }
export type DashboardMe = {
  greeting: { part: string; name: string; initials: string; avatar: string | null }
  attendance: {
    status: 'not_clocked_in' | 'clocked_in' | 'on_break' | 'clocked_out'
    clock_in_at: string | null; clock_out_at: string | null
    open_break: BreakSession | null; last_break_in: string | null; last_break_out: string | null
    worked_minutes: number; break_minutes: number
  }
  sales_target: { month: string; target: number; achieved: number; progress: number | null; currency: string }
  streak: { days: number }
  rank: { position: number | null; total_staff: number }
  leads: { new_today: number }
  pipeline: { new: number; contacted: number; won: number }
  follow_ups: { counts: { missed: number; late: number; due_today: number }; items: DashboardFollowUp[] }
  tasks: { done: number; total: number; items: DashboardTask[] }
  next_up: { title: string; subtitle: string; at: string } | null
}

export const hm = (m: number) => `${Math.floor(m / 60)}h ${m % 60}m`
export const err = (e: any) => e.response?.data?.message ?? (Object.values(e.response?.data?.errors ?? {})[0] as string[] | undefined)?.[0] ?? 'Failed'
export const inp = 'border border-gray-300 rounded-lg px-3 py-2 text-sm'
export const money = (v: number | null | undefined) => (v == null ? '—' : new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(Number(v)))
export const hhmm = (s: string | null) => (s ? new Date(s).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—')
export const forInput = (s: string | null) => (s ? new Date(s).toISOString().slice(0, 16) : '')

export function PageHeader({ icon, title, sub }: { icon: string; title: string; sub: string }) {
  return (
    <div>
      <h1 className="page-title">{icon} {title}</h1>
      <p className="page-sub">{sub}</p>
    </div>
  )
}

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto">
        <div className="p-4 border-b flex items-center justify-between">
          <h2 className="font-semibold text-gray-900">{title}</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <div className="p-4 space-y-3">{children}</div>
      </div>
    </div>
  )
}

// ── Multi-user picker — who gets notified for a given HR alert (late clock-in,
// late/early clock-out, break overrun, new leave request). Value/onChange are
// plain arrays of user ids, matching the hr_settings.*_notify_user_ids columns.
// Shows only the currently-selected people as removable chips (not the whole
// staff list) — search to find and add more.
export function StaffMultiSelect({ value, onChange }: { value: number[]; onChange: (ids: number[]) => void }) {
  const [staff, setStaff] = useState<any[]>([])
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  useEffect(() => { api.get('/staff').then(r => setStaff(r.data?.data ?? r.data ?? [])).catch(() => {}) }, [])

  const selected = staff.filter(u => value.includes(u.id))
  const q = query.trim().toLowerCase()
  const results = staff.filter(u => !value.includes(u.id) && (!q || String(u.name ?? '').toLowerCase().includes(q)))

  const add = (id: number) => { onChange([...value, id]); setQuery(''); setOpen(false) }
  const remove = (id: number) => onChange(value.filter(v => v !== id))

  return (
    <div className="space-y-1.5">
      <div className="flex flex-wrap gap-1.5 min-h-[1.5rem]">
        {selected.length === 0 && <span className="text-xs text-gray-400">No one selected</span>}
        {selected.map(u => (
          <span key={u.id} className="inline-flex items-center gap-1 bg-indigo-50 text-indigo-700 text-xs pl-2 pr-1 py-1 rounded-full">
            {u.name}
            <button type="button" onClick={() => remove(u.id)} className="text-indigo-400 hover:text-indigo-700 leading-none px-0.5">&times;</button>
          </span>
        ))}
      </div>
      <div className="relative">
        <input
          value={query}
          onChange={e => { setQuery(e.target.value); setOpen(true) }}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 150)}
          placeholder="Search staff to add…"
          className={`w-full ${inp}`}
        />
        {open && results.length > 0 && (
          <div className="absolute z-10 mt-1 w-full max-h-40 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg">
            {results.map(u => (
              <button key={u.id} type="button" onMouseDown={() => add(u.id)}
                className="block w-full text-left px-3 py-1.5 text-sm hover:bg-gray-50">
                {u.name}
              </button>
            ))}
          </div>
        )}
        {open && query && results.length === 0 && (
          <div className="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg px-3 py-2 text-xs text-gray-400">
            No match
          </div>
        )}
      </div>
    </div>
  )
}

// ── Break / Leave type CRUD — used by HrBreakTypesPage and HrLeaveTypesPage ──────
type TypeKind = 'break' | 'leave'

// Ready-made starting points, shown as one-click chips until added. Everything stays editable.
const BREAK_SAMPLES = [
  { name: 'Tea Break',    max_minutes: 15, daily_limit: 2, is_paid: true,  requires_gps: true },
  { name: 'Lunch Break',  max_minutes: 45, daily_limit: 1, is_paid: false, requires_gps: true },
  { name: 'Prayer Break', max_minutes: 15, daily_limit: 3, is_paid: true,  requires_gps: true },
  { name: 'Short Break',  max_minutes: 10, daily_limit: 4, is_paid: true,  requires_gps: false },
]
const LEAVE_SAMPLES = [
  { name: 'Casual Leave',  max_days_per_year: 12, is_paid: true,  requires_approval: true, color: 'info' },
  { name: 'Sick Leave',    max_days_per_year: 12, is_paid: true,  requires_approval: true, color: 'warning' },
  { name: 'Earned Leave',  max_days_per_year: 15, is_paid: true,  requires_approval: true, color: 'success' },
  { name: 'Unpaid Leave',  max_days_per_year: null, is_paid: false, requires_approval: true, color: 'danger' },
]

const intOrNull = (v: any) => (v === '' || v == null ? null : Number(v))

/** Returns a message per invalid field; empty object when the form is valid. */
function validateType(kind: TypeKind, f: any, rows: any[], ignoreId?: number): Record<string, string> {
  const e: Record<string, string> = {}
  const name = String(f.name ?? '').trim()
  if (!name) e.name = 'Name is required'
  else if (name.length > 60) e.name = 'Max 60 characters'
  else if (rows.some(r => r.id !== ignoreId && String(r.name).trim().toLowerCase() === name.toLowerCase())) e.name = 'Already exists'

  const whole = (v: any) => v !== '' && v != null && (!Number.isInteger(Number(v)))
  if (kind === 'break') {
    const m = intOrNull(f.max_minutes), d = intOrNull(f.daily_limit)
    if (whole(f.max_minutes) || (m !== null && (m < 1 || m > 480))) e.max_minutes = '1–480 minutes'
    if (whole(f.daily_limit) || (d !== null && (d < 1 || d > 20))) e.daily_limit = '1–20 times'
    if (!e.max_minutes && !e.daily_limit && m && d && m * d > 480) e.daily_limit = `${d} × ${m} min = ${m * d} min — more than a day allows`
  } else {
    const y = intOrNull(f.max_days_per_year)
    if (whole(f.max_days_per_year) || (y !== null && (y < 1 || y > 365))) e.max_days_per_year = '1–365 days'
  }
  return e
}

const payloadFor = (kind: TypeKind, f: any) => {
  const p: any = { ...f, name: String(f.name).trim() }
  if (kind === 'break') { p.max_minutes = intOrNull(f.max_minutes); p.daily_limit = intOrNull(f.daily_limit) }
  else p.max_days_per_year = intOrNull(f.max_days_per_year)
  return p
}

const describe = (kind: TypeKind, r: any) => kind === 'break'
  ? `${r.max_minutes ? r.max_minutes + ' min' : 'no time limit'}${r.daily_limit ? ` × ${r.daily_limit}/day` : ' · unlimited per day'}${r.max_minutes && r.daily_limit ? ` = ${r.max_minutes * r.daily_limit} min/day` : ''}${r.requires_gps ? ' · GPS' : ''}`
  : `${r.max_days_per_year ? r.max_days_per_year + ' days/yr' : 'no yearly cap'}${r.requires_approval ? ' · needs approval' : ' · auto-approved'}`

export function TypeCrud({ kind }: { kind: TypeKind }) {
  const base = kind === 'break' ? '/hr/break-types' : '/hr/leave-types'
  const blank = kind === 'break'
    ? { name: '', max_minutes: '', daily_limit: '', is_paid: true, requires_gps: true }
    : { name: '', is_paid: true, max_days_per_year: '', requires_approval: true, color: 'info' }
  const samples: any[] = kind === 'break' ? BREAK_SAMPLES : LEAVE_SAMPLES

  const [rows, setRows] = useState<any[]>([])
  const [form, setForm] = useState<any>(blank)
  const [touched, setTouched] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [edit, setEdit] = useState<any>({})

  const load = useCallback(() => { api.get(base).then(r => setRows(r.data?.data ?? [])).catch(() => {}) }, [base])
  useEffect(() => { load() }, [load])

  const setF = (k: string, v: any) => setForm((p: any) => ({ ...p, [k]: v }))
  const setE = (k: string, v: any) => setEdit((p: any) => ({ ...p, [k]: v }))

  const errors = validateType(kind, form, rows)
  const editErrors = editId ? validateType(kind, edit, rows, editId) : {}
  const show = (k: string) => (touched ? errors[k] : undefined)

  const add = async () => {
    setTouched(true)
    if (Object.keys(errors).length) return
    try { await api.post(base, payloadFor(kind, form)); setForm(blank); setTouched(false); load() } catch (e) { toast.error(err(e)) }
  }
  const addSample = async (smp: any) => {
    try { await api.post(base, smp); toast.success(`${smp.name} added`); load() } catch (e) { toast.error(err(e)) }
  }
  const saveEdit = async () => {
    if (!editId || Object.keys(editErrors).length) return
    try { await api.patch(`${base}/${editId}`, payloadFor(kind, edit)); setEditId(null); load() } catch (e) { toast.error(err(e)) }
  }
  const del = async (id: number) => { await api.delete(`${base}/${id}`); load() }

  const missing = samples.filter(smp => !rows.some(r => String(r.name).trim().toLowerCase() === smp.name.toLowerCase()))
  const dailyTotal = kind === 'break'
    ? rows.reduce((t, r) => t + (r.is_active === false ? 0 : (r.max_minutes && r.daily_limit ? r.max_minutes * r.daily_limit : 0)), 0)
    : 0

  const Field = ({ label, error, children }: { label: string; error?: string; children: any }) => (
    <label className="text-xs text-gray-500 flex flex-col gap-1">{label}{children}{error && <span className="text-red-500">{error}</span>}</label>
  )
  const cls = (bad?: string) => `${inp} ${bad ? 'border-red-400' : ''}`

  return (
    <div className="space-y-3">
      {missing.length > 0 && (
        <div className="bg-indigo-50 border border-indigo-100 rounded-xl p-3">
          <p className="text-xs text-indigo-700 mb-2">Quick add a common {kind === 'break' ? 'break' : 'leave'} — you can change the limits afterwards:</p>
          <div className="flex flex-wrap gap-2">
            {missing.map(smp => (
              <button key={smp.name} onClick={() => addSample(smp)} className="text-xs bg-white border border-indigo-200 text-indigo-700 rounded-full px-3 py-1 hover:bg-indigo-100">
                + {smp.name} <span className="text-indigo-400">({kind === 'break' ? `${smp.max_minutes}m × ${smp.daily_limit}/day` : smp.max_days_per_year ? `${smp.max_days_per_year}d/yr` : 'no cap'})</span>
              </button>
            ))}
          </div>
        </div>
      )}

      <div className="bg-white border border-gray-200 rounded-xl p-4">
        <p className="text-xs font-medium text-gray-600 mb-2">Add another {kind === 'break' ? 'break option' : 'leave type'}</p>
        <div className="flex flex-wrap items-start gap-3">
          <Field label="Name" error={show('name')}><input value={form.name} onChange={e => setF('name', e.target.value)} placeholder={kind === 'break' ? 'e.g. Snack Break' : 'e.g. Study Leave'} className={cls(show('name'))} /></Field>
          {kind === 'break' && <>
            <Field label="Max minutes each time" error={show('max_minutes')}><input type="number" min={1} max={480} value={form.max_minutes} onChange={e => setF('max_minutes', e.target.value)} placeholder="blank = no limit" className={`${cls(show('max_minutes'))} w-40`} /></Field>
            <Field label="Times per day" error={show('daily_limit')}><input type="number" min={1} max={20} value={form.daily_limit} onChange={e => setF('daily_limit', e.target.value)} placeholder="blank = unlimited" className={`${cls(show('daily_limit'))} w-40`} /></Field>
            <label className="text-xs flex items-center gap-1 mt-6"><input type="checkbox" checked={form.requires_gps} onChange={e => setF('requires_gps', e.target.checked)} /> GPS</label>
          </>}
          {kind === 'leave' && <>
            <Field label="Days per year" error={show('max_days_per_year')}><input type="number" min={1} max={365} value={form.max_days_per_year} onChange={e => setF('max_days_per_year', e.target.value)} placeholder="blank = no cap" className={`${cls(show('max_days_per_year'))} w-40`} /></Field>
            <Field label="Colour"><select value={form.color} onChange={e => setF('color', e.target.value)} className={inp}>{['info', 'primary', 'success', 'warning', 'danger'].map(c => <option key={c}>{c}</option>)}</select></Field>
            <label className="text-xs flex items-center gap-1 mt-6"><input type="checkbox" checked={form.requires_approval} onChange={e => setF('requires_approval', e.target.checked)} /> Approval</label>
          </>}
          <label className="text-xs flex items-center gap-1 mt-6"><input type="checkbox" checked={form.is_paid} onChange={e => setF('is_paid', e.target.checked)} /> Paid</label>
          <button onClick={add} className="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm mt-5">Add</button>
        </div>
      </div>

      {kind === 'break' && rows.length > 0 && (
        <p className={`text-xs ${dailyTotal > 120 ? 'text-amber-600' : 'text-gray-400'}`}>
          Total break allowance per day if every break is fully used: <b>{dailyTotal} min</b>{dailyTotal > 120 ? ' — that is more than 2 hours; check the limits.' : ''}
        </p>
      )}

      <div className="space-y-1.5">
        {rows.map(r => editId === r.id ? (
          <div key={r.id} className="bg-white border border-indigo-300 rounded-lg px-3 py-3 flex flex-wrap items-start gap-3 text-sm">
            <Field label="Name" error={editErrors.name}><input value={edit.name} onChange={e => setE('name', e.target.value)} className={cls(editErrors.name)} /></Field>
            {kind === 'break' ? <>
              <Field label="Max minutes" error={editErrors.max_minutes}><input type="number" value={edit.max_minutes ?? ''} onChange={e => setE('max_minutes', e.target.value)} className={`${cls(editErrors.max_minutes)} w-28`} /></Field>
              <Field label="Times / day" error={editErrors.daily_limit}><input type="number" value={edit.daily_limit ?? ''} onChange={e => setE('daily_limit', e.target.value)} className={`${cls(editErrors.daily_limit)} w-28`} /></Field>
              <label className="text-xs flex items-center gap-1 mt-6"><input type="checkbox" checked={!!edit.requires_gps} onChange={e => setE('requires_gps', e.target.checked)} /> GPS</label>
            </> : <>
              <Field label="Days / year" error={editErrors.max_days_per_year}><input type="number" value={edit.max_days_per_year ?? ''} onChange={e => setE('max_days_per_year', e.target.value)} className={`${cls(editErrors.max_days_per_year)} w-28`} /></Field>
              <label className="text-xs flex items-center gap-1 mt-6"><input type="checkbox" checked={!!edit.requires_approval} onChange={e => setE('requires_approval', e.target.checked)} /> Approval</label>
            </>}
            <label className="text-xs flex items-center gap-1 mt-6"><input type="checkbox" checked={!!edit.is_paid} onChange={e => setE('is_paid', e.target.checked)} /> Paid</label>
            <div className="flex gap-2 mt-5">
              <button onClick={saveEdit} disabled={Object.keys(editErrors).length > 0} className="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs disabled:opacity-40">Save</button>
              <button onClick={() => setEditId(null)} className="px-3 py-1.5 border border-gray-200 rounded-lg text-xs">Cancel</button>
            </div>
          </div>
        ) : (
          <div key={r.id} className="bg-white border border-gray-200 rounded-lg px-3 py-2 flex items-center justify-between text-sm">
            <span>{r.name}<span className="text-xs text-gray-400 ml-2">{describe(kind, r)}{r.is_paid ? ' · paid' : ' · unpaid'}</span></span>
            <span className="flex gap-3">
              <button onClick={() => { setEditId(r.id); setEdit({ ...r }) }} className="text-xs text-gray-400 hover:text-indigo-600">Edit</button>
              <button onClick={() => del(r.id)} className="text-xs text-gray-300 hover:text-red-500">Delete</button>
            </span>
          </div>
        ))}
        {rows.length === 0 && <p className="text-sm text-gray-400 py-4 text-center">Nothing added yet — use a quick-add above or create your own.</p>}
      </div>
    </div>
  )
}
