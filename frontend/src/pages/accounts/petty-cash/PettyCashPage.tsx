import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, Stat, today, useRange, useCanManage } from '../shared'
import AccountsPageFrame from '../components/AccountsPageFrame'

/** Imprest petty cash: a fixed float, topped up from the bank, spent on small expenses, replenished back to the float. */
function PettyCashView() {
  const canManage = useCanManage()
  const [range, setRange] = useRange()
  const [d, setD] = useState<any>(null)
  const [ledgers, setLedgers] = useState<any[]>([])
  const [modal, setModal] = useState<any>(null)
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => { accountsApi.pettyCash(range).then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [range])
  useEffect(() => { load() }, [load])
  useEffect(() => { accountsApi.ledgers().then(r => setLedgers(r.data.ledgers)).catch(() => {}) }, [])
  const banks = ledgers.filter(l => ['bank', 'cash'].includes(l.group) && l.id !== d?.ledger_id)

  const submit = async () => {
    setBusy(true)
    try {
      const m = modal
      const { data } = m.kind === 'spend'
        ? await accountsApi.pettySpend({ date: m.date, ledger_id: m.ledger_id, amount: Number(m.amount), description: m.description, gst_rate: Number(m.gst_rate) || 0 })
        : await accountsApi.pettyTopUp({ date: m.date, from_ledger_id: m.from_ledger_id, amount: m.amount ? Number(m.amount) : undefined })
      toast.success(data.message); setModal(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  if (!d) return <TableSkeleton rows={4} cols={5} />
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Stat label="Cash in the box" value={inr(d.balance)} tone={d.low ? 'text-amber-600' : 'text-green-600'} sub={d.low ? 'running low — replenish' : undefined} />
        <Stat label="Float (imprest)" value={d.float_limit ? inr(d.float_limit) : 'Not set'} sub={d.float_limit ? undefined : 'set it in Settings'} />
        <Stat label="To replenish" value={inr(d.replenish_needed)} />
        <Stat label="Spent this period" value={inr(d.spent)} />
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <RangePicker range={range} onChange={setRange} />
        {canManage && <div className="ml-auto flex gap-2">
          <Button variant="secondary" onClick={() => setModal({ kind: 'topup', date: today(), from_ledger_id: '', amount: d.replenish_needed || '' })}>＋ Top up / replenish</Button>
          <Button onClick={() => setModal({ kind: 'spend', date: today(), ledger_id: '', amount: '', description: '', gst_rate: 0 })}>− Spend</Button>
        </div>}
      </div>
      <div className="grid lg:grid-cols-3 gap-3">
        <div className="card lg:col-span-2">
          {d.rows.length === 0 ? <EmptyState icon="🪙" title="No petty cash movements" desc="Top up the float from the bank, then record small expenses against it." /> : (
            <div className="table-wrapper"><table className="table">
              <thead><tr><th>Date</th><th>Voucher</th><th>Note</th><th className="text-right">In</th><th className="text-right">Out</th><th className="text-right">Balance</th></tr></thead>
              <tbody><tr className="text-gray-500"><td colSpan={5}>Opening balance</td><td className="text-right">{inr(d.opening)}</td></tr>
                {d.rows.map((r: any, i: number) => <tr key={i}><td className="text-sm">{r.date}</td><td>{r.number}</td><td className="text-xs text-gray-500">{r.narration}</td>
                  <td className="text-right text-green-600">{r.debit ? inr(r.debit) : ''}</td><td className="text-right text-red-600">{r.credit ? inr(r.credit) : ''}</td><td className="text-right">{inr(r.balance)}</td></tr>)}</tbody>
            </table></div>
          )}
        </div>
        <div className="card"><div className="card-header"><h4 className="text-sm font-semibold">Where it went</h4></div>
          <div className="card-body space-y-2 text-sm">{d.spent_by_category.length === 0 ? <p className="text-gray-400">Nothing spent.</p> : d.spent_by_category.map((c: any) => (
            <div key={c.name} className="flex justify-between"><span>{c.name}</span><span>{inr(c.amount)}</span></div>))}</div></div>
      </div>
      {modal && (
        <Modal open onClose={() => setModal(null)} title={modal.kind === 'spend' ? 'Pay from petty cash' : 'Top up petty cash'}
          footer={<><Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button><Button onClick={submit} loading={busy}>{modal.kind === 'spend' ? 'Record expense' : 'Transfer'}</Button></>}>
          <div className="grid sm:grid-cols-2 gap-4">
            <Input label="Date" type="date" value={modal.date} onChange={e => setModal({ ...modal, date: e.target.value })} />
            <Input label={modal.kind === 'topup' ? 'Amount (blank = back to the float)' : 'Amount'} type="number" min="0" value={modal.amount} onChange={e => setModal({ ...modal, amount: e.target.value })} />
            {modal.kind === 'spend' ? <>
              <Select label="Expense head" value={modal.ledger_id} onChange={e => setModal({ ...modal, ledger_id: e.target.value })} options={[{ value: '', label: 'Select…' }, ...ledgers.filter(l => l.nature === 'expense').map(l => ({ value: l.id, label: l.name }))]} />
              <Input label="What for" value={modal.description} onChange={e => setModal({ ...modal, description: e.target.value })} />
              <p className="text-xs text-gray-500 sm:col-span-2">Petty cash can't go below zero — top it up first if it is short.</p>
            </> : <Select label="Take from" value={modal.from_ledger_id} onChange={e => setModal({ ...modal, from_ledger_id: e.target.value })} options={[{ value: '', label: 'Select bank / cash…' }, ...banks.map(l => ({ value: l.id, label: l.name }))]} />}
          </div>
        </Modal>
      )}
    </div>
  )
}

/** /accounts/petty-cash */
export default function PettyCashPage() {
  return (
    <AccountsPageFrame path="petty-cash">
      <PettyCashView />
    </AccountsPageFrame>
  )
}
