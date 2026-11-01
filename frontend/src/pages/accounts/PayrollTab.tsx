import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, ConfirmModal, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, monthLabel, Stat, StatusPill, SubTabs, today } from './shared'

export default function PayrollTab() {
  const [sub, setSub] = useState('runs')
  return (
    <div className="space-y-3">
      <SubTabs value={sub} onChange={setSub} tabs={[['runs', '🗓️ Monthly payroll'], ['employees', '👥 Employees & settlement']]} />
      {sub === 'runs' ? <Runs /> : <Employees />}
    </div>
  )
}

// ─── Monthly payroll: open → review → post → pay ────────────────────────────
function Runs() {
  const [list, setList] = useState<any>(null)
  const [openId, setOpenId] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => { accountsApi.payrollRuns().then(r => setList(r.data)).catch(e => toast.error(getError(e))) }, [])
  useEffect(() => { load() }, [load])
  const start = async () => {
    setBusy(true)
    try { const { data } = await accountsApi.createPayrollRun(list.next_period); toast.success(data.message); load(); setOpenId(data.run.id) } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  if (openId) return <RunDetail id={openId} onBack={() => { setOpenId(null); load() }} />
  if (!list) return <TableSkeleton rows={4} cols={6} />
  return (
    <div className="card">
      <div className="card-header"><p className="text-sm text-gray-500">Payroll runs month by month, in order — no gaps.</p>
        <div className="ml-auto"><Button onClick={start} loading={busy}>Open {monthLabel(list.next_period)} payroll</Button></div></div>
      {list.rows.length === 0 ? <EmptyState icon="💼" title="No payroll yet" desc="Add employees first, then open the first month." /> : (
        <div className="table-wrapper"><table className="table">
          <thead><tr><th>Month</th><th className="text-right">Gross</th><th className="text-right">Deductions</th><th className="text-right">Net pay</th><th className="text-right">Provisions accrued</th><th>Status</th><th /></tr></thead>
          <tbody>{list.rows.map((r: any) => (
            <tr key={r.id}><td className="font-medium">{monthLabel(r.period)}</td><td className="text-right">{inr(r.total_gross)}</td><td className="text-right text-gray-500">{inr(r.total_deductions)}</td>
              <td className="text-right font-medium">{inr(r.total_net)}</td><td className="text-right text-gray-500">{inr(r.total_provisions)}</td><td><StatusPill status={r.status} /></td>
              <td className="text-right"><button className="text-xs text-blue-600 hover:underline" onClick={() => setOpenId(r.id)}>Open</button></td></tr>
          ))}</tbody>
        </table></div>
      )}
    </div>
  )
}

function RunDetail({ id, onBack }: { id: number; onBack: () => void }) {
  const [d, setD] = useState<any>(null)
  const [banks, setBanks] = useState<any[]>([])
  const [pay, setPay] = useState<any>(null)
  const [del, setDel] = useState(false)
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => { accountsApi.payrollRun(id).then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [id])
  useEffect(() => { load() }, [load])
  useEffect(() => { accountsApi.ledgers({ group: 'bank,cash' }).then(r => setBanks(r.data.ledgers)).catch(() => {}) }, [])
  const act = async (fn: () => Promise<any>) => { setBusy(true); try { const r = await fn(); toast.success(r?.data?.message || 'Done.'); load() } catch (e) { toast.error(getError(e)) } finally { setBusy(false) } }
  const edit = (line: any, k: string, v: string) => setD((x: any) => ({ ...x, run: { ...x.run, lines: x.run.lines.map((l: any) => l.id === line.id ? { ...l, [k]: v } : l) } }))
  const commit = async (line: any) => {
    try { const r = await accountsApi.updatePayrollLine(id, line.id, { commission: Number(line.commission) || 0, unpaid_days: Number(line.unpaid_days) || 0, other_deductions: Number(line.other_deductions) || 0 }); setD((x: any) => ({ ...x, run: r.data.run })) }
    catch (e) { toast.error(getError(e)); load() }
  }
  const slips = () => {
    const w = window.open('', '_blank', 'width=800,height=1000'); if (!w) return
    const esc = (s: any) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c] as string))
    const co = d.company
    w.document.write(`<!doctype html><html><head><title>Payslips ${esc(run.period)}</title><style>body{font:13px Arial;padding:24px}.s{page-break-after:always;border:1px solid #ccc;padding:18px;margin-bottom:20px}table{width:100%;border-collapse:collapse}td{padding:5px 8px;border-bottom:1px solid #eee}.r{text-align:right}h2{margin:0}</style></head><body>` +
      run.lines.map((l: any) => `<div class="s"><h2>${esc(co?.legal_name || 'Payslip')}</h2><p>Payslip — ${monthLabel(run.period)}<br><b>${esc(l.employee?.name)}</b> ${esc(l.employee?.designation || '')}</p><table>
        <tr><td>Basic</td><td class="r">${inr(l.basic)}</td></tr><tr><td>Allowances</td><td class="r">${inr(l.allowance)}</td></tr><tr><td>Commission</td><td class="r">${inr(l.commission)}</td></tr>
        <tr><td>Unpaid leave (${l.unpaid_days} days)</td><td class="r">− ${inr(l.unpaid_deduction)}</td></tr><tr><td>Other deductions</td><td class="r">− ${inr(l.other_deductions)}</td></tr>
        <tr><td><b>Net pay</b></td><td class="r"><b>${inr(l.net)}</b></td></tr></table></div>`).join('') + '<script>window.onload=()=>window.print()</script></body></html>')
    w.document.close()
  }
  if (!d) return <TableSkeleton rows={4} cols={6} />
  const run = d.run; const draft = run.status === 'draft'
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        <button className="text-sm text-blue-600 hover:underline" onClick={onBack}>← All months</button>
        <h3 className="font-semibold">{monthLabel(run.period)} payroll</h3><StatusPill status={run.status} />
        <div className="ml-auto flex flex-wrap gap-2">
          <Button variant="secondary" onClick={slips}>Payslips</Button>
          {draft && <><Button variant="secondary" onClick={() => act(() => accountsApi.refreshPayrollRun(id))}>Refresh from employees</Button>
            <Button variant="secondary" onClick={() => setDel(true)}>Delete</Button>
            <Button loading={busy} onClick={() => act(() => accountsApi.postPayrollRun(id))}>Post payroll</Button></>}
          {run.status === 'posted' && <><Button variant="secondary" loading={busy} onClick={() => act(() => accountsApi.unpostPayrollRun(id))}>Take back to draft</Button>
            <Button onClick={() => setPay({ account_ledger_id: '', date: today() })}>Pay salaries</Button></>}
          {run.status === 'paid' && <Button variant="secondary" loading={busy} onClick={() => act(() => accountsApi.unpayPayrollRun(id))}>Reverse payment</Button>}
        </div>
      </div>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Stat label="Gross" value={inr(run.total_gross)} /><Stat label="Deductions" value={inr(run.total_deductions)} /><Stat label="Net pay" value={inr(run.total_net)} tone="text-green-600" /><Stat label="Provisions accrued" value={inr(run.total_provisions)} sub="leave · ticket · end of service" />
      </div>
      <div className="card"><div className="table-wrapper"><table className="table">
        <thead><tr><th>Employee</th><th className="text-right">Basic</th><th className="text-right">Allowance</th><th className="text-right w-28">Commission</th><th className="text-right w-24">Unpaid days</th><th className="text-right w-28">Other deductions</th><th className="text-right">Net</th><th className="text-right">Provisions</th></tr></thead>
        <tbody>{run.lines.map((l: any) => (
          <tr key={l.id}><td className="font-medium">{l.employee?.name}<p className="text-xs text-gray-400 font-normal">{l.employee?.designation}</p></td>
            <td className="text-right">{inr(l.basic)}</td><td className="text-right">{inr(l.allowance)}</td>
            {(['commission', 'unpaid_days', 'other_deductions'] as const).map(k => (
              <td key={k} className="text-right">{draft ? <input className="input !py-1 text-right" type="number" min="0" value={l[k]} onChange={e => edit(l, k, e.target.value)} onBlur={() => commit(l)} /> : (k === 'unpaid_days' ? l[k] : inr(l[k]))}</td>))}
            <td className="text-right font-medium">{inr(l.net)}</td><td className="text-right text-gray-500 text-xs">{inr(l.leave_accrual + l.ticket_accrual + l.eos_accrual)}</td></tr>
        ))}</tbody>
      </table></div></div>
      {run.paid_on && <p className="text-xs text-gray-500">Paid on {String(run.paid_on).slice(0, 10)}.</p>}
      {pay && (
        <Modal open onClose={() => setPay(null)} title={`Pay ${monthLabel(run.period)} salaries — ${inr(run.total_net)}`}
          footer={<><Button variant="secondary" onClick={() => setPay(null)}>Cancel</Button><Button loading={busy} disabled={!pay.account_ledger_id} onClick={async () => { await act(() => accountsApi.payPayrollRun(id, pay)); setPay(null) }}>Pay</Button></>}>
          <div className="grid sm:grid-cols-2 gap-4">
            <Select label="Pay from" value={pay.account_ledger_id} onChange={e => setPay({ ...pay, account_ledger_id: e.target.value })} options={[{ value: '', label: 'Select bank / cash…' }, ...banks.map(b => ({ value: b.id, label: b.name }))]} />
            <Input label="Payment date" type="date" value={pay.date} onChange={e => setPay({ ...pay, date: e.target.value })} />
          </div>
        </Modal>
      )}
      <ConfirmModal open={del} title="Delete draft payroll?" message="The month can be opened again afterwards." onConfirm={async () => { try { await accountsApi.deletePayrollRun(id); toast.success('Deleted.'); onBack() } catch (e) { toast.error(getError(e)) } setDel(false) }} onCancel={() => setDel(false)} />
    </div>
  )
}

// ─── Employees and end-of-service settlement ────────────────────────────────
const blankEmp = () => ({ name: '', code: '', designation: '', joined_on: today(), left_on: '', basic: '', allowance: '', annual_ticket: '', leave_days_per_year: 30, eos_days_per_year: 21, leave_taken_days: '', ticket_used: '' })

function Employees() {
  const [d, setD] = useState<any>(null)
  const [form, setForm] = useState<any>(null)
  const [settle, setSettle] = useState<any>(null)
  const [banks, setBanks] = useState<any[]>([])
  const [del, setDel] = useState<any>(null)
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => { accountsApi.employees().then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { accountsApi.ledgers({ group: 'bank,cash' }).then(r => setBanks(r.data.ledgers)).catch(() => {}) }, [])
  const set = (k: string, v: any) => setForm((f: any) => ({ ...f, [k]: v }))
  const num = (v: any) => (v === '' || v == null ? 0 : Number(v))

  const save = async () => {
    setBusy(true)
    try {
      const body = { ...form, left_on: form.left_on || null, basic: num(form.basic), allowance: num(form.allowance), annual_ticket: num(form.annual_ticket), leave_taken_days: num(form.leave_taken_days), ticket_used: num(form.ticket_used) }
      form.id ? await accountsApi.updateEmployee(form.id, body) : await accountsApi.createEmployee(body)
      toast.success('Saved.'); setForm(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const openSettle = async (e: any) => { try { setSettle({ emp: e, q: (await accountsApi.settlementQuote(e.id)).data, account_ledger_id: '' }) } catch (er) { toast.error(getError(er)) } }
  const doSettle = async () => {
    setBusy(true)
    try { const { data } = await accountsApi.settleEmployee(settle.emp.id, { date: settle.q.date, account_ledger_id: settle.account_ledger_id || null }); toast.success(data.message); setSettle(null); load() }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  if (!d) return <TableSkeleton rows={4} cols={6} />
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
        <Stat label="Active employees" value={d.active} /><Stat label="Monthly gross" value={inr(d.monthly_gross)} /><Stat label="Provisions held" value={inr(d.provisions_held)} sub="leave · ticket · end of service" />
      </div>
      <div className="card">
        <div className="card-header"><div className="ml-auto"><Button onClick={() => setForm(blankEmp())}>+ Add employee</Button></div></div>
        {d.rows.length === 0 ? <EmptyState icon="👥" title="No employees" /> : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Employee</th><th>Joined</th><th className="text-right">Basic</th><th className="text-right">Allowance</th><th className="text-right">End-of-service held</th><th className="text-right">Leave held</th><th className="text-right">Ticket held</th><th>Status</th><th /></tr></thead>
            <tbody>{d.rows.map((e: any) => (
              <tr key={e.id}><td className="font-medium">{e.name}<p className="text-xs text-gray-400 font-normal">{[e.code, e.designation].filter(Boolean).join(' · ')}</p></td>
                <td className="text-sm">{String(e.joined_on).slice(0, 10)}{e.left_on && <p className="text-xs text-gray-400">to {String(e.left_on).slice(0, 10)}</p>}</td>
                <td className="text-right">{inr(e.basic)}</td><td className="text-right">{inr(e.allowance)}</td>
                <td className="text-right text-gray-500">{inr(e.accrued.eos)}</td><td className="text-right text-gray-500">{inr(e.accrued.leave)}</td><td className="text-right text-gray-500">{inr(e.accrued.ticket)}</td>
                <td><StatusPill status={e.status} />{e.settlement_amount != null && <p className="text-xs text-gray-400">settled {inr(e.settlement_amount)}</p>}</td>
                <td className="text-right whitespace-nowrap text-xs space-x-2">
                  {!e.settled_on && <button className="text-gray-600 hover:underline" onClick={() => setForm({ ...e, left_on: e.left_on ? String(e.left_on).slice(0, 10) : '', joined_on: String(e.joined_on).slice(0, 10) })}>Edit</button>}
                  {!e.settled_on && <button className="text-purple-700 hover:underline" onClick={() => openSettle(e)}>Settle & leave</button>}
                  {!e.settled_on && <button className="text-red-500 hover:underline" onClick={() => setDel(e)}>Delete</button>}</td></tr>
            ))}</tbody>
          </table></div>
        )}
      </div>
      {form && (
        <Modal open onClose={() => setForm(null)} size="lg" title={form.id ? 'Edit employee' : 'Add employee'} footer={<><Button variant="secondary" onClick={() => setForm(null)}>Cancel</Button><Button onClick={save} loading={busy}>Save</Button></>}>
          <div className="grid sm:grid-cols-2 gap-4">
            <Input label="Name" value={form.name} onChange={e => set('name', e.target.value)} />
            <Input label="Employee code" value={form.code || ''} onChange={e => set('code', e.target.value)} />
            <Input label="Designation" value={form.designation || ''} onChange={e => set('designation', e.target.value)} />
            <Input label="Joining date" type="date" value={form.joined_on} onChange={e => set('joined_on', e.target.value)} />
            <Input label="Monthly basic" type="number" min="0" value={form.basic} onChange={e => set('basic', e.target.value)} />
            <Input label="Monthly allowances" type="number" min="0" value={form.allowance} onChange={e => set('allowance', e.target.value)} />
            <Input label="Annual ticket allowance" type="number" min="0" value={form.annual_ticket} onChange={e => set('annual_ticket', e.target.value)} />
            <Input label="Paid leave days per year" type="number" min="0" value={form.leave_days_per_year} onChange={e => set('leave_days_per_year', e.target.value)} />
            <Input label="End-of-service days of basic per year" type="number" min="0" value={form.eos_days_per_year} onChange={e => set('eos_days_per_year', e.target.value)} />
            <Input label="Leave days already taken" type="number" min="0" value={form.leave_taken_days} onChange={e => set('leave_taken_days', e.target.value)} />
            <Input label="Last working day (set before the final payroll)" type="date" value={form.left_on || ''} onChange={e => set('left_on', e.target.value)} />
            <p className="text-xs text-gray-500 sm:col-span-2">Each month the system accrues leave salary, annual ticket and end-of-service provisions on top of the salary. On settlement the provisions are paid out and any difference is trued up through expense.</p>
          </div>
        </Modal>
      )}
      {settle && (
        <Modal open onClose={() => setSettle(null)} title={`Final settlement — ${settle.emp.name}`}
          footer={<><Button variant="secondary" onClick={() => setSettle(null)}>Cancel</Button><Button onClick={doSettle} loading={busy} disabled={!settle.q.can_settle || (settle.q.total_due > 0 && !settle.account_ledger_id)}>Settle & mark as left</Button></>}>
          <div className="space-y-3 text-sm">
            <p>Last working day <b>{settle.q.date}</b> · {settle.q.years_of_service} years of service</p>
            {settle.q.problems.map((p: string) => <p key={p} className="text-red-600 bg-red-50 rounded p-2">{p}</p>)}
            <table className="table"><thead><tr><th /><th className="text-right">Due</th><th className="text-right">Provision held</th></tr></thead><tbody>
              <tr><td>End-of-service gratuity</td><td className="text-right">{inr(settle.q.due.eos)}</td><td className="text-right text-gray-500">{inr(settle.q.provision_held.eos)}</td></tr>
              <tr><td>Leave salary ({settle.q.leave_days_balance} days)</td><td className="text-right">{inr(settle.q.due.leave)}</td><td className="text-right text-gray-500">{inr(settle.q.provision_held.leave)}</td></tr>
              <tr><td>Annual ticket</td><td className="text-right">{inr(settle.q.due.ticket)}</td><td className="text-right text-gray-500">{inr(settle.q.provision_held.ticket)}</td></tr>
              <tr className="font-semibold"><td>Total to pay</td><td className="text-right">{inr(settle.q.total_due)}</td><td /></tr></tbody></table>
            {settle.q.total_due > 0 && <Select label="Pay from" value={settle.account_ledger_id} onChange={e => setSettle({ ...settle, account_ledger_id: e.target.value })} options={[{ value: '', label: 'Select bank / cash…' }, ...banks.map(b => ({ value: b.id, label: b.name }))]} />}
          </div>
        </Modal>
      )}
      <ConfirmModal open={!!del} title="Delete employee?" message={`Delete ${del?.name}?`} onConfirm={async () => { try { await accountsApi.deleteEmployee(del.id); toast.success('Deleted.'); load() } catch (e) { toast.error(getError(e)) } setDel(null) }} onCancel={() => setDel(null)} />
    </div>
  )
}
