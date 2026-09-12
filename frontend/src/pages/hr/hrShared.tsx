// Shared helpers + widgets for the HR Administration pages (Attendance, Payroll, Sales,
// Incentives, Leave Requests, Staff Setup, Break Types, Leave Types, Office Details) — each is
// its own routed page (no shared tab strip), but they lean on the same small toolkit.
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

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
