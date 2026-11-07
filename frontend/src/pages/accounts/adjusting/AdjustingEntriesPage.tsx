import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, StatusPill, today, useRange, useCanManage } from '../shared'
import AccountsPageFrame from '../components/AccountsPageFrame'

const KINDS: Record<string, { icon: string; title: string; hint: string; needs: 'expense' | 'income' | 'account' | 'both'; reverse?: boolean }> = {
  accrued_expense:    { icon: '🧾', title: 'Accrued expense', hint: 'Expense incurred but the bill has not arrived. Dr Expense / Cr Accrued Expenses.', needs: 'expense', reverse: true },
  prepaid_payment:    { icon: '📆', title: 'Prepaid expense paid', hint: 'Rent, insurance or subscriptions paid upfront. Dr Prepaid Expenses / Cr Cash-Bank.', needs: 'account' },
  prepaid_amortize:   { icon: '⏳', title: 'Use up prepaid expense', hint: 'Move this period\'s share of a prepaid expense into expense. Dr Expense / Cr Prepaid Expenses.', needs: 'expense' },
  unearned_receive:   { icon: '💵', title: 'Unearned revenue received', hint: 'Paid for services you have not delivered yet. Dr Cash-Bank / Cr Unearned Revenue.', needs: 'account' },
  unearned_recognize: { icon: '✅', title: 'Recognise earned revenue', hint: 'Service delivered. Dr Unearned Revenue / Cr Revenue.', needs: 'income' },
}

/** Adjusting entries (accruals, prepaids, deferrals) without having to remember which side each one goes on. */
function AdjustingView() {
  const canManage = useCanManage()
  const [range, setRange] = useRange()
  const [d, setD] = useState<any>(null)
  const [ledgers, setLedgers] = useState<any[]>([])
  const [form, setForm] = useState<any>(null)
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => { setD(null); accountsApi.adjusting(range).then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [range])
  useEffect(() => { load() }, [load])
  useEffect(() => { accountsApi.ledgers().then(r => setLedgers(r.data.ledgers)).catch(() => {}) }, [])

  const k = form && KINDS[form.kind]
  const save = async () => {
    setBusy(true)
    try {
      const { data } = await accountsApi.createAdjusting({ kind: form.kind, date: form.date, amount: Number(form.amount), ledger_id: form.ledger_id || null, account_ledger_id: form.account_ledger_id || null,
        auto_reverse: !!form.auto_reverse, narration: form.narration || null })
      toast.success(data.message); setForm(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const cancelRev = async (id: number) => { try { await accountsApi.cancelReversal(id); toast.success('Auto-reversal cancelled.'); load() } catch (e) { toast.error(getError(e)) } }

  return (
    <div className="space-y-3">
      {canManage && <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        {Object.entries(KINDS).map(([key, v]) => (
          <button key={key} className="card text-left hover:border-brand-300 transition" onClick={() => setForm({ kind: key, date: today(), amount: '', ledger_id: '', account_ledger_id: '', auto_reverse: !!v.reverse, narration: '' })}>
            <div className="card-body py-3"><p className="font-medium text-sm">{v.icon} {v.title}</p><p className="text-xs text-gray-500 mt-1">{v.hint}</p></div>
          </button>
        ))}
      </div>}
      <RangePicker range={range} onChange={setRange} />
      <div className="card">
        {!d ? <TableSkeleton rows={4} cols={5} /> : d.rows.length === 0 ? <EmptyState icon="🔧" title="No adjusting entries in this period" desc="Depreciation, payroll provisions and the entries above appear here." /> : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th className="text-right">Amount</th><th>Reversal</th></tr></thead>
            <tbody>{d.rows.map((r: any) => (
              <tr key={r.id}><td className="text-sm">{r.date}</td><td className="font-medium">{r.number}{r.source_type && <p className="text-xs text-gray-400 font-normal">{r.source_type.replace('_', ' ')}</p>}</td>
                <td className="text-sm text-gray-600">{r.narration}</td><td className="text-right">{inr(r.total)}</td>
                <td className="text-xs">{r.reversed_by ? <span className="text-gray-500">Reversed by {r.reversed_by}</span> : r.reversal_pending ? <span>Reverses {r.reverse_on} {canManage && <button className="text-red-500 hover:underline ml-1" onClick={() => cancelRev(r.id)}>cancel</button>}</span> : r.reverse_on ? <StatusPill status="open" label="scheduled" /> : '—'}</td></tr>
            ))}</tbody>
          </table></div>
        )}
      </div>
      {form && k && (
        <Modal open onClose={() => setForm(null)} title={`${k.icon} ${k.title}`} footer={<><Button variant="secondary" onClick={() => setForm(null)}>Cancel</Button><Button onClick={save} loading={busy}>Post</Button></>}>
          <div className="grid sm:grid-cols-2 gap-4">
            <p className="text-xs text-gray-500 sm:col-span-2">{k.hint}</p>
            <Input label="Date" type="date" value={form.date} onChange={e => setForm({ ...form, date: e.target.value })} />
            <Input label="Amount" type="number" min="0" value={form.amount} onChange={e => setForm({ ...form, amount: e.target.value })} />
            {k.needs === 'expense' && <Select label="Expense account" value={form.ledger_id} onChange={e => setForm({ ...form, ledger_id: e.target.value })} options={[{ value: '', label: 'Select…' }, ...ledgers.filter(l => l.nature === 'expense').map(l => ({ value: l.id, label: l.name }))]} />}
            {k.needs === 'income' && <Select label="Revenue account" value={form.ledger_id} onChange={e => setForm({ ...form, ledger_id: e.target.value })} options={[{ value: '', label: 'Select…' }, ...ledgers.filter(l => l.nature === 'income').map(l => ({ value: l.id, label: l.name }))]} />}
            {k.needs === 'account' && <Select label={form.kind === 'prepaid_payment' ? 'Paid from' : 'Received in'} value={form.account_ledger_id} onChange={e => setForm({ ...form, account_ledger_id: e.target.value })}
              options={[{ value: '', label: 'Select…' }, ...ledgers.filter(l => ['cash', 'bank', ...(form.kind === 'prepaid_payment' ? ['creditor'] : [])].includes(l.group)).map(l => ({ value: l.id, label: l.name }))]} />}
            <div className="sm:col-span-2"><Input label="Note (optional)" value={form.narration} onChange={e => setForm({ ...form, narration: e.target.value })} /></div>
            {k.reverse && <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={form.auto_reverse} onChange={e => setForm({ ...form, auto_reverse: e.target.checked })} /> Reverse automatically on the next day (so the real bill can be booked normally)</label>}
          </div>
        </Modal>
      )}
    </div>
  )
}

/** /accounts/adjusting */
export default function AdjustingEntriesPage() {
  return (
    <AccountsPageFrame path="adjusting">
      <AdjustingView />
    </AccountsPageFrame>
  )
}
