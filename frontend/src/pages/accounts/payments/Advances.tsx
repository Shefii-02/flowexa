import { Fragment, useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, Stat, StatusPill, SubTabs, today, useRange, useCanManage } from '../shared'
import VoucherForm, { blankVoucher } from '../vouchers/VoucherForm'

/** Unapplied advances. `fixedKind` shows just customers' or just suppliers' (its own sidebar page). */
export function Advances({ fixedKind }: { fixedKind?: 'receivable' | 'payable' }) {
  const canManage = useCanManage()
  const [kind, setKind] = useState<'receivable' | 'payable'>(fixedKind ?? 'receivable')
  const [d, setD] = useState<any>(null)
  const [apply, setApply] = useState<any>(null)
  const [docs, setDocs] = useState<any[]>([])
  const [applied, setApplied] = useState<Record<number, any[]>>({})
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => { setD(null); accountsApi.advances(kind).then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [kind])
  useEffect(() => { load() }, [load])

  const openApply = async (a: any) => {
    try {
      const r = await accountsApi.paymentDocs(kind, { party: a.party_id, status: 'open' })
      setDocs(r.data.rows); setApply({ advance: a, invoice_id: r.data.rows[0]?.voucher_id || '', amount: '', date: today() })
    } catch (e) { toast.error(getError(e)) }
  }
  const submit = async () => {
    setSaving(true)
    try { await accountsApi.applyAdvance({ advance_id: apply.advance.voucher_id, invoice_id: apply.invoice_id, amount: Number(apply.amount), date: apply.date }); toast.success('Advance applied.'); setApply(null); load() }
    catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }
  const showApplied = async (id: number) => {
    try { const v = (await accountsApi.voucher(id)).data.voucher; setApplied(m => ({ ...m, [id]: (v.allocations || []).filter((x: any) => x.journal_voucher_id) })) } catch (e) { toast.error(getError(e)) }
  }
  const undo = async (adv: number, allocId: number) => {
    try { await accountsApi.unapplyAdvance(allocId); toast.success('Reversed.'); showApplied(adv); load() } catch (e) { toast.error(getError(e)) }
  }
  const cur = docs.find(x => String(x.voucher_id) === String(apply?.invoice_id))

  return (
    <div className="space-y-3">
      {!fixedKind && <SubTabs value={kind} onChange={k => setKind(k as any)} tabs={[['receivable', 'Received from customers'], ['payable', 'Paid to suppliers']]} />}
      <div className="card">
        <div className="card-header"><p className="text-sm text-gray-500">{kind === 'receivable' ? 'Money received before an invoice exists. It sits in Customer Advances until you apply it.' : 'Money paid to suppliers before their bill arrived. It sits in Advances to Suppliers until you apply it.'}
          {d && <b className="ml-2">{inr(d.total)} unapplied</b>}</p></div>
        {!d ? <TableSkeleton rows={3} cols={5} /> : d.rows.length === 0 ? <EmptyState icon="💼" title="No unapplied advances" desc='Record one with a Receipt/Payment voucher and tick "Advance".' /> : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Voucher</th><th>Party</th><th className="text-right">Amount</th><th className="text-right">Applied</th><th className="text-right">Remaining</th><th /></tr></thead>
            <tbody>{d.rows.map((a: any) => (
              <Fragment key={a.voucher_id}>
                <tr><td>{a.number}<p className="text-xs text-gray-400">{a.date}</p></td><td>{a.party}</td><td className="text-right">{inr(a.amount)}</td>
                  <td className="text-right">{a.applied ? <button className="underline text-gray-600" onClick={() => showApplied(a.voucher_id)}>{inr(a.applied)}</button> : '—'}</td>
                  <td className="text-right font-medium">{inr(a.remaining)}</td>
                  <td className="text-right">{canManage && <button className="text-xs text-blue-600 hover:underline" onClick={() => openApply(a)}>Apply to {kind === 'receivable' ? 'invoice' : 'bill'}</button>}</td></tr>
                {(applied[a.voucher_id] || []).map(x => (
                  <tr key={`x${x.id}`} className="bg-gray-50 text-xs"><td colSpan={4} className="pl-8">↳ {inr(x.amount)} applied to {x.invoice_number} on {String(x.date).slice(0, 10)}</td><td colSpan={2} className="text-right">{canManage && <button className="text-red-500 hover:underline" onClick={() => undo(a.voucher_id, x.id)}>Undo</button>}</td></tr>
                ))}
              </Fragment>
            ))}</tbody>
          </table></div>
        )}
      </div>
      {apply && (
        <Modal open onClose={() => setApply(null)} title={`Apply advance ${apply.advance.number} — ${apply.advance.party}`}
          footer={<><Button variant="secondary" onClick={() => setApply(null)}>Cancel</Button><Button onClick={submit} loading={saving} disabled={!apply.invoice_id || !Number(apply.amount)}>Apply</Button></>}>
          {docs.length === 0 ? <p className="text-sm text-gray-500">This party has no open {kind === 'receivable' ? 'invoices' : 'bills'} to apply it to.</p> : (
            <div className="grid sm:grid-cols-2 gap-4">
              <Select label={kind === 'receivable' ? 'Invoice' : 'Bill'} value={apply.invoice_id} onChange={e => setApply({ ...apply, invoice_id: e.target.value })}
                options={docs.map(x => ({ value: x.voucher_id, label: `${x.number} — ${inr(x.outstanding)} outstanding` }))} />
              <Input label={`Amount (max ${inr(Math.min(apply.advance.remaining, cur?.outstanding ?? 0))})`} type="number" min="0" value={apply.amount} onChange={e => setApply({ ...apply, amount: e.target.value })} />
              <Input label="Date" type="date" value={apply.date} onChange={e => setApply({ ...apply, date: e.target.value })} />
              <p className="text-xs text-gray-500 sm:col-span-2">Posts an adjusting journal: {kind === 'receivable' ? 'Dr Customer Advances / Cr Customer' : 'Dr Supplier / Cr Advances to Suppliers'}.</p>
            </div>
          )}
        </Modal>
      )}
    </div>
  )
}
