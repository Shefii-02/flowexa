// src/pages/leads/InstallmentsPage.tsx
// Installment follow-ups: who has to pay what, overdue / due today / upcoming / paid, and (HR) the rules for how installments count toward targets.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { installmentApi, staffApi } from '@/api'
import { Badge, Button, EmptyState, Spinner } from '@/components/ui'
import { getError } from '@/utils'
import { pid } from '@/lib/publicId'

const money = (n: any) => (n === null || n === undefined || n === '' ? '—' : `₹${Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`)
const day = (d?: string | null) => (d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const VIEWS = [['overdue', 'Overdue'], ['today', 'Due today'], ['upcoming', 'Upcoming'], ['paid', 'Paid / waived'], ['all', 'All']] as const

function Rules({ onClose }: { onClose: () => void }) {
  const [s, setS] = useState<any>(null)
  const [staff, setStaff] = useState<{ id: number; name: string }[]>([])
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    installmentApi.settings().then((r) => setS(r.data.data)).catch((e) => toast.error(getError(e)))
    staffApi.list({ per_page: 200 }).then((r) => setStaff((r.data.data ?? r.data.staff ?? []).map((x: any) => ({ id: x.id, name: x.name })))).catch(() => {})
  }, [])
  if (!s) return <div className="card p-6 flex justify-center"><Spinner /></div>
  const set = (k: string, v: any) => setS({ ...s, [k]: v })
  const save = async () => { setBusy(true); try { const r = await installmentApi.saveSettings(s); setS(r.data.data); toast.success('Rules saved.'); onClose() } catch (e) { toast.error(getError(e)) } finally { setBusy(false) } }
  return (
    <div className="card p-5 space-y-5" data-testid="installment-rules">
      <div>
        <p className="font-semibold text-gray-800">What counts toward sales targets</p>
        <div className="mt-2 space-y-2 text-sm">
          {[['collected', 'Cash collected', 'Each payment counts in the month it is paid. A 2999 course paid in 3 parts adds to three months. Incentive follows real money.'],
            ['full', 'Full value at enrolment', 'The whole amount counts when the student enrolls, even if most is still unpaid.'],
            ['first', 'First installment only', 'Only payments on the first installment count; later ones add nothing.']].map(([v, t, h]) => (
            <label key={v} className="flex gap-2 items-start"><input type="radio" className="mt-1" checked={s.target_basis === v} onChange={() => set('target_basis', v)} /><span><b>{t}</b><br /><span className="text-xs text-gray-500">{h}</span></span></label>
          ))}
        </div>
        <div className="mt-4">
          <p className="font-semibold text-gray-800 text-sm">What counts toward incentives</p>
          <p className="text-xs text-gray-500 mb-1">Can differ from the target — e.g. count the full value toward the target but pay incentive only on cash collected.</p>
          <select className="input w-72" value={s.incentive_basis} onChange={(e) => set('incentive_basis', e.target.value)}>
            <option value="same">Same as the target above</option><option value="collected">Cash collected</option><option value="full">Full value at enrolment</option><option value="first">First installment only</option>
          </select>
        </div>
        <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" checked={s.category_follow_basis} onChange={(e) => set('category_follow_basis', e.target.checked)} /> Lead-category targets follow the same rule <span className="text-xs text-gray-400">(off = they keep counting the full lead value)</span></label>
      </div>

      <div>
        <p className="font-semibold text-gray-800">Late fee for a missed installment</p>
        <div className="mt-2 flex flex-wrap items-end gap-3 text-sm">
          <div><label className="block text-xs text-gray-500 mb-1">Fee</label>
            <select className="input w-40" value={s.late_fee_mode} onChange={(e) => set('late_fee_mode', e.target.value)}><option value="none">No late fee</option><option value="fixed">Fixed amount</option><option value="percent">% of installment</option></select></div>
          {s.late_fee_mode !== 'none' && <>
            <div><label className="block text-xs text-gray-500 mb-1">{s.late_fee_mode === 'percent' ? 'Percent' : 'Amount (₹)'}</label><input type="number" min={0} className="input w-28" value={s.late_fee_value} onChange={(e) => set('late_fee_value', e.target.value)} /></div>
            <div><label className="block text-xs text-gray-500 mb-1">After how many days late</label><input type="number" min={0} className="input w-28" value={s.late_fee_grace_days} onChange={(e) => set('late_fee_grace_days', e.target.value)} /></div>
            <label className="flex items-center gap-2 pb-2"><input type="checkbox" checked={s.late_fee_counts_for_target} onChange={(e) => set('late_fee_counts_for_target', e.target.checked)} /> A collected late fee also counts toward the target</label>
          </>}
        </div>
      </div>

      <div>
        <p className="font-semibold text-gray-800">Who follows up and collects</p>
        <div className="mt-2 space-y-2 text-sm">
          {[['owner_hr', 'Lead owner — HR can step in', 'The staff member who owns the lead; HR (and anyone it is assigned to) can too.'],
            ['owner', 'Lead owner only', 'Only the owner (or the person an installment is assigned to) follows up.'],
            ['collector', 'A collections person', 'One chosen person handles all installments; the owner only sees the status.']].map(([v, t, h]) => (
            <label key={v} className="flex gap-2 items-start"><input type="radio" className="mt-1" checked={s.followup_mode === v} onChange={() => set('followup_mode', v)} /><span><b>{t}</b><br /><span className="text-xs text-gray-500">{h}</span></span></label>
          ))}
          {s.followup_mode === 'collector' && (
            <select className="input w-64" value={s.collector_user_id ?? ''} onChange={(e) => set('collector_user_id', e.target.value ? +e.target.value : null)}><option value="">Choose the person…</option>{staff.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
          )}
          <p className="text-xs text-gray-400">Any installment can also be assigned to a specific person from the lead page. Only HR can edit, waive or reverse payments.</p>
        </div>
      </div>
      <div className="flex gap-2"><Button onClick={save} loading={busy}>Save rules</Button><Button variant="secondary" onClick={onClose}>Close</Button></div>
    </div>
  )
}

export default function InstallmentsPage() {
  const [view, setView] = useState<string>('overdue')
  const [mine, setMine] = useState(false)
  const [rows, setRows] = useState<any[] | null>(null)
  const [isHr, setIsHr] = useState(false)
  const [rules, setRules] = useState(false)
  const [canSwitch, setCanSwitch] = useState(false)
  const [picked, setPicked] = useState<number[]>([])
  const [staff, setStaff] = useState<{ id: number; name: string }[]>([])
  const [to, setTo] = useState('')
  const [moving, setMoving] = useState(false)
  useEffect(() => { staffApi.list({ per_page: 200 }).then((r) => setStaff((r.data.data ?? r.data.staff ?? []).filter((x: any) => x.is_active !== false).map((x: any) => ({ id: x.id, name: x.name })))).catch(() => {}) }, [])
  const load = useCallback(() => {
    setRows(null); setPicked([])
    installmentApi.list({ view, mine: mine ? 1 : undefined }).then((r) => { setRows(r.data.data ?? []); setIsHr(!!r.data.is_hr); setCanSwitch(!!r.data.can_switch) }).catch((e) => { toast.error(getError(e)); setRows([]) })
  }, [view, mine])
  useEffect(() => { load() }, [load])
  const open = (rows ?? []).filter((r) => r.status !== 'paid' && r.status !== 'waived')
  const allPicked = open.length > 0 && open.every((r) => picked.includes(r.id))
  const toggle = (id: number) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))
  const move = async () => {
    setMoving(true)
    try {
      const r = await installmentApi.bulkAssign(picked, to === '' || to === 'none' ? null : +to)
      toast.success(`${r.data.updated} installment${r.data.updated === 1 ? '' : 's'} moved.`); setTo(''); load()
    } catch (e) { toast.error(getError(e)) } finally { setMoving(false) }
  }
  const totalBal = (rows ?? []).reduce((a, r) => a + (r.status === 'paid' || r.status === 'waived' ? 0 : r.balance), 0)

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Installment follow-ups</h1><p className="page-sub">Collect what students still owe — payments, promises and who is chasing</p></div>
        {isHr && <Button variant="secondary" onClick={() => setRules(!rules)}>{rules ? 'Hide rules' : 'HR rules'}</Button>}
      </div>
      {rules && <Rules onClose={() => setRules(false)} />}

      <div className="flex flex-wrap items-center gap-1 border-b border-gray-200">
        {VIEWS.map(([k, label]) => <button key={k} onClick={() => setView(k)} className={`px-4 py-2 text-sm -mb-px border-b-2 ${view === k ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>{label}</button>)}
        {isHr && <label className="ml-auto flex items-center gap-2 text-xs text-gray-500"><input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} /> Only mine</label>}
      </div>

      {rows === null ? <div className="p-10 flex justify-center"><Spinner /></div> : rows.length === 0 ? <EmptyState icon="💸" title="Nothing here" desc="No installments in this view." /> : (
        <div className="card table-wrapper">
          <div className="px-5 py-2 text-xs text-gray-500">{rows.length} installment{rows.length > 1 ? 's' : ''}{view !== 'paid' && <> · balance to collect <b>{money(totalBal)}</b></>}</div>
          {canSwitch && picked.length > 0 && (
            <div className="mx-5 mb-2 flex flex-wrap items-center gap-2 rounded-lg bg-indigo-50 border border-indigo-100 px-3 py-2 text-sm">
              <b>{picked.length}</b> selected — switch follow-up to
              <select className="input w-56" value={to} onChange={(e) => setTo(e.target.value)}>
                <option value="">Choose…</option><option value="none">No one in particular (lead owner / HR)</option>{staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
              <Button size="sm" onClick={move} loading={moving} disabled={to === ''}>Switch</Button>
              <button className="text-xs text-gray-500 hover:underline" onClick={() => setPicked([])}>Clear</button>
            </div>
          )}
          <table className="table"><thead><tr>{canSwitch && <th className="w-8"><input type="checkbox" checked={allPicked} onChange={() => setPicked(allPicked ? [] : open.map((r) => r.id))} title="Select all open" /></th>}<th>Student</th><th>Installment</th><th>Due</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th>Follow-up by</th><th>Last follow-up</th><th /></tr></thead>
            <tbody>
              {rows.map((i) => (
                <tr key={i.id}>
                  {canSwitch && <td>{i.status !== 'paid' && i.status !== 'waived' && <input type="checkbox" checked={picked.includes(i.id)} onChange={() => toggle(i.id)} />}</td>}
                  <td><p className="font-medium text-gray-900">{i.contact_name || i.contact_phone || `Lead #${i.lead_id}`}</p><p className="text-xs text-gray-400">{i.contact_phone}</p></td>
                  <td>#{i.seq}</td>
                  <td className="whitespace-nowrap">{day(i.due_date)}{i.overdue && <div className="text-[11px] text-red-500">{i.days_overdue} days late</div>}</td>
                  <td>{money(i.amount)}{i.late_fee > 0 && <div className="text-[11px] text-red-500">+{money(i.late_fee)} fee</div>}</td>
                  <td className="text-green-700">{money(i.paid)}</td><td className="font-medium">{money(i.balance)}</td>
                  <td><Badge variant={i.overdue ? 'red' : i.status === 'paid' ? 'green' : i.status === 'partial' ? 'yellow' : i.status === 'waived' ? 'gray' : 'blue'}>{i.overdue ? 'overdue' : i.status}</Badge>{i.promised_on && i.status !== 'paid' && <div className="text-[11px] text-gray-400">promised {day(i.promised_on)}</div>}</td>
                  <td className="text-gray-600">{i.assigned_name || i.owner_name || '—'}</td>
                  <td className="text-xs text-gray-400">{i.last_followup_at ? day(i.last_followup_at) : '—'}</td>
                  <td><Link to={`/crm/leads/${pid(i.lead_id)}`} className="text-xs text-brand-600 hover:underline whitespace-nowrap">Open lead ›</Link></td>
                </tr>
              ))}
            </tbody></table>
        </div>
      )}
    </div>
  )
}
