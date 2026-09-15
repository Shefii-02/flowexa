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
export function TypeCrud({ kind }: { kind: 'break' | 'leave' }) {
  const base = kind === 'break' ? '/hr/break-types' : '/hr/leave-types'
  const [rows, setRows] = useState<any[]>([])
  const [form, setForm] = useState<any>(kind === 'break'
    ? { name: '', max_minutes: '', daily_limit: '', is_paid: true, requires_gps: true }
    : { name: '', is_paid: true, max_days_per_year: '', requires_approval: true, color: 'info' })
  const load = useCallback(() => { api.get(base).then(r => setRows(r.data?.data ?? [])).catch(() => {}) }, [base])
  useEffect(() => { load() }, [load])
  const add = async () => {
    if (!form.name.trim()) return
    const p: any = { ...form }
    if (kind === 'break') { p.max_minutes = form.max_minutes ? Number(form.max_minutes) : null; p.daily_limit = form.daily_limit ? Number(form.daily_limit) : null }
    else p.max_days_per_year = form.max_days_per_year ? Number(form.max_days_per_year) : null
    try { await api.post(base, p); setForm({ ...form, name: '' }); load() } catch (e) { toast.error(err(e)) }
  }
  const del = async (id: number) => { await api.delete(`${base}/${id}`); load() }
  return (
    <div className="space-y-3">
      <div className="bg-white border border-gray-200 rounded-xl p-4 flex flex-wrap items-end gap-2">
        <input value={form.name} onChange={e => setForm((p: any) => ({ ...p, name: e.target.value }))} placeholder="Name" className={inp} />
        {kind === 'break' && <>
          <input type="number" value={form.max_minutes} onChange={e => setForm((p: any) => ({ ...p, max_minutes: e.target.value }))} placeholder="Max min" className={`${inp} w-24`} />
          <input type="number" value={form.daily_limit} onChange={e => setForm((p: any) => ({ ...p, daily_limit: e.target.value }))} placeholder="Per day" className={`${inp} w-24`} />
          <label className="text-xs flex items-center gap-1"><input type="checkbox" checked={form.requires_gps} onChange={e => setForm((p: any) => ({ ...p, requires_gps: e.target.checked }))} /> GPS</label>
        </>}
        {kind === 'leave' && <>
          <input type="number" value={form.max_days_per_year} onChange={e => setForm((p: any) => ({ ...p, max_days_per_year: e.target.value }))} placeholder="Days/yr" className={`${inp} w-24`} />
          <select value={form.color} onChange={e => setForm((p: any) => ({ ...p, color: e.target.value }))} className={inp}>{['info', 'primary', 'success', 'warning', 'danger'].map(c => <option key={c}>{c}</option>)}</select>
          <label className="text-xs flex items-center gap-1"><input type="checkbox" checked={form.requires_approval} onChange={e => setForm((p: any) => ({ ...p, requires_approval: e.target.checked }))} /> Approval</label>
        </>}
        <label className="text-xs flex items-center gap-1"><input type="checkbox" checked={form.is_paid} onChange={e => setForm((p: any) => ({ ...p, is_paid: e.target.checked }))} /> Paid</label>
        <button onClick={add} className="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm">Add</button>
      </div>
      <div className="space-y-1.5">
        {rows.map(r => (
          <div key={r.id} className="bg-white border border-gray-200 rounded-lg px-3 py-2 flex items-center justify-between text-sm">
            <span>{r.name}<span className="text-xs text-gray-400 ml-2">{kind === 'break' ? `${r.max_minutes ? r.max_minutes + 'm' : 'no limit'}${r.daily_limit ? ` · ${r.daily_limit}/day` : ''}` : `${r.max_days_per_year ? r.max_days_per_year + 'd/yr' : ''}${r.requires_approval ? ' · approval' : ' · auto'}`}{r.is_paid ? ' · paid' : ' · unpaid'}</span></span>
            <button onClick={() => del(r.id)} className="text-xs text-gray-300 hover:text-red-500">Delete</button>
          </div>
        ))}
      </div>
    </div>
  )
}
