// src/pages/leads/components/LeadInstallments.tsx
// A lead's instalment plan on the lead page: set it up (how many, when, how much each — amounts may differ), record payments,
// log follow-ups, and (HR) edit / waive / reverse. Every action is kept in the activity log below.
import { useCallback, useEffect, useMemo, useState } from 'react'
import toast from 'react-hot-toast'
import { installmentApi, staffApi } from '@/api'
import { Badge, Button, Drawer, Input, Spinner } from '@/components/ui'
import { getError } from '@/utils'

const money = (n: any) => (n === null || n === undefined || n === '' ? '—' : `₹${Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`)
const day = (d?: string | null) => (d ? new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
const when = (d?: string | null) => (d ? new Date(d).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')
const plusMonths = (iso: string, n: number) => { const d = new Date(iso); d.setMonth(d.getMonth() + n); return d.toISOString().slice(0, 10) }
const ACTION: Record<string, string> = { credit_kept: 'Earlier credit kept', plan_created: 'Plan created', edited: 'Edited', paid: 'Payment', reversed: 'Payment reversed', waived: 'Waived', followup: 'Follow-up', assigned: 'Follow-up person changed', late_fee: 'Late fee added' }
const statusVariant = (s: string, overdue: boolean): any => (overdue ? 'red' : ({ paid: 'green', partial: 'yellow', waived: 'gray', pending: 'blue' } as any)[s] ?? 'gray')

type Row = { due_date: string; amount: string }

export default function LeadInstallments({ leadId, saleValue }: { leadId: number; saleValue?: number | string | null }) {
  const [d, setD] = useState<any>(null)
  const [staff, setStaff] = useState<{ id: number; name: string }[]>([])
  const [setup, setSetup] = useState(false)
  const [total, setTotal] = useState(String(saleValue ?? ''))
  const [count, setCount] = useState('3')
  const [first, setFirst] = useState(new Date().toISOString().slice(0, 10))
  const [rows, setRows] = useState<Row[]>([])
  const [assignTo, setAssignTo] = useState('')
  const [busy, setBusy] = useState(false)
  const [action, setAction] = useState<null | { kind: 'pay' | 'followup' | 'edit'; inst: any }>(null)
  const [f, setF] = useState<any>({})
  const [showLog, setShowLog] = useState(false)

  const load = useCallback(() => { installmentApi.forLead(leadId).then((r) => setD(r.data)).catch((e) => toast.error(getError(e))) }, [leadId])
  useEffect(() => { load() }, [load])
  useEffect(() => { staffApi.list({ per_page: 200 }).then((r) => setStaff((r.data.data ?? r.data.staff ?? []).map((x: any) => ({ id: x.id, name: x.name })))).catch(() => {}) }, [])

  const split = () => {
    const n = Math.max(1, Math.min(60, +count || 1)); const t = +total || 0
    const base = Math.floor((t / n) * 100) / 100
    const out: Row[] = Array.from({ length: n }, (_, i) => ({ due_date: plusMonths(first, i), amount: String(base) }))
    out[n - 1].amount = String(Math.round((t - base * (n - 1)) * 100) / 100)   // the last one absorbs the rounding
    setRows(out)
  }
  const sum = useMemo(() => Math.round(rows.reduce((a, r) => a + (+r.amount || 0), 0) * 100) / 100, [rows])

  const createPlan = async () => {
    setBusy(true)
    try {
      const r = await installmentApi.createPlan(leadId, { total_amount: +total, installments: rows.map((x) => ({ due_date: x.due_date, amount: +x.amount })), assigned_to: assignTo ? +assignTo : null })
      setD(r.data); setSetup(false); toast.success('Installment plan saved.')
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const run = async (fn: () => Promise<any>, ok: string) => { setBusy(true); try { const r = await fn(); setD(r.data); toast.success(ok); setAction(null) } catch (e) { toast.error(getError(e)) } finally { setBusy(false) } }
  const ask = (msg: string) => window.prompt(msg)?.trim() || null

  if (!d) return <div className="card p-5 flex justify-center"><Spinner /></div>
  const plan = d.plan
  const isHr = d.is_hr

  return (
    <div className="card p-5" data-testid="lead-installments">
      <div className="flex items-center justify-between mb-3">
        <p className="text-sm font-semibold text-gray-800">Installments {plan && <span className="text-xs font-normal text-gray-400">· total {money(plan.total_amount)} · collected {money(plan.collected)} · balance {money(plan.balance)}</span>}</p>
        {!plan && !setup && <Button size="sm" onClick={() => { setSetup(true); setRows([]) }}>Set up installments</Button>}
        {plan && isHr && !setup && d.installments.every((i: any) => !i.paid) && <button className="text-xs text-brand-600 hover:underline" onClick={() => { setSetup(true); setTotal(String(plan.total_amount)); setRows(d.installments.map((i: any) => ({ due_date: i.due_date, amount: String(i.amount) }))) }}>Replace plan</button>}
      </div>

      {plan?.credit_mode === 'upfront' && (
        <p className="mb-3 rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">This sale was already credited in full, and its incentive was paid out, before the plan was made. That credit stays as it is, so these payments are not counted toward the target or incentive again.</p>
      )}

      {setup && (
        <div className="rounded-lg border border-gray-200 p-4 mb-4 space-y-3">
          <p className="text-xs text-gray-500">The lead's value stays the full amount; the plan says how it is collected. Amounts can differ month to month, but they must add up to the total.</p>
          <div className="flex flex-wrap items-end gap-2">
            <Input label="Total (₹)" type="number" value={total} onChange={(e) => setTotal(e.target.value)} className="w-36" />
            <Input label="No. of installments" type="number" min={1} max={60} value={count} onChange={(e) => setCount(e.target.value)} className="w-32" />
            <Input label="First due date" type="date" value={first} onChange={(e) => setFirst(e.target.value)} />
            <Button size="sm" variant="secondary" onClick={split} disabled={!+total}>Split evenly</Button>
          </div>
          {rows.length > 0 && (
            <>
              <table className="w-full text-sm"><thead><tr className="text-left text-xs text-gray-400"><th className="py-1">#</th><th>Due date</th><th>Amount (₹)</th><th /></tr></thead>
                <tbody>{rows.map((r, i) => (
                  <tr key={i}><td className="py-1 pr-2">{i + 1}</td>
                    <td><input type="date" className="input" value={r.due_date} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, due_date: e.target.value } : x)))} /></td>
                    <td><input type="number" className="input w-32" value={r.amount} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, amount: e.target.value } : x)))} /></td>
                    <td><button className="text-xs text-red-500" onClick={() => setRows(rows.filter((_, j) => j !== i))}>×</button></td></tr>
                ))}</tbody></table>
              <div className={`text-xs ${Math.abs(sum - (+total || 0)) < 0.01 ? 'text-green-600' : 'text-red-500'}`}>Installments add up to {money(sum)} of {money(+total)}</div>
              {staff.length > 0 && (
                <div><label className="block text-xs text-gray-500 mb-1">Who follows these up <span className="text-gray-400">(blank = per HR's rule)</span></label>
                  <select className="input w-56" value={assignTo} onChange={(e) => setAssignTo(e.target.value)}><option value="">Default</option>{staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
              )}
            </>
          )}
          <div className="flex gap-2"><Button size="sm" onClick={createPlan} loading={busy} disabled={rows.length === 0 || Math.abs(sum - (+total || 0)) > 0.009}>Save plan</Button><Button size="sm" variant="secondary" onClick={() => setSetup(false)}>Cancel</Button></div>
        </div>
      )}

      {plan && (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="text-left text-xs text-gray-400 border-b border-gray-100"><th className="py-1.5">#</th><th>Due</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th>Follow-up by</th><th /></tr></thead>
              <tbody>
                {d.installments.map((i: any) => (
                  <tr key={i.id} className="border-b border-gray-50">
                    <td className="py-1.5">{i.seq}</td>
                    <td className="whitespace-nowrap">{day(i.due_date)}{i.overdue && <span className="ml-1 text-[11px] text-red-500">{i.days_overdue}d late</span>}</td>
                    <td>{money(i.amount)}{i.late_fee > 0 && <div className="text-[11px] text-red-500">+{money(i.late_fee)} late fee{i.fee_due > 0 ? ` (${money(i.fee_due)} due)` : ' (paid)'}</div>}</td>
                    <td className="text-green-700">{money(i.paid)}</td><td>{money(i.balance)}</td>
                    <td><Badge variant={statusVariant(i.status, i.overdue)}>{i.overdue ? 'overdue' : i.status}</Badge>{i.promised_on && i.status !== 'paid' && <div className="text-[11px] text-gray-400">promised {day(i.promised_on)}</div>}</td>
                    <td className="text-gray-600">{i.assigned_name || 'Lead owner'}</td>
                    <td className="whitespace-nowrap text-right">
                      {i.status !== 'paid' && i.status !== 'waived' && d.can_follow_up && (<>
                        <button className="text-xs text-green-700 hover:underline mr-2" onClick={() => { setF({ amount: String(i.balance), late_fee_amount: i.fee_due ? String(i.fee_due) : '', paid_on: new Date().toISOString().slice(0, 10), method: '', reference: '' }); setAction({ kind: 'pay', inst: i }) }}>Record payment</button>
                        <button className="text-xs text-brand-600 hover:underline mr-2" onClick={() => { setF({ note: '', promised_on: i.promised_on ?? '' }); setAction({ kind: 'followup', inst: i }) }}>Follow-up</button>
                      </>)}
                      {isHr && i.status !== 'waived' && <button className="text-xs text-gray-500 hover:underline mr-2" onClick={() => { setF({ due_date: i.due_date, amount: String(i.amount), assigned_to: i.no_followup ? 'nobody' : i.assigned_to ?? '' }); setAction({ kind: 'edit', inst: i }) }}>Edit</button>}
                      {isHr && i.status !== 'waived' && i.status !== 'paid' && <button className="text-xs text-red-500 hover:underline" onClick={() => { const r = ask('Reason for waiving this installment?'); if (r) run(() => installmentApi.waive(i.id, r), 'Waived.') }}>Waive</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {d.payments.length > 0 && (
            <div className="mt-4">
              <p className="text-xs font-semibold text-gray-600 mb-1">Payments</p>
              <ul className="text-xs divide-y divide-gray-50">
                {d.payments.map((p: any) => (
                  <li key={p.id} className={`py-1 flex items-center gap-2 ${p.reversed_at ? 'text-gray-400 line-through' : ''}`}>
                    <span className="w-24">{day(p.paid_on)}</span><span className="font-medium">{money(p.amount)}</span>{+p.late_fee_amount > 0 && <span>+ {money(p.late_fee_amount)} fee</span>}
                    <span className="text-gray-400">{[p.method, p.reference].filter(Boolean).join(' · ')}</span><span className="text-gray-400 ml-auto">{p.recorded_name}</span>
                    {isHr && !p.reversed_at && <button className="text-red-500 hover:underline no-underline" onClick={() => { const r = ask('Reason for reversing this payment? (its target credit and pending incentive are taken back)'); if (r) run(() => installmentApi.reverse(p.id, r), 'Payment reversed.') }}>Reverse</button>}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <button className="mt-3 text-xs text-gray-500 hover:underline" onClick={() => setShowLog(!showLog)}>{showLog ? 'Hide' : 'Show'} activity log ({d.log.length})</button>
          {showLog && (
            <ul className="mt-2 text-xs text-gray-600 space-y-1">
              {d.log.map((l: any) => (
                <li key={l.id}><span className="text-gray-400">{when(l.created_at)}</span> · <b>{ACTION[l.action] ?? l.action}</b> {l.by ? `by ${l.by}` : ''} <span className="text-gray-500">{l.details_arr?.note || l.details_arr?.reason || (l.details_arr?.amount ? money(l.details_arr.amount) : '')}</span></li>
              ))}
            </ul>
          )}
        </>
      )}

      <Drawer open={!!action} onClose={() => setAction(null)} title={action?.kind === 'pay' ? `Record payment — installment ${action.inst.seq}` : action?.kind === 'followup' ? `Follow-up — installment ${action?.inst.seq}` : `Edit installment ${action?.inst.seq}`} size="sm"
        footer={<><Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
          <Button loading={busy} onClick={() => {
            const id = action!.inst.id
            if (action!.kind === 'pay') run(() => installmentApi.pay(id, { amount: +f.amount, late_fee_amount: f.late_fee_amount ? +f.late_fee_amount : 0, paid_on: f.paid_on, method: f.method || undefined, reference: f.reference || undefined }), 'Payment recorded.')
            else if (action!.kind === 'followup') run(() => installmentApi.followup(id, { note: f.note || undefined, promised_on: f.promised_on || undefined }), 'Follow-up saved.')
            else run(() => installmentApi.edit(id, { due_date: f.due_date, amount: +f.amount, assigned_to: f.assigned_to && f.assigned_to !== 'nobody' ? +f.assigned_to : null, no_followup: f.assigned_to === 'nobody' }), 'Saved.')
          }}>Save</Button></>}>
        {action?.kind === 'pay' && (<div className="space-y-3">
          <Input label={`Amount received (balance ${money(action.inst.balance)})`} type="number" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} />
          {action.inst.fee_due > 0 && <Input label={`Late fee collected (due ${money(action.inst.fee_due)})`} type="number" value={f.late_fee_amount} onChange={(e) => setF({ ...f, late_fee_amount: e.target.value })} />}
          <Input label="Paid on" type="date" value={f.paid_on} onChange={(e) => setF({ ...f, paid_on: e.target.value })} />
          <Input label="Method" placeholder="UPI, cash, card…" value={f.method} onChange={(e) => setF({ ...f, method: e.target.value })} />
          <Input label="Reference" placeholder="Transaction id / receipt no." value={f.reference} onChange={(e) => setF({ ...f, reference: e.target.value })} />
        </div>)}
        {action?.kind === 'followup' && (<div className="space-y-3">
          <Input label="What happened?" placeholder="Called, will pay on Friday…" value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} />
          <Input label="Promised to pay on" type="date" value={f.promised_on} onChange={(e) => setF({ ...f, promised_on: e.target.value })} />
        </div>)}
        {action?.kind === 'edit' && (<div className="space-y-3">
          <Input label="Due date" type="date" value={f.due_date} onChange={(e) => setF({ ...f, due_date: e.target.value })} />
          <Input label="Amount (₹)" type="number" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} />
          <div><label className="block text-xs text-gray-500 mb-1">Follow-up by</label>
            <select className="input" value={f.assigned_to} onChange={(e) => setF({ ...f, assigned_to: e.target.value })}><option value="">Lead owner / default</option><option value="nobody">No one — only HR can act on it</option>{staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
          <p className="text-[11px] text-gray-400">If you change an amount, make sure the installments still add up to the plan total.</p>
        </div>)}
      </Drawer>
    </div>
  )
}
