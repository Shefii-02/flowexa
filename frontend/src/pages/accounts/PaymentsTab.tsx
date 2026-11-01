import { Fragment, useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, RangePicker, Stat, StatusPill, SubTabs, today, useRange, useCanManage } from './shared'
import VoucherForm, { blankVoucher } from './VoucherForm'

const BUCKETS: [string, string][] = [['current', 'Not due'], ['d1_30', '1–30'], ['d31_60', '31–60'], ['d61_90', '61–90'], ['d90p', '90+']]

/** Receivable / payable trackers (invoice by invoice), ageing, advances and the payment report. */
export default function PaymentsTab() {
  const [sub, setSub] = useState('receivable')
  return (
    <div className="space-y-3">
      <SubTabs value={sub} onChange={setSub} tabs={[['receivable', '📥 Payments receivable'], ['payable', '📤 Payments payable'], ['advances', '💼 Advances'], ['report', '📑 Payment report']]} />
      {(sub === 'receivable' || sub === 'payable') && <Tracker key={sub} kind={sub} />}
      {sub === 'advances' && <Advances />}
      {sub === 'report' && <PaymentReport />}
    </div>
  )
}

function Tracker({ kind }: { kind: 'receivable' | 'payable' }) {
  const canManage = useCanManage()
  const [view, setView] = useState<'invoices' | 'ageing'>('invoices')
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
          <SubTabs value={view} onChange={k => setView(k as any)} tabs={[['invoices', 'Documents'], ['ageing', 'Ageing']]} />
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

function Advances() {
  const canManage = useCanManage()
  const [kind, setKind] = useState<'receivable' | 'payable'>('receivable')
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
      <SubTabs value={kind} onChange={k => setKind(k as any)} tabs={[['receivable', 'Received from customers'], ['payable', 'Paid to suppliers']]} />
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

function PaymentReport() {
  const [range, setRange] = useRange()
  const [d, setD] = useState<any>(null)
  useEffect(() => { setD(null); accountsApi.paymentReport(range).then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [range])
  return (
    <div className="space-y-3">
      <RangePicker range={range} onChange={setRange} />
      {!d ? <TableSkeleton rows={4} cols={6} /> : (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Stat label="Received" value={inr(d.total_received)} tone="text-green-600" sub={d.advances_received ? `incl. ${inr(d.advances_received)} advances` : undefined} />
            <Stat label="Paid" value={inr(d.total_paid)} tone="text-red-600" sub={d.advances_paid ? `incl. ${inr(d.advances_paid)} advances` : undefined} />
            <Stat label="Net cash movement" value={inr(d.net)} tone={d.net >= 0 ? 'text-green-600' : 'text-red-600'} />
            <Stat label="Vouchers" value={d.rows.length} />
          </div>
          <div className="grid lg:grid-cols-3 gap-3">
            <div className="card lg:col-span-2">
              {d.rows.length === 0 ? <EmptyState icon="🧾" title="No receipts or payments in this period" /> : (
                <div className="table-wrapper"><table className="table">
                  <thead><tr><th>Date</th><th>Voucher</th><th>Party</th><th>Account</th><th className="text-right">Amount</th><th className="text-right">Matched</th><th className="text-right">On account</th></tr></thead>
                  <tbody>{d.rows.map((r: any) => (
                    <tr key={r.voucher_id}><td className="text-sm">{r.date}</td>
                      <td>{r.direction === 'in' ? '📥' : '📤'} {r.number}{r.advance ? <span className="ml-1 text-xs text-amber-600">advance</span> : null}</td>
                      <td className="text-sm">{r.party}</td><td className="text-sm">{r.account}</td>
                      <td className={`text-right font-medium ${r.direction === 'in' ? 'text-green-600' : 'text-red-600'}`}>{inr(r.amount)}</td>
                      <td className="text-right">{r.allocated ? inr(r.allocated) : '—'}</td><td className="text-right text-gray-500">{r.unallocated ? inr(r.unallocated) : '—'}</td></tr>
                  ))}</tbody>
                </table></div>
              )}
            </div>
            <div className="card"><div className="card-header"><h4 className="text-sm font-semibold">By account</h4></div>
              <div className="card-body space-y-2 text-sm">{d.by_account.map((a: any) => (
                <div key={a.account} className="border-b pb-2"><p className="font-medium">{a.account}</p>
                  <p className="flex justify-between text-gray-500"><span>In</span><span className="text-green-600">{inr(a.received)}</span></p>
                  <p className="flex justify-between text-gray-500"><span>Out</span><span className="text-red-600">{inr(a.paid)}</span></p></div>))}</div></div>
          </div>
        </>
      )}
    </div>
  )
}
