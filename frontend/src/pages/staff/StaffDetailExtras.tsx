// src/pages/staff/StaffDetailExtras.tsx
// The extra tabs of the staff details page: leads, performance (with incentives), documents, activity, and the work-setup panel.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { api } from '@/api/client'
import { staffExtrasApi } from '@/api'
import { Badge, Button, Spinner } from '@/components/ui'
import { getError } from '@/utils'
import { pid } from '@/lib/publicId'

const money = (n: any) => (n === null || n === undefined || n === '' ? '—' : `₹${Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`)
const day = (d?: string | null) => (d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const when = (d?: string | null) => (d ? new Date(d).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')
const monthLabel = (ym: string) => new Date(`${ym}-01`).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })

function Stat({ label, value, sub, tone }: { label: string; value: any; sub?: string; tone?: string }) {
  return (
    <div className="card p-4"><p className="text-xs text-gray-500">{label}</p><p className={`text-2xl font-semibold mt-1 ${tone ?? 'text-gray-900'}`}>{value}</p>{sub && <p className="text-[11px] text-gray-400 mt-0.5">{sub}</p>}</div>
  )
}
const Loading = () => <div className="p-8 flex justify-center"><Spinner /></div>

const STAGES: [string, string, string][] = [['new', 'New', 'text-orange-600'], ['contacted', 'Contacted', 'text-amber-600'], ['follow_up', 'Follow-up', 'text-yellow-600'], ['enrolled', 'Won / closed', 'text-green-600'], ['lost', 'Lost', 'text-red-600']]

/** New / contacted / follow-up / won / lost, this month's movement, follow-ups due and the latest leads. */
export function LeadsTab({ id, month }: { id: number; month: string }) {
  const [d, setD] = useState<any>(null)
  useEffect(() => { setD(null); staffExtrasApi.leadsSummary(id, month).then((r) => setD(r.data)).catch((e) => toast.error(getError(e))) }, [id, month])
  if (!d) return <Loading />
  const ms = d.month_stats, fu = d.followups
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
        {STAGES.map(([k, label, tone]) => <Stat key={k} label={label} value={d.by_stage[k]} tone={tone} sub={k === 'enrolled' || k === 'lost' ? 'all time' : 'open now'} />)}
      </div>
      <div className="card p-5">
        <p className="text-sm font-semibold mb-3">{monthLabel(d.month)}</p>
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
          <div><p className="text-[11px] uppercase text-gray-400">Assigned</p><p className="text-lg font-semibold">{ms.assigned}</p></div>
          <div><p className="text-[11px] uppercase text-gray-400">Closed / won</p><p className="text-lg font-semibold text-green-600">{ms.enrolled}</p></div>
          <div><p className="text-[11px] uppercase text-gray-400">Lost</p><p className="text-lg font-semibold text-red-600">{ms.lost}</p></div>
          <div><p className="text-[11px] uppercase text-gray-400">Sale value won</p><p className="text-lg font-semibold">{money(ms.sale_value)}</p></div>
          <div><p className="text-[11px] uppercase text-gray-400">Calls / activities</p><p className="text-lg font-semibold">{ms.calls}</p></div>
        </div>
      </div>
      <div className="grid grid-cols-3 gap-3">
        <Stat label="Follow-ups due today" value={fu.due_today} tone={fu.due_today ? 'text-amber-600' : undefined} />
        <Stat label="Overdue follow-ups" value={fu.overdue} tone={fu.overdue ? 'text-red-600' : undefined} />
        <Stat label="Upcoming follow-ups" value={fu.upcoming} />
      </div>
      <div className="card table-wrapper">
        <div className="card-header"><h3 className="card-title">Latest leads ({d.total} total · {d.open} open)</h3></div>
        <table className="table"><thead><tr><th>Lead</th><th>Stage</th><th>Category</th><th>Value</th><th>Updated</th><th /></tr></thead>
          <tbody>{d.recent.length === 0 ? <tr><td colSpan={6} className="text-center text-gray-400 py-6">No leads assigned.</td></tr> : d.recent.map((l: any) => (
            <tr key={l.id}><td><p className="font-medium">{l.contact_name || l.contact_phone || `Lead #${l.id}`}</p><p className="text-xs text-gray-400">{l.contact_phone}</p></td>
              <td><Badge variant={l.stage === 'enrolled' ? 'green' : l.stage === 'lost' ? 'red' : 'blue'}>{l.stage === 'enrolled' ? 'won' : l.stage.replace('_', ' ')}</Badge></td>
              <td>{l.category || '—'}</td><td>{l.sale_value ? money(l.sale_value) : '—'}</td><td className="text-xs text-gray-400">{when(l.updated_at)}</td>
              <td><Link to={`/crm/leads/${pid(l.id)}`} className="text-xs text-brand-600 hover:underline">Open ›</Link></td></tr>
          ))}</tbody></table>
      </div>
      <Link to={`/crm/leads/staff/${pid(id)}`} className="text-sm text-brand-600 hover:underline">All of this person's leads ›</Link>
    </div>
  )
}

/** Month by month: sales vs target, attendance, leads, tasks and incentives — with the incentive ledger below. */
export function PerformanceTab({ id }: { id: number }) {
  const [d, setD] = useState<any>(null)
  const [months, setMonths] = useState(6)
  useEffect(() => { setD(null); staffExtrasApi.performance(id, months).then((r) => setD(r.data)).catch((e) => toast.error(getError(e))) }, [id, months])
  if (!d) return <Loading />
  const max = Math.max(1, ...d.months.map((m: any) => Math.max(m.sold, m.target)))
  const tot = d.incentive_totals
  return (
    <div className="space-y-5">
      <div className="flex items-center gap-2"><p className="text-sm font-semibold">Last</p>
        <select className="input w-24" value={months} onChange={(e) => setMonths(+e.target.value)}>{[3, 6, 9, 12].map((n) => <option key={n} value={n}>{n} months</option>)}</select></div>

      <div className="card p-5">
        <p className="text-sm font-semibold mb-3">Sales vs target</p>
        <div className="flex items-end gap-3 h-44">
          {d.months.map((m: any) => (
            <div key={m.month} className="flex-1 flex flex-col items-center justify-end h-full min-w-0">
              <div className="w-full flex items-end justify-center gap-1 flex-1">
                <div className="w-1/2 bg-gray-200 rounded-t" style={{ height: `${(m.target / max) * 100}%` }} title={`Target ${money(m.target)}`} />
                <div className={`w-1/2 rounded-t ${m.target > 0 && m.sold >= m.target ? 'bg-green-500' : 'bg-brand-500'}`} style={{ height: `${(m.sold / max) * 100}%` }} title={`Sold ${money(m.sold)}`} />
              </div>
              <p className="text-[11px] text-gray-500 mt-1">{m.label}</p>
              <p className="text-[10px] text-gray-400">{m.achieved_pct != null ? `${m.achieved_pct}%` : '—'}</p>
            </div>
          ))}
        </div>
        <p className="text-[11px] text-gray-400 mt-2"><span className="inline-block w-2 h-2 bg-gray-200 mr-1" />Target <span className="inline-block w-2 h-2 bg-brand-500 ml-3 mr-1" />Sold <span className="inline-block w-2 h-2 bg-green-500 ml-3 mr-1" />Target reached</p>
      </div>

      <div className="card table-wrapper">
        <table className="table"><thead><tr><th>Month</th><th>Sold</th><th>Target</th><th>Present</th><th>Late</th><th>Absent</th><th>Hours</th><th>Leads in</th><th>Won</th><th>Lost</th><th>Tasks done</th><th>Incentive</th></tr></thead>
          <tbody>{d.months.map((m: any) => (
            <tr key={m.month}><td className="font-medium whitespace-nowrap">{m.label}</td><td>{money(m.sold)}</td><td>{money(m.target)}</td><td>{m.present}</td><td>{m.late_days}</td><td>{m.absent}</td><td>{m.worked_hours}</td>
              <td>{m.leads_assigned}</td><td className="text-green-600">{m.leads_enrolled}</td><td className="text-red-600">{m.leads_lost}</td><td>{m.tasks_done}</td><td className="font-medium">{money(m.incentive)}</td></tr>
          ))}</tbody></table>
      </div>

      <div className="grid grid-cols-3 gap-3"><Stat label="Incentive pending" value={money(tot.pending)} /><Stat label="Approved" value={money(tot.approved)} /><Stat label="Paid" value={money(tot.paid)} tone="text-green-600" /></div>
      <div className="card table-wrapper">
        <div className="card-header"><h3 className="card-title">Incentives earned</h3></div>
        <table className="table"><thead><tr><th>Earned on</th><th>For</th><th>On sale of</th><th>Incentive</th><th>Status</th></tr></thead>
          <tbody>{d.incentives.length === 0 ? <tr><td colSpan={5} className="text-center text-gray-400 py-6">No incentives yet.</td></tr> : d.incentives.map((i: any) => (
            <tr key={i.id}><td className="whitespace-nowrap">{day(i.earned_on)}</td><td className="max-w-xs truncate">{i.title}</td><td>{money(i.base_amount)}</td><td className="font-medium">{money(i.amount)}</td>
              <td><Badge variant={i.status === 'paid' ? 'green' : i.status === 'approved' ? 'blue' : 'yellow'}>{i.status}</Badge></td></tr>
          ))}</tbody></table>
      </div>
    </div>
  )
}

/** The person's documents: view, upload, delete. */
export function DocumentsTab({ id }: { id: number }) {
  const [docs, setDocs] = useState<any[] | null>(null)
  const [types, setTypes] = useState<{ id: number; name: string }[]>([])
  const [type, setType] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    staffExtrasApi.documents(id).then((r) => setDocs(r.data.data ?? [])).catch((e) => { toast.error(getError(e)); setDocs([]) })
    staffExtrasApi.documentTypes().then((r) => { setTypes(r.data.data ?? []); setType((t) => t || r.data.data?.[0]?.name || '') }).catch(() => {})
  }, [id])
  const upload = async () => {
    if (!file || !type) return
    setBusy(true)
    try { const r = await staffExtrasApi.upload(id, type, file); setDocs(r.data.data ?? []); setFile(null); toast.success('Uploaded.') } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  if (!docs) return <Loading />
  return (
    <div className="space-y-4">
      <div className="card p-5 flex flex-wrap items-end gap-2">
        <div><label className="block text-xs text-gray-500 mb-1">Type</label><select className="input w-52" value={type} onChange={(e) => setType(e.target.value)}>{types.map((t) => <option key={t.id} value={t.name}>{t.name}</option>)}</select></div>
        <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-xs" />
        <Button size="sm" onClick={upload} loading={busy} disabled={!file || !type}>Upload</Button>
      </div>
      <div className="card">
        {docs.length === 0 ? <p className="p-6 text-sm text-gray-400 text-center">No documents uploaded.</p> : (
          <ul className="divide-y divide-gray-100">{docs.map((x) => (
            <li key={x.id} className="flex items-center gap-3 px-5 py-3 text-sm">
              <span className="text-[10px] px-2 py-0.5 rounded bg-gray-100">{x.type}</span>
              <a href={x.url} target="_blank" rel="noreferrer" className="flex-1 truncate text-brand-600 hover:underline">{x.title}</a>
              <span className="text-xs text-gray-400">{day(x.created_at)} · {Math.round((x.size || 0) / 1024)} KB</span>
              <button className="text-xs text-red-500" onClick={async () => { if (!window.confirm('Delete this document?')) return; try { const r = await staffExtrasApi.removeDocument(id, x.id); setDocs(r.data.data ?? []) } catch (e) { toast.error(getError(e)) } }}>Delete</button>
            </li>
          ))}</ul>
        )}
      </div>
    </div>
  )
}

/** Last login, linked phones and a merged feed of what this person did (and what was changed about them). */
export function ActivityTab({ id }: { id: number }) {
  const [d, setD] = useState<any>(null)
  useEffect(() => { staffExtrasApi.activity(id).then((r) => setD(r.data)).catch((e) => toast.error(getError(e))) }, [id])
  if (!d) return <Loading />
  return (
    <div className="space-y-5">
      <div className="grid md:grid-cols-2 gap-5">
        <div className="card p-5"><p className="text-sm font-semibold mb-2">Last login</p><p className="text-lg">{d.last_login_at ? when(d.last_login_at) : 'Never'}</p></div>
        <div className="card p-5">
          <p className="text-sm font-semibold mb-2">Linked devices</p>
          {d.devices.length === 0 ? <p className="text-xs text-gray-400">No phone linked.</p> : (
            <ul className="text-sm space-y-1.5">{d.devices.map((v: any) => (
              <li key={v.id} className="flex items-center gap-2"><span>{v.platform === 'android' ? '🤖' : '📱'}</span><span className="font-medium">{v.device_name || v.platform}</span>
                <span className="text-xs text-gray-400">{v.app_version ? `v${v.app_version} · ` : ''}active {when(v.last_active_at)}</span>{v.revoked_at && <Badge variant="gray">removed</Badge>}</li>
            ))}</ul>
          )}
        </div>
      </div>
      <div className="card">
        <div className="card-header"><h3 className="card-title">Recent activity</h3></div>
        {d.feed.length === 0 ? <p className="p-6 text-sm text-gray-400 text-center">Nothing recorded yet.</p> : (
          <ul className="divide-y divide-gray-50">{d.feed.map((f: any, i: number) => (
            <li key={i} className="px-5 py-2.5 text-sm flex gap-3">
              <span className="text-xs text-gray-400 w-32 shrink-0">{when(f.at)}</span>
              <Badge variant={f.type === 'salary' ? 'yellow' : f.type === 'lead' ? 'blue' : 'gray'}>{f.type}</Badge>
              <span className="flex-1">{f.text}{f.detail && <span className="block text-xs text-gray-500">{f.detail}</span>}</span>
              {f.lead_id && <Link to={`/crm/leads/${pid(f.lead_id)}`} className="text-xs text-brand-600 hover:underline">Lead ›</Link>}
            </li>
          ))}</ul>
        )}
      </div>
    </div>
  )
}

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

/** Shift, weekly off, attendance type and work mode — changed right here (HR). */
export function WorkSetup({ id, work, onSaved }: { id: number; work: any; onSaved: () => void }) {
  const [shifts, setShifts] = useState<any[]>([])
  const [shiftId, setShiftId] = useState<string>('')
  const [off, setOff] = useState<number[]>([])
  const [att, setAtt] = useState('manual')
  const [mode, setMode] = useState('wfo')
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => {
    api.get('/hr/shifts').then((r) => setShifts(r.data?.data ?? [])).catch(() => {})
    api.get('/hr/staff-profiles').then((r) => {
      const row = (r.data?.data ?? []).find((x: any) => x.user?.id === id)
      if (row) { setShiftId(row.shift_id ? String(row.shift_id) : ''); setOff((row.weekly_off ?? []).map(Number)); setAtt(row.attendance_type ?? 'manual'); setMode(row.work_mode ?? 'wfo') }
    }).catch(() => {})
  }, [id])
  useEffect(() => { load() }, [load, work])
  const save = async () => {
    setBusy(true)
    try { await api.put(`/hr/staff-profiles/${id}`, { shift_id: shiftId ? +shiftId : null, weekly_off: off, attendance_type: att, work_mode: mode }); toast.success('Work setup saved.'); onSaved() } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  return (
    <div className="card p-5" data-testid="work-setup">
      <p className="text-sm font-semibold mb-3">Work setup</p>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div><label className="block text-xs text-gray-500 mb-1">Shift</label>
          <select className="input" value={shiftId} onChange={(e) => setShiftId(e.target.value)}><option value="">No shift</option>{shifts.filter((s) => s.is_active || String(s.id) === shiftId).map((s) => <option key={s.id} value={s.id}>{s.name} ({s.start_time}–{s.end_time})</option>)}</select></div>
        <div><label className="block text-xs text-gray-500 mb-1">Check-in</label><select className="input" value={att} onChange={(e) => setAtt(e.target.value)}><option value="manual">Manual</option><option value="gps">GPS</option></select></div>
        <div><label className="block text-xs text-gray-500 mb-1">Work mode</label><select className="input" value={mode} onChange={(e) => setMode(e.target.value)}><option value="wfo">Work from office</option><option value="wfh">Work from home</option><option value="hybrid">Hybrid</option></select></div>
        <div><label className="block text-xs text-gray-500 mb-1">Weekly off</label>
          <div className="flex flex-wrap gap-1">{DAYS.map((n, i) => (
            <button type="button" key={i} onClick={() => setOff(off.includes(i) ? off.filter((x) => x !== i) : [...off, i])} className={`px-2 py-1 rounded text-xs border ${off.includes(i) ? 'bg-brand-600 text-white border-brand-600' : 'border-gray-200 text-gray-600'}`}>{n}</button>
          ))}</div></div>
      </div>
      <Button size="sm" className="mt-3" onClick={save} loading={busy}>Save work setup</Button>
    </div>
  )
}

/** A one-file summary of the staff member (opens in Excel). */
export function exportProfileCsv(data: any) {
  const p = data.profile, pe = data.personal, a = data.attendance.summary
  const rows: [string, any][] = [
    ['Name', p.name], ['Email', p.email], ['Phone', p.phone], ['Role', p.role], ['Department', p.department], ['Designation', p.designation], ['Joined', p.joined_on], ['Shift', p.work?.shift],
    ['Monthly salary', p.work?.monthly_salary], ['Hourly rate', p.work?.hourly_rate], ['Monthly target', p.work?.monthly_target],
    ['DOB', pe.dob], ['Gender', pe.gender], ['Blood group', pe.blood_group], ['Address', [pe.address, pe.city, pe.state, pe.pincode].filter(Boolean).join(', ')],
    ['Father', `${pe.father_name ?? ''} ${pe.father_phone ?? ''}`.trim()], ['Mother', `${pe.mother_name ?? ''} ${pe.mother_phone ?? ''}`.trim()],
    ['Emergency contact', `${pe.emergency_name ?? ''} ${pe.emergency_phone ?? ''} ${pe.emergency_relation ?? ''}`.trim()],
    [`Attendance ${data.month}`, `present ${a.present}, absent ${a.absent}, on leave ${a.on_leave}, late ${a.late_days}, worked ${a.worked_hours} h`],
    ...((data.leave_balance ?? []).map((l: any) => [`Leave: ${l.name}`, `used ${l.used}${l.allowed != null ? ` of ${l.allowed}` : ''}`]) as [string, any][]),
  ]
  const csv = '﻿' + rows.map(([k, v]) => `"${k}","${String(v ?? '').replace(/"/g, '""')}"`).join('\n')
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const el = document.createElement('a'); el.href = url; el.download = `${String(p.name).replace(/\W+/g, '_')}_profile.csv`; el.click(); URL.revokeObjectURL(url)
}
