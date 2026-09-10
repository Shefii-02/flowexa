import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { api } from '@/api/client'
import toast from 'react-hot-toast'

const TABS = ['Attendance', 'Payroll', 'Sales', 'Incentives', 'Leave', 'Break types', 'Leave types', 'Staff setup', 'Office'] as const
type Tab = typeof TABS[number]

// Each tab is also its own URL — /hr/admin/<slug> — so it can be linked from the
// sidebar, bookmarked, and navigated with browser history.
const SLUG: Record<Tab, string> = {
  'Attendance': 'attendance', 'Payroll': 'payroll', 'Sales': 'sales', 'Incentives': 'incentives', 'Leave': 'leave',
  'Break types': 'break-types', 'Leave types': 'leave-types', 'Staff setup': 'staff-setup', 'Office': 'office',
}
const TAB_BY_SLUG = Object.fromEntries(Object.entries(SLUG).map(([t, s]) => [s, t as Tab]))

const hm = (m: number) => `${Math.floor(m / 60)}h ${m % 60}m`
const err = (e: any) => e.response?.data?.message ?? (Object.values(e.response?.data?.errors ?? {})[0] as string[] | undefined)?.[0] ?? 'Failed'
const inp = 'border border-gray-300 rounded-lg px-3 py-2 text-sm'
const money = (v: number | null | undefined) => (v == null ? '—' : new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(Number(v)))
const hhmm = (s: string | null) => (s ? new Date(s).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—')
const forInput = (s: string | null) => (s ? new Date(s).toISOString().slice(0, 16) : '')

export default function HrAdminPage() {
  const navigate = useNavigate()
  const { tab: slug } = useParams()
  const tab: Tab = (slug && TAB_BY_SLUG[slug]) || 'Attendance'
  const setTab = (t: Tab) => navigate(`/hr/admin/${SLUG[t]}`)

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-5">
      <div>
        <h1 className="page-title">👥 HR Administration</h1>
        <p className="page-sub">Attendance, payroll, incentives, leave and HR configuration</p>
      </div>
      <div className="flex gap-1 border-b border-gray-200 overflow-x-auto">
        {TABS.map(t => (
          <button key={t} onClick={() => setTab(t)}
            className={`px-3 py-2 text-sm font-medium whitespace-nowrap border-b-2 -mb-px ${tab === t ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500'}`}>{t}</button>
        ))}
      </div>
      {tab === 'Attendance' && <AttendanceTab />}
      {tab === 'Payroll' && <PayrollTab />}
      {tab === 'Sales' && <SalesTab />}
      {tab === 'Incentives' && <IncentivesTab />}
      {tab === 'Leave' && <LeaveApprovalTab />}
      {tab === 'Break types' && <TypeCrud kind="break" />}
      {tab === 'Leave types' && <TypeCrud kind="leave" />}
      {tab === 'Staff setup' && <StaffSetupTab />}
      {tab === 'Office' && <OfficeTab />}
    </div>
  )
}

// ── Attendance: default today, filter, edit, add missed entry ────────────────

function AttendanceTab() {
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

// ── Payroll: generate → edit lines → release → report ───────────────────────

function PayrollTab() {
  const [runs, setRuns] = useState<any[]>([])
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7))
  const [open, setOpen] = useState<any | null>(null)
  const [editItem, setEditItem] = useState<any | null>(null)

  const load = useCallback(() => { api.get('/hr/payroll/runs').then(r => setRuns(r.data?.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { load() }, [load])

  const generate = async () => {
    try { const r = await api.post('/hr/payroll/runs', { period: month }); setOpen(r.data.data); load(); toast.success('Payroll generated') }
    catch (e) { toast.error(err(e)) }
  }
  const openRun = async (id: number) => { const r = await api.get(`/hr/payroll/runs/${id}`); setOpen(r.data.data) }
  const release = async (id: number) => {
    if (!confirm('Release this payroll? It can no longer be edited.')) return
    try { const r = await api.post(`/hr/payroll/runs/${id}/release`); setOpen(r.data.data); load(); toast.success('Released') }
    catch (e) { toast.error(err(e)) }
  }
  const saveItem = async () => {
    try {
      await api.patch(`/hr/payroll/items/${editItem.id}`, {
        base_pay: Number(editItem.base_pay), overtime_pay: Number(editItem.overtime_pay),
        incentive_pay: Number(editItem.incentive_pay), allowances: Number(editItem.allowances),
        deductions: Number(editItem.deductions), note: editItem.note || null,
      })
      setEditItem(null); openRun(open.id); toast.success('Line updated')
    } catch (e) { toast.error(err(e)) }
  }
  const exportCsv = async (id: number) => {
    const r = await api.get(`/hr/payroll/runs/${id}/report`)
    const rows = r.data.rows as any[]
    const head = Object.keys(rows[0] ?? { staff: '' })
    const csv = [head.join(','), ...rows.map(x => head.map(k => `"${x[k] ?? ''}"`).join(','))].join('\n')
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
    a.download = `payroll-${r.data.period}.csv`; a.click()
  }

  if (open) {
    const t = open.totals ?? {}
    return (
      <div className="space-y-3">
        <button onClick={() => setOpen(null)} className="text-sm text-indigo-600">← All runs</button>
        <div className="flex items-center justify-between flex-wrap gap-2">
          <h3 className="font-semibold text-gray-800">Payroll {open.period} · <span className={open.status === 'released' ? 'text-green-600' : 'text-amber-600'}>{open.status}</span></h3>
          <div className="flex gap-2">
            <button onClick={() => exportCsv(open.id)} className="text-sm border border-gray-200 rounded-lg px-3 py-1.5">Export CSV</button>
            {open.status !== 'released' && <>
              <button onClick={generate} className="text-sm border border-gray-200 rounded-lg px-3 py-1.5">Re-generate</button>
              <button onClick={() => release(open.id)} className="text-sm bg-green-600 text-white rounded-lg px-3 py-1.5">Release payroll</button>
            </>}
          </div>
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-center">
          {[['Staff', t.staff], ['Base', money(t.base_pay)], ['Incentive', money(t.incentive_pay)], ['Deductions', money(t.deductions)], ['Net payout', money(t.net_pay)]].map(([k, v]) => (
            <div key={k as string} className="bg-white border border-gray-200 rounded-xl px-3 py-2"><div className="text-lg font-bold">{v}</div><div className="text-[11px] text-gray-400">{k}</div></div>
          ))}
        </div>
        <div className="bg-white border border-gray-200 rounded-xl overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="text-left text-gray-400 border-b border-gray-100">
              <th className="p-2">Staff</th><th className="p-2">Present</th><th className="p-2">Leave</th><th className="p-2">Absent</th><th className="p-2">Late</th><th className="p-2">Hrs</th><th className="p-2">Base</th><th className="p-2">OT</th><th className="p-2">Incentive</th><th className="p-2">Allow.</th><th className="p-2">Deduct</th><th className="p-2">Net</th><th className="p-2"></th>
            </tr></thead>
            <tbody>
              {open.items?.map((i: any) => (
                <tr key={i.id} className="border-b border-gray-50">
                  <td className="p-2">{i.user?.name}</td>
                  <td className="p-2">{i.present_days}</td>
                  <td className="p-2">{Number(i.paid_leave_days) + Number(i.unpaid_leave_days)}</td>
                  <td className="p-2">{i.absent_days}</td>
                  <td className="p-2 text-amber-600">{i.late_days}</td>
                  <td className="p-2">{i.worked_hours}</td>
                  <td className="p-2">{money(i.base_pay)}</td>
                  <td className="p-2">{money(i.overtime_pay)}</td>
                  <td className="p-2">{money(i.incentive_pay)}</td>
                  <td className="p-2">{money(i.allowances)}</td>
                  <td className="p-2 text-red-500">{money(i.deductions)}</td>
                  <td className="p-2 font-semibold">{money(i.net_pay)}</td>
                  <td className="p-2">{open.status !== 'released' && <button onClick={() => setEditItem({ ...i })} className="text-xs text-indigo-600 hover:underline">Edit</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {editItem && (
          <Modal title={`Adjust — ${editItem.user?.name}`} onClose={() => setEditItem(null)}>
            {(['base_pay', 'overtime_pay', 'incentive_pay', 'allowances', 'deductions'] as const).map(k => (
              <label key={k} className="text-xs text-gray-500 capitalize">{k.replace('_', ' ')}
                <input type="number" value={editItem[k]} onChange={e => setEditItem((p: any) => ({ ...p, [k]: e.target.value }))} className={`block mt-1 w-full ${inp}`} />
              </label>
            ))}
            <input value={editItem.note ?? ''} onChange={e => setEditItem((p: any) => ({ ...p, note: e.target.value }))} placeholder="Note (why adjusted)" className={`w-full ${inp}`} />
            <button onClick={saveItem} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Save</button>
          </Modal>
        )}
      </div>
    )
  }

  return (
    <div className="space-y-3">
      <div className="flex items-end gap-2">
        <label className="text-xs text-gray-500">Month<input type="month" value={month} onChange={e => setMonth(e.target.value)} className={`block mt-1 ${inp}`} /></label>
        <button onClick={generate} className="text-sm bg-indigo-600 text-white rounded-lg px-3 py-2">Generate payroll</button>
      </div>
      <div className="bg-white border border-gray-200 rounded-xl divide-y divide-gray-50">
        {runs.map(r => (
          <div key={r.id} className="flex items-center justify-between p-3">
            <div>
              <span className="font-medium">{r.period}</span>
              <span className={`ml-2 text-xs px-2 py-0.5 rounded-full ${r.status === 'released' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>{r.status}</span>
              <span className="ml-2 text-xs text-gray-400">{r.items_count} staff · net {money(r.totals?.net_pay)}</span>
            </div>
            <button onClick={() => openRun(r.id)} className="text-sm text-indigo-600 hover:underline">Open</button>
          </div>
        ))}
        {runs.length === 0 && <p className="p-4 text-center text-gray-400 text-sm">No payroll runs yet.</p>}
      </div>
    </div>
  )
}

// ── Sales: record a catalog-item sale → auto-posts the staff incentive ─────

function SalesTab() {
  const month = new Date().toISOString().slice(0, 7)
  const [rows, setRows] = useState<any[]>([])
  const [summary, setSummary] = useState<any[]>([])
  const [staff, setStaff] = useState<any[]>([])
  const [listings, setListings] = useState<any[]>([])
  const [showForm, setShowForm] = useState(false)
  const [f, setF] = useState({ listing_id: '', staff_id: '', amount: '', sold_at: new Date().toISOString().slice(0, 10), item_label: '', note: '' })
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    api.get('/hr/sales', { params: { month } }).then(r => setRows(r.data?.data ?? [])).catch(() => {})
    api.get('/hr/sales/summary', { params: { month } }).then(r => setSummary(r.data?.data ?? [])).catch(() => {})
  }, [month])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    api.get('/staff').then(r => setStaff(r.data?.data ?? r.data ?? [])).catch(() => {})
    api.get('/listings', { params: { status: 'active' } }).then(r => setListings(r.data?.data ?? r.data ?? [])).catch(() => {})
  }, [])

  const submit = async () => {
    if (!f.staff_id || !f.amount) { toast.error('Pick a staff member and enter the amount'); return }
    if (!f.listing_id && !f.item_label.trim()) { toast.error('Pick a catalog item or type an item name'); return }
    setSaving(true)
    try {
      await api.post('/hr/sales', {
        listing_id: f.listing_id ? Number(f.listing_id) : null,
        staff_id: Number(f.staff_id),
        amount: Number(f.amount),
        sold_at: f.sold_at,
        item_label: f.item_label.trim() || undefined,
        note: f.note.trim() || undefined,
      })
      toast.success('Sale recorded — incentive posted.')
      setShowForm(false)
      setF({ listing_id: '', staff_id: '', amount: '', sold_at: new Date().toISOString().slice(0, 10), item_label: '', note: '' })
      load()
    } catch (e) { toast.error(err(e)) }
    finally { setSaving(false) }
  }

  const voidSale = async (id: number) => {
    if (!confirm('Void this sale? Its unpaid incentive is removed too.')) return
    try { await api.delete(`/hr/sales/${id}`); load() } catch (e) { toast.error(err(e)) }
  }

  return (
    <div className="space-y-4">
      <div className="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-2.5 text-xs text-indigo-700">
        Recording a sale credits the staff member automatically — the incentive uses the item's own % if set, otherwise the matching category rule. A lead reaching <b>enrolled</b> with an item + amount also records a sale on its own.
      </div>

      {/* Per-staff summary vs monthly target */}
      {summary.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          {summary.map(s => (
            <div key={s.staff_id} className="bg-white border border-gray-200 rounded-xl p-3">
              <div className="text-sm font-medium">{s.staff_name}</div>
              <div className="text-xs text-gray-500 mt-1">{s.sales_count} sales · incentive {money(s.incentive_total)}</div>
              <div className="text-lg font-bold mt-1">{money(s.sold_total)}{s.target > 0 && <span className="text-xs font-normal text-gray-400"> / {money(s.target)}</span>}</div>
              {s.progress != null && (
                <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden mt-1.5">
                  <div className={`h-full rounded-full ${s.progress >= 100 ? 'bg-green-500' : 'bg-indigo-400'}`} style={{ width: `${Math.min(s.progress, 100)}%` }} />
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">Sales this month</h3>
        <button onClick={() => setShowForm(v => !v)} className="text-sm bg-indigo-600 text-white rounded-lg px-3 py-2">{showForm ? 'Cancel' : '+ Record sale'}</button>
      </div>

      {showForm && (
        <div className="bg-white border border-gray-200 rounded-xl p-4 grid grid-cols-2 gap-3">
          <select value={f.listing_id} onChange={e => setF(p => ({ ...p, listing_id: e.target.value }))} className={inp}>
            <option value="">— catalog item —</option>
            {listings.map((l: any) => <option key={l.id} value={l.id}>{l.title}{l.price ? ` (${money(l.price)})` : ''}</option>)}
          </select>
          <input value={f.item_label} onChange={e => setF(p => ({ ...p, item_label: e.target.value }))} placeholder="…or type an item name" className={inp} />
          <select value={f.staff_id} onChange={e => setF(p => ({ ...p, staff_id: e.target.value }))} className={inp}>
            <option value="">— staff member —</option>
            {staff.map((s: any) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          <input type="number" value={f.amount} onChange={e => setF(p => ({ ...p, amount: e.target.value }))} placeholder="Sale amount" className={inp} />
          <input type="date" value={f.sold_at} onChange={e => setF(p => ({ ...p, sold_at: e.target.value }))} className={inp} />
          <input value={f.note} onChange={e => setF(p => ({ ...p, note: e.target.value }))} placeholder="Note (optional)" className={inp} />
          <div className="col-span-2 flex justify-end">
            <button onClick={submit} disabled={saving} className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">{saving ? 'Saving…' : 'Record sale'}</button>
          </div>
        </div>
      )}

      <div className="bg-white border border-gray-200 rounded-xl overflow-x-auto">
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-400 border-b border-gray-100">
            <th className="p-2">Item</th><th className="p-2">Staff</th><th className="p-2">Amount</th><th className="p-2">Incentive</th><th className="p-2">Date</th><th className="p-2"></th>
          </tr></thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} className="border-b border-gray-50">
                <td className="p-2">{r.item_label || r.listing?.title || '—'}{r.lead_id && <span className="text-[10px] text-gray-400 ml-1">· from lead</span>}</td>
                <td className="p-2">{r.staff?.name}</td>
                <td className="p-2">{money(r.amount)}</td>
                <td className="p-2">{money(r.incentive?.amount)} <span className="text-[10px] text-gray-400">{r.incentive?.status}</span></td>
                <td className="p-2 text-gray-500">{r.sold_at?.slice(0, 10)}</td>
                <td className="p-2"><button onClick={() => voidSale(r.id)} className="text-xs text-red-500 hover:underline">Void</button></td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-gray-400 text-sm">No sales recorded this month.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ── Incentives: rules + ledger ─────────────────────────────────────────────

function IncentivesTab() {
  const [rules, setRules] = useState<any[]>([])
  const [ledger, setLedger] = useState<any[]>([])
  const [staff, setStaff] = useState<any[]>([])
  const [rf, setRf] = useState({ name: '', category: 'service', kind: 'percentage', percent: '', fixed_amount: '' })
  const [showManual, setShowManual] = useState(false)

  const load = useCallback(() => {
    api.get('/hr/incentive-rules').then(r => setRules(r.data?.data ?? [])).catch(() => {})
    api.get('/hr/incentives').then(r => setLedger(r.data?.data ?? [])).catch(() => {})
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { api.get('/hr/staff-profiles').then(r => setStaff((r.data?.data ?? []).map((x: any) => x.user))).catch(() => {}) }, [])

  const addRule = async () => {
    if (!rf.name.trim()) return
    try {
      await api.post('/hr/incentive-rules', {
        name: rf.name, category: rf.category, kind: rf.kind,
        percent: rf.kind === 'percentage' ? Number(rf.percent || 0) : 0,
        fixed_amount: rf.kind === 'fixed' ? Number(rf.fixed_amount || 0) : 0,
      })
      setRf({ ...rf, name: '', percent: '', fixed_amount: '' }); load()
    } catch (e) { toast.error(err(e)) }
  }
  const delRule = async (id: number) => { await api.delete(`/hr/incentive-rules/${id}`); load() }
  const approve = async (id: number) => { await api.patch(`/hr/incentives/${id}`, { status: 'approved' }); load() }
  const delIncentive = async (id: number) => { await api.delete(`/hr/incentives/${id}`); load() }

  return (
    <div className="space-y-5">
      <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
        <h3 className="text-sm font-semibold text-gray-700">Incentive rules — per service / course / product</h3>
        <div className="flex flex-wrap items-end gap-2">
          <input value={rf.name} onChange={e => setRf(p => ({ ...p, name: e.target.value }))} placeholder="Service / course / product" className={inp} />
          <select value={rf.category} onChange={e => setRf(p => ({ ...p, category: e.target.value }))} className={inp}>{['service', 'course', 'product', 'other'].map(c => <option key={c}>{c}</option>)}</select>
          <select value={rf.kind} onChange={e => setRf(p => ({ ...p, kind: e.target.value }))} className={inp}><option value="percentage">% of value</option><option value="fixed">Fixed</option></select>
          {rf.kind === 'percentage'
            ? <input type="number" value={rf.percent} onChange={e => setRf(p => ({ ...p, percent: e.target.value }))} placeholder="%" className={`${inp} w-20`} />
            : <input type="number" value={rf.fixed_amount} onChange={e => setRf(p => ({ ...p, fixed_amount: e.target.value }))} placeholder="₹" className={`${inp} w-24`} />}
          <button onClick={addRule} className="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm">Add</button>
        </div>
        <div className="flex flex-wrap gap-2">
          {rules.map(r => (
            <span key={r.id} className="inline-flex items-center gap-1.5 text-xs bg-gray-50 border border-gray-200 rounded-full pl-3 pr-1.5 py-1">
              {r.name} · {r.kind === 'fixed' ? `₹${r.fixed_amount}` : `${r.percent}%`} <span className="text-gray-400">({r.category})</span>
              <button onClick={() => delRule(r.id)} className="text-gray-400 hover:text-red-500">×</button>
            </span>
          ))}
        </div>
      </div>

      <div className="bg-white border border-gray-200 rounded-xl p-4 space-y-3">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold text-gray-700">Incentive ledger</h3>
          <button onClick={() => setShowManual(v => !v)} className="text-sm text-indigo-600 hover:underline">+ Manual entry</button>
        </div>
        {showManual && <ManualIncentive rules={rules} staff={staff} onDone={() => { setShowManual(false); load() }} />}
        <table className="w-full text-sm">
          <thead><tr className="text-left text-gray-400 border-b border-gray-100"><th className="py-1.5">Staff</th><th className="py-1.5">For</th><th className="py-1.5">Base</th><th className="py-1.5">Incentive</th><th className="py-1.5">Earned</th><th className="py-1.5">Status</th><th className="py-1.5"></th></tr></thead>
          <tbody>
            {ledger.map(i => (
              <tr key={i.id} className="border-b border-gray-50">
                <td className="py-1.5">{i.user?.name}</td>
                <td className="py-1.5 text-gray-500">{i.title}</td>
                <td className="py-1.5">{money(i.base_amount)}</td>
                <td className="py-1.5 font-medium">{money(i.amount)}</td>
                <td className="py-1.5 text-gray-400">{String(i.earned_on).slice(0, 10)}</td>
                <td className="py-1.5"><span className={`text-xs px-2 py-0.5 rounded-full ${i.status === 'paid' ? 'bg-green-100 text-green-700' : i.status === 'approved' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700'}`}>{i.status}</span></td>
                <td className="py-1.5 text-right space-x-2">
                  {i.status === 'pending' && <button onClick={() => approve(i.id)} className="text-xs text-blue-600 hover:underline">Approve</button>}
                  {i.status !== 'paid' && <button onClick={() => delIncentive(i.id)} className="text-xs text-red-500 hover:underline">Delete</button>}
                </td>
              </tr>
            ))}
            {ledger.length === 0 && <tr><td colSpan={7} className="py-4 text-center text-gray-400">No incentives yet. They're auto-created when a deal with an incentive rule is won.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function ManualIncentive({ rules, staff, onDone }: { rules: any[]; staff: any[]; onDone: () => void }) {
  const [f, setF] = useState({ user_id: '', incentive_rule_id: '', title: '', base_amount: '', amount: '', earned_on: new Date().toISOString().slice(0, 10) })
  const submit = async () => {
    if (!f.user_id || !f.title) { toast.error('Staff and title required'); return }
    try {
      await api.post('/hr/incentives', {
        user_id: Number(f.user_id),
        incentive_rule_id: f.incentive_rule_id ? Number(f.incentive_rule_id) : undefined,
        title: f.title, base_amount: Number(f.base_amount || 0),
        amount: f.amount ? Number(f.amount) : undefined,
        earned_on: f.earned_on,
      })
      onDone()
    } catch (e) { toast.error(err(e)) }
  }
  return (
    <div className="border border-gray-200 rounded-lg p-3 grid grid-cols-2 gap-2">
      <select value={f.user_id} onChange={e => setF(p => ({ ...p, user_id: e.target.value }))} className={inp}><option value="">Staff *</option>{staff.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
      <select value={f.incentive_rule_id} onChange={e => setF(p => ({ ...p, incentive_rule_id: e.target.value }))} className={inp}><option value="">Rule (optional)</option>{rules.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}</select>
      <input value={f.title} onChange={e => setF(p => ({ ...p, title: e.target.value }))} placeholder="Title / what was sold" className={inp} />
      <input type="number" value={f.base_amount} onChange={e => setF(p => ({ ...p, base_amount: e.target.value }))} placeholder="Sale value" className={inp} />
      <input type="number" value={f.amount} onChange={e => setF(p => ({ ...p, amount: e.target.value }))} placeholder="Incentive (blank = from rule)" className={inp} />
      <input type="date" value={f.earned_on} onChange={e => setF(p => ({ ...p, earned_on: e.target.value }))} className={inp} />
      <div className="col-span-2"><button onClick={submit} className="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm">Add incentive</button></div>
    </div>
  )
}

// ── Leave approvals ────────────────────────────────────────────────────────

function LeaveApprovalTab() {
  const [rows, setRows] = useState<any[]>([])
  const [staff, setStaff] = useState<any[]>([])
  const [f, setF] = useState({ status: '', user_id: '', date: '' })
  const load = useCallback(() => {
    api.get('/hr/leave', { params: {
      scope: 'team',
      status: f.status || undefined,
      user_id: f.user_id || undefined,
      date: f.date || undefined,
    } }).then(r => setRows(r.data?.data ?? [])).catch(() => {})
  }, [f])
  useEffect(() => { load() }, [load])
  useEffect(() => { api.get('/staff').then(r => setStaff(r.data?.data ?? r.data ?? [])).catch(() => {}) }, [])
  const review = async (id: number, decision: 'approved' | 'rejected') => {
    const note = decision === 'rejected' ? (prompt('Reason?') ?? '') : ''
    try { await api.post(`/hr/leave/${id}/review`, { decision, note }); load() } catch (e) { toast.error(err(e)) }
  }
  return (
    <div className="space-y-2">
      <div className="bg-white border border-gray-200 rounded-xl p-3 flex flex-wrap items-center gap-2">
        <input type="date" value={f.date} onChange={e => setF(p => ({ ...p, date: e.target.value }))} className={`${inp} text-xs`} />
        <select value={f.user_id} onChange={e => setF(p => ({ ...p, user_id: e.target.value }))} className={`${inp} text-xs`}>
          <option value="">All staff</option>
          {staff.map((s: any) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <select value={f.status} onChange={e => setF(p => ({ ...p, status: e.target.value }))} className={`${inp} text-xs`}>
          <option value="">Any status</option>
          {['pending', 'approved', 'rejected', 'cancelled'].map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        {(f.date || f.user_id || f.status) && (
          <button onClick={() => setF({ status: '', user_id: '', date: '' })} className="text-xs text-gray-500 hover:underline">Clear</button>
        )}
      </div>
      {rows.map(r => (
        <div key={r.id} className="bg-white border border-gray-200 rounded-xl p-3 flex items-center justify-between gap-3">
          <div>
            <div className="text-sm font-medium">{r.user?.name} · {r.leave_type?.name} · {r.days}d</div>
            <div className="text-xs text-gray-400">{r.start_date} → {r.end_date}{r.reason ? ` · ${r.reason}` : ''}</div>
          </div>
          {r.status === 'pending'
            ? <div className="flex gap-1.5"><button onClick={() => review(r.id, 'approved')} className="text-xs px-2.5 py-1 bg-green-600 text-white rounded">Approve</button><button onClick={() => review(r.id, 'rejected')} className="text-xs px-2.5 py-1 border border-red-200 text-red-600 rounded">Reject</button></div>
            : <span className={`text-xs px-2 py-0.5 rounded-full ${r.status === 'approved' ? 'bg-green-100 text-green-700' : r.status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-400'}`}>{r.status}</span>}
        </div>
      ))}
      {rows.length === 0 && <p className="text-sm text-gray-400 text-center py-6">No team leave requests.</p>}
    </div>
  )
}

// ── Break / Leave type CRUD ────────────────────────────────────────────────

function TypeCrud({ kind }: { kind: 'break' | 'leave' }) {
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

// ── Staff setup (attendance profile + photo) ───────────────────────────────

function StaffSetupTab() {
  const [rows, setRows] = useState<any[]>([])
  const load = useCallback(() => { api.get('/hr/staff-profiles').then(r => setRows(r.data?.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { load() }, [load])
  const save = async (userId: number, patch: Record<string, unknown>) => {
    try { await api.put(`/hr/staff-profiles/${userId}`, patch); load() } catch (e) { toast.error(err(e)) }
  }
  return (
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
  )
}

// ── Office: per-day hours + geofence + payroll rules ───────────────────────

function OfficeTab() {
  const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
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
  if (!s) return <div className="text-gray-400">Loading…</div>
  return (
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
        <h3 className="text-sm font-semibold text-gray-700">Geofence & timing</h3>
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
  )
}

// ── shared modal ──────────────────────────────────────────────────────────

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
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
