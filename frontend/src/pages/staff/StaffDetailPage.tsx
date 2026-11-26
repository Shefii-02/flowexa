// src/pages/staff/StaffDetailPage.tsx
// One staff member, everything in one place: profile & personal details, attendance, leaves, payroll, targets and tasks.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { staffExtrasApi } from '@/api'
import { Badge, Spinner, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import { useDecodedParams, pid } from '@/lib/publicId'
import toast from 'react-hot-toast'

const TABS = ['Overview', 'Personal', 'Attendance', 'Leaves', 'Payroll', 'Targets & tasks'] as const
type Tab = typeof TABS[number]

const money = (n: any) => (n === null || n === undefined || n === '' ? '—' : `₹${Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`)
const day = (d?: string | null) => (d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const time = (d?: string | null) => (d ? new Date(d).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : '—')
const hm = (min?: number) => (min ? `${Math.floor(min / 60)}h ${min % 60}m` : '—')

function Field({ label, value }: { label: string; value?: any }) {
  return (
    <div>
      <p className="text-[11px] uppercase tracking-wide text-gray-400">{label}</p>
      <p className="text-sm text-gray-800 break-words">{value || '—'}</p>
    </div>
  )
}
function Stat({ label, value, sub }: { label: string; value: any; sub?: string }) {
  return (
    <div className="card p-4">
      <p className="text-xs text-gray-500">{label}</p>
      <p className="text-2xl font-semibold text-gray-900 mt-1">{value}</p>
      {sub && <p className="text-[11px] text-gray-400 mt-0.5">{sub}</p>}
    </div>
  )
}
const statusVariant = (s: string): any => ({ present: 'green', approved: 'green', done: 'green', released: 'green', absent: 'red', rejected: 'red', on_leave: 'blue', pending: 'yellow', half_day: 'yellow', open: 'yellow' } as any)[s] ?? 'gray'

export default function StaffDetailPage() {
  const { id } = useDecodedParams<{ id: string }>()
  const [tab, setTab] = useState<Tab>('Overview')
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7))
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!id) return
    setLoading(true)
    staffExtrasApi.overview(+id, month).then((r) => setData(r.data)).catch((e) => toast.error(getError(e))).finally(() => setLoading(false))
  }, [id, month])

  if (loading && !data) return <div className="p-10 flex justify-center"><Spinner size="lg" /></div>
  if (!data) return <EmptyState icon="👤" title="Staff member not found" desc="You may not have access to see this person." />

  const p = data.profile, pe = data.personal, a = data.attendance, t = data.tasks, s = data.sales
  const phones = [p.phone && { label: 'Primary', number: p.phone }, ...(pe.alternate_phones ?? []), ...(pe.other_numbers ?? [])].filter(Boolean) as { label: string; number: string }[]

  return (
    <div className="space-y-5">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-4">
          <div className="w-14 h-14 rounded-full bg-brand-100 flex items-center justify-center text-xl font-semibold text-brand-700 overflow-hidden">
            {p.avatar ? <img src={p.avatar} alt="" className="w-full h-full object-cover" /> : p.name?.charAt(0).toUpperCase()}
          </div>
          <div>
            <h1 className="page-title">{p.name}</h1>
            <p className="page-sub">{[p.designation, p.role, p.department].filter(Boolean).join(' · ') || p.email}</p>
            <div className="mt-1 flex gap-2"><Badge variant={p.is_active ? 'green' : 'gray'}>{p.is_active ? 'Active' : 'Inactive'}</Badge>{p.joined_on && <Badge variant="blue">Joined {day(p.joined_on)}</Badge>}</div>
          </div>
        </div>
        <Link to="/staff/index" className="text-sm text-brand-600 hover:underline">← All staff</Link>
      </div>

      <div className="flex flex-wrap items-center gap-1 border-b border-gray-200">
        {TABS.map((x) => (
          <button key={x} onClick={() => setTab(x)} className={`px-4 py-2 text-sm -mb-px border-b-2 ${tab === x ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>{x}</button>
        ))}
        {(tab === 'Attendance' || tab === 'Overview') && (
          <input type="month" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} className="input ml-auto w-40" />
        )}
      </div>

      {tab === 'Overview' && (
        <div className="space-y-5">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Stat label="Present days" value={a.summary.present} sub={`${a.summary.late_days} late · ${a.summary.absent} absent`} />
            <Stat label="Worked hours" value={a.summary.worked_hours} sub={`${a.summary.overtime_hours} h overtime`} />
            <Stat label="Tasks completed" value={t.stats.done_this_month} sub={`${t.stats.open} open · ${t.stats.overdue} overdue`} />
            <Stat label="Leads" value={data.leads.assigned} sub={`${data.leads.enrolled} enrolled`} />
          </div>
          <div className="card p-5 grid grid-cols-2 md:grid-cols-4 gap-4">
            <Field label="Email" value={p.email} />
            <Field label="Last login" value={p.last_login_at ? `${day(p.last_login_at)} ${time(p.last_login_at)}` : null} />
            <Field label="Attendance type" value={p.work?.attendance_type} />
            <Field label="Work mode" value={p.work?.work_mode} />
            <Field label="Shift" value={p.work?.shift} />
            <Field label="Duty hours" value={p.work?.duty_start ? `${String(p.work.duty_start).slice(0, 5)} – ${String(p.work.duty_end ?? '').slice(0, 5)}` : null} />
            <Field label="Weekly off" value={(p.work?.weekly_off ?? []).join(', ')} />
            <Field label="Monthly salary" value={p.work ? money(p.work.monthly_salary) : null} />
            <Field label="Monthly target" value={p.work ? money(p.work.monthly_target) : null} />
          </div>
        </div>
      )}

      {tab === 'Personal' && (
        <div className="space-y-5">
          <div className="card p-5">
            <p className="text-sm font-semibold mb-3">Phone numbers</p>
            {phones.length === 0 ? <p className="text-xs text-gray-400">No numbers added.</p> : (
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">{phones.map((n, i) => <Field key={i} label={n.label || 'Number'} value={n.number} />)}</div>
            )}
          </div>
          <div className="card p-5">
            <p className="text-sm font-semibold mb-3">Personal details</p>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <Field label="Date of birth" value={pe.dob ? day(pe.dob) : null} /><Field label="Gender" value={pe.gender} />
              <Field label="Blood group" value={pe.blood_group} /><Field label="Marital status" value={pe.marital_status} />
              <div className="col-span-2"><Field label="Address" value={pe.address} /></div>
              <Field label="City" value={pe.city} /><Field label="State / Pincode" value={[pe.state, pe.pincode].filter(Boolean).join(' · ')} />
            </div>
          </div>
          <div className="card p-5">
            <p className="text-sm font-semibold mb-3">Parents &amp; emergency contact</p>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <Field label="Father" value={pe.father_name} /><Field label="Father's phone" value={pe.father_phone} />
              <Field label="Mother" value={pe.mother_name} /><Field label="Mother's phone" value={pe.mother_phone} />
              <Field label="Emergency contact" value={pe.emergency_name} /><Field label="Emergency phone" value={pe.emergency_phone} />
              <Field label="Relation" value={pe.emergency_relation} /><Field label="Notes" value={pe.notes} />
            </div>
          </div>
          <p className="text-xs text-gray-400">To change these, use <b>Edit</b> on the staff list.{' '}<Link className="text-brand-600 underline" to={`/staff/access?staff=${pid(p.id)}`}>Access settings ›</Link></p>
        </div>
      )}

      {tab === 'Attendance' && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
            <Stat label="Present" value={a.summary.present} /><Stat label="Absent" value={a.summary.absent} />
            <Stat label="On leave" value={a.summary.on_leave} /><Stat label="Late" value={a.summary.late_days} sub={hm(a.summary.late_minutes)} />
            <Stat label="Worked" value={`${a.summary.worked_hours} h`} />
          </div>
          <div className="card table-wrapper">
            <table className="table"><thead><tr><th>Date</th><th>Status</th><th>In</th><th>Out</th><th>Late</th><th>Worked</th></tr></thead>
              <tbody>
                {a.days.length === 0 ? <tr><td colSpan={6} className="text-center text-gray-400 py-6">No attendance this month.</td></tr> : a.days.map((d: any) => (
                  <tr key={d.date}><td>{day(d.date)}</td><td><Badge variant={statusVariant(d.status)}>{String(d.status).replace('_', ' ')}</Badge></td>
                    <td>{time(d.in)}</td><td>{time(d.out)}</td><td>{hm(d.late_minutes)}</td><td>{hm(d.worked_minutes)}</td></tr>
                ))}
              </tbody></table>
          </div>
        </div>
      )}

      {tab === 'Leaves' && (
        <div className="card table-wrapper">
          <table className="table"><thead><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th><th>Reason</th></tr></thead>
            <tbody>
              {data.leaves.length === 0 ? <tr><td colSpan={6} className="text-center text-gray-400 py-6">No leave requests.</td></tr> : data.leaves.map((l: any) => (
                <tr key={l.id}><td>{l.type || '—'}{l.half_day ? ' (half day)' : ''}</td><td>{day(l.start_date)}</td><td>{day(l.end_date)}</td><td>{l.days}</td>
                  <td><Badge variant={statusVariant(l.status)}>{l.status}</Badge></td><td className="text-gray-500 max-w-xs truncate">{l.reason || '—'}</td></tr>
              ))}
            </tbody></table>
        </div>
      )}

      {tab === 'Payroll' && (
        <div className="card table-wrapper">
          <table className="table"><thead><tr><th>Month</th><th>Present</th><th>Paid leave</th><th>Unpaid</th><th>Absent</th><th>Gross</th><th>Deductions</th><th>Net pay</th><th>Status</th></tr></thead>
            <tbody>
              {data.payroll.length === 0 ? <tr><td colSpan={9} className="text-center text-gray-400 py-6">No payroll yet.</td></tr> : data.payroll.map((r: any) => (
                <tr key={r.period}><td>{r.period}</td><td>{r.present_days}</td><td>{r.paid_leave_days}</td><td>{r.unpaid_leave_days}</td><td>{r.absent_days}</td>
                  <td>{money(r.gross_pay)}</td><td>{money(r.deductions)}</td><td className="font-medium">{money(r.net_pay)}</td><td><Badge variant={statusVariant(r.run_status)}>{r.run_status}</Badge></td></tr>
              ))}
            </tbody></table>
        </div>
      )}

      {tab === 'Targets & tasks' && (
        <div className="space-y-5">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Stat label="Tasks done (this month)" value={t.stats.done_this_month} sub={`${t.stats.done_total} all time`} />
            <Stat label="Open tasks" value={t.stats.open} sub={`${t.stats.overdue} overdue`} />
            <Stat label="Calls (month)" value={s?.month?.calls ?? '—'} sub={s ? `target ${s.targets?.monthly_calls ?? 0}${s.month.calls_pct != null ? ` · ${s.month.calls_pct}%` : ''}` : 'Not a sales role'} />
            <Stat label="Conversions (month)" value={s?.month?.converted ?? '—'} sub={s ? `target ${s.targets?.monthly_conversions ?? 0}${s.month.conversions_pct != null ? ` · ${s.month.conversions_pct}%` : ''}` : undefined} />
          </div>
          {s && (
            <div className="card p-5 grid grid-cols-2 md:grid-cols-4 gap-4">
              <Field label="New-lead calls today" value={`${s.day.new_calls} / ${s.targets.daily_new_lead_calls}`} />
              <Field label="Follow-up calls today" value={`${s.day.follow_up_calls} / ${s.targets.daily_follow_up_calls}`} />
              <Field label="Cost per conversion" value={s.month.cost_per_conversion != null ? money(s.month.cost_per_conversion) : null} />
              <Field label="Pipeline" value={`${s.pipeline.new} new · ${s.pipeline.contacted} contacted · ${s.pipeline.follow_up} follow-up`} />
            </div>
          )}
          <div className="card table-wrapper">
            <table className="table"><thead><tr><th>Task</th><th>Type</th><th>Priority</th><th>Due</th><th>Status</th></tr></thead>
              <tbody>
                {t.recent.length === 0 ? <tr><td colSpan={5} className="text-center text-gray-400 py-6">No tasks assigned.</td></tr> : t.recent.map((x: any) => (
                  <tr key={x.id}><td>{x.title}</td><td>{x.type}</td><td>{x.priority}</td><td>{x.due_at ? `${day(x.due_at)} ${time(x.due_at)}` : '—'}</td>
                    <td><Badge variant={statusVariant(x.status)}>{x.status}</Badge></td></tr>
                ))}
              </tbody></table>
          </div>
        </div>
      )}
    </div>
  )
}
