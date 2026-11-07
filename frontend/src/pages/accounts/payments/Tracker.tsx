import { Fragment, useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, Stat, StatusPill, SubTabs, today, useRange, useCanManage } from '../shared'
import VoucherForm, { blankVoucher } from '../vouchers/VoucherForm'

const BUCKETS: [string, string][] = [['current', 'Not due'], ['d1_30', '1–30'], ['d31_60', '31–60'], ['d61_90', '61–90'], ['d90p', '90+']]

/** Invoice-by-invoice tracker. `fixedView` shows just Documents or just Ageing (its own sidebar page). */
export function Tracker({ kind, fixedView }: { kind: 'receivable' | 'payable'; fixedView?: 'invoices' | 'ageing' }) {
  const canManage = useCanManage()
  const [view, setView] = useState<'invoices' | 'ageing'>(fixedView ?? 'invoices')
  const [status, setStatus] = useState('open')
  const [search, setSearch] = useState('')
  const [d, setD] = useState<any>(null)
  const [ageing, setAgeing] = useState<any>(null)
  const [ledgers, setLedgers] = useState<any[]>([])
  const [catalog, setCatalog] = useState<any[]>([])
  const [company, setCompany] = useState<any>(null)
  const [form, setForm] = useState<any>(null)
  const recv = kind === 'receivable'

  const load = useCallback(() => {
    setD(null); accountsApi.paymentDocs(kind, { status, search: search || undefined }).then(r => setD(r.data)).catch(e => toast.error(getError(e)))
    if (view === 'ageing') { setAgeing(null); accountsApi.ageing(kind).then(r => setAgeing(r.data)).catch(e => toast.error(getError(e))) }
  }, [kind, status, search, view])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    accountsApi.ledgers().then(r => setLedgers(r.data.ledgers)).catch(() => {})
    accountsApi.items().then(r => setCatalog(r.data.items)).catch(() => {})
    accountsApi.settings().then(r => setCompany(r.data.settings)).catch(() => {})
  }, [])

  const settle = (row: any) => setForm({ ...blankVoucher(recv ? 'receipt' : 'payment'), party_ledger_id: row.party_id, amount: row.outstanding, alloc: { [row.voucher_id]: row.outstanding }, reference_no: '' })

  return (
    <div className="space-y-3">
      {d && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <Stat label={recv ? 'To receive' : 'To pay'} value={inr(d.total_outstanding)} sub={`${d.count_open} open ${recv ? 'invoices' : 'bills'}`} />
          <Stat label="Overdue" value={inr(d.total_overdue)} tone={d.total_overdue ? 'text-red-600' : ''} />
          <Stat label="Not yet due" value={inr(d.total_outstanding - d.total_overdue)} />
          <Stat label="As on" value={d.as_on} />
        </div>
      )}
      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          {!fixedView && <SubTabs value={view} onChange={k => setView(k as any)} tabs={[['invoices', 'Documents'], ['ageing', 'Ageing']]} />}
          {view === 'invoices' && <>
            <select className="select max-w-[150px]" value={status} onChange={e => setStatus(e.target.value)}>
              {[['open', 'All outstanding'], ['overdue', 'Overdue'], ['partial', 'Partly paid'], ['paid', 'Paid'], ['all', 'Everything']].map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
            <input className="input max-w-[200px]" placeholder="Search number / ref…" value={search} onChange={e => setSearch(e.target.value)} />
          </>}
        </div>
        {view === 'invoices' ? (!d ? <TableSkeleton rows={5} cols={7} /> : d.rows.length === 0 ? <EmptyState icon="✅" title="Nothing here" desc={recv ? 'No invoices match.' : 'No bills match.'} /> : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Document</th><th>{recv ? 'Customer' : 'Supplier'}</th><th>Due</th><th className="text-right">Total</th><th className="text-right">Settled</th><th className="text-right">Outstanding</th><th>Status</th><th /></tr></thead>
            <tbody>{d.rows.map((r: any) => (
              <tr key={r.voucher_id}>
                <td><p className="font-medium">{r.number}</p><p className="text-xs text-gray-400">{r.date}{r.reference_no ? ` · ${r.reference_no}` : ''}</p></td>
                <td className="text-sm">{r.party}</td>
                <td className={`text-sm ${r.days_overdue ? 'text-red-600' : ''}`}>{r.due_date}{r.days_overdue ? <p className="text-xs">{r.days_overdue} days late</p> : null}</td>
                <td className="text-right">{inr(r.total)}</td><td className="text-right text-gray-500">{r.settled ? inr(r.settled) : '—'}</td>
                <td className="text-right font-medium">{inr(r.outstanding)}</td>
                <td><StatusPill status={r.status} /></td>
                <td className="text-right">{canManage && r.outstanding > 0 && <button className="text-xs text-blue-600 hover:underline" onClick={() => settle(r)}>{recv ? 'Receive' : 'Pay'}</button>}</td>
              </tr>
            ))}</tbody>
          </table></div>
        )) : (!ageing ? <TableSkeleton rows={5} cols={8} /> : ageing.rows.length === 0 ? <EmptyState icon="✅" title="Nothing outstanding" /> : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>{recv ? 'Customer' : 'Supplier'}</th>{BUCKETS.map(([k, l]) => <th key={k} className="text-right">{l}</th>)}<th className="text-right">Invoices total</th><th className="text-right" title="Opening balance (+) or payments not yet matched to an invoice (−)">On account</th></tr></thead>
            <tbody>
              {ageing.rows.map((r: any) => (
                <tr key={r.party_id}><td className="font-medium">{r.name}</td>
                  {BUCKETS.map(([k]) => <td key={k} className={`text-right ${k !== 'current' && r[k] ? 'text-red-600' : ''}`}>{r[k] ? inr(r[k]) : '—'}</td>)}
                  <td className="text-right font-medium">{inr(r.outstanding)}</td><td className="text-right text-gray-500">{r.on_account ? inr(r.on_account) : '—'}</td></tr>
              ))}
              <tr className="font-semibold"><td>Total</td>{BUCKETS.map(([k]) => <td key={k} className="text-right">{inr(ageing.totals[k])}</td>)}<td className="text-right">{inr(ageing.totals.outstanding)}</td><td className="text-right">{inr(ageing.totals.on_account)}</td></tr>
            </tbody>
          </table>
          {ageing.advances > 0 && <p className="text-xs text-gray-500 p-3">Plus {inr(ageing.advances)} of {recv ? 'customer advances' : 'advances paid to suppliers'} not yet applied to a bill.</p>}</div>
        ))}
      </div>
      <VoucherForm form={form} setForm={setForm} ledgers={ledgers} items={catalog} company={company} onClose={() => setForm(null)} onSaved={() => { setForm(null); load() }} />
    </div>
  )
}
