import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, EmptyState, Input, Modal, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, Stat, StatusPill, useCanManage } from '../shared'
import AccountsPageFrame from '../components/AccountsPageFrame'

const LABEL: Record<string, string> = { ok: 'Within limit', near: 'Near limit', over: 'Over limit', hold: 'On hold', no_limit: 'No limit set' }

/** Customer credit management: limits, payment terms, holds, and who is over or overdue. */
function CreditView() {
  const canManage = useCanManage()
  const [d, setD] = useState<any>(null)
  const [edit, setEdit] = useState<any>(null)
  const [saving, setSaving] = useState(false)
  const load = useCallback(() => { accountsApi.credit().then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [])
  useEffect(() => { load() }, [load])

  const open = async (row: any) => {
    try {
      const l = (await accountsApi.ledgers({ group: 'debtor' })).data.ledgers.find((x: any) => x.id === row.ledger_id)
      setEdit({ ...l, credit_limit: l.credit_limit || '', credit_days: l.credit_days || '' })
    } catch (e) { toast.error(getError(e)) }
  }
  const save = async () => {
    setSaving(true)
    try {
      await accountsApi.updateLedger(edit.id, { name: edit.name, nature: edit.nature, group: edit.group, credit_limit: Number(edit.credit_limit) || 0, credit_days: Number(edit.credit_days) || 0, credit_hold: !!edit.credit_hold })
      toast.success('Credit terms saved.'); setEdit(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  if (!d) return <TableSkeleton rows={5} cols={6} />
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Stat label="Total owed by customers" value={inr(d.total_owing)} />
        <Stat label="Overdue" value={inr(d.total_overdue)} tone={d.total_overdue ? 'text-red-600' : ''} />
        <Stat label="Over their limit" value={d.over} tone={d.over ? 'text-red-600' : ''} />
        <Stat label="On credit hold" value={d.on_hold} tone={d.on_hold ? 'text-amber-600' : ''} />
      </div>
      <div className="card">
        {d.rows.length === 0 ? <EmptyState icon="🏦" title="No customers yet" desc="Add customer ledgers, then set a credit limit and payment terms." /> : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Customer</th><th className="text-right">Limit</th><th className="text-right">Owing</th><th className="w-40">Used</th><th className="text-right">Available</th><th className="text-right">Overdue</th><th>Terms</th><th>Status</th><th /></tr></thead>
            <tbody>{d.rows.map((r: any) => (
              <tr key={r.ledger_id}>
                <td className="font-medium">{r.name}{r.phone && <p className="text-xs text-gray-400 font-normal">{r.phone}</p>}</td>
                <td className="text-right">{r.credit_limit ? inr(r.credit_limit) : '—'}</td>
                <td className="text-right">{inr(r.owing)}</td>
                <td>{r.utilisation === null ? <span className="text-xs text-gray-400">—</span> : (
                  <div className="h-2 bg-gray-100 rounded-full overflow-hidden"><div className={`h-2 ${r.status === 'over' ? 'bg-red-500' : r.status === 'near' ? 'bg-amber-500' : 'bg-green-500'}`} style={{ width: `${Math.min(100, r.utilisation)}%` }} /></div>)}
                  {r.utilisation !== null && <span className="text-xs text-gray-400">{r.utilisation}%</span>}</td>
                <td className="text-right">{r.available === null ? '—' : inr(r.available)}</td>
                <td className={`text-right ${r.overdue ? 'text-red-600 font-medium' : ''}`}>{r.overdue ? <>{inr(r.overdue)}<p className="text-xs font-normal">{r.oldest_overdue_days}d late</p></> : '—'}</td>
                <td className="text-sm">{r.credit_days ? `Net ${r.credit_days}` : 'On delivery'}</td>
                <td><StatusPill status={r.status} label={LABEL[r.status]} /></td>
                <td className="text-right">{canManage && <button className="text-xs text-blue-600 hover:underline" onClick={() => open(r)}>Edit terms</button>}</td>
              </tr>
            ))}</tbody>
          </table></div>
        )}
      </div>
      {edit && (
        <Modal open onClose={() => setEdit(null)} title={`Credit terms — ${edit.name}`}
          footer={<><Button variant="secondary" onClick={() => setEdit(null)}>Cancel</Button><Button onClick={save} loading={saving}>Save</Button></>}>
          <div className="grid sm:grid-cols-2 gap-4">
            <Input label="Credit limit (0 = no limit)" type="number" min="0" value={edit.credit_limit} onChange={e => setEdit({ ...edit, credit_limit: e.target.value })} />
            <Input label="Payment terms (days)" type="number" min="0" max="365" value={edit.credit_days} onChange={e => setEdit({ ...edit, credit_days: e.target.value })} />
            <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={!!edit.credit_hold} onChange={e => setEdit({ ...edit, credit_hold: e.target.checked })} /> Credit hold — block new invoices to this customer</label>
            <p className="text-xs text-gray-500 sm:col-span-2">An invoice that would take the customer past their limit is refused unless it is knowingly overridden; the override is noted on the invoice. Payment terms set each new invoice's due date.</p>
          </div>
        </Modal>
      )}
    </div>
  )
}

/** /accounts/sales/credit */
export default function CustomerCreditPage() {
  return (
    <AccountsPageFrame path="sales/credit">
      <CreditView />
    </AccountsPageFrame>
  )
}
