import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, ConfirmModal, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, monthLabel, Stat, StatusPill, today } from '../shared'

import AccountsPageFrame from '../components/AccountsPageFrame'

// ─── Employees and end-of-service settlement ────────────────────────────────
const blankEmp = () => ({ name: '', code: '', designation: '', joined_on: today(), left_on: '', basic: '', allowance: '', annual_ticket: '', leave_days_per_year: 30, eos_days_per_year: 21, leave_taken_days: '', ticket_used: '' })

function EmployeesView() {
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

/** /accounts/payroll/employees */
export default function EmployeesPage() {
  return (
    <AccountsPageFrame path="payroll/employees">
      <EmployeesView />
    </AccountsPageFrame>
  )
}
