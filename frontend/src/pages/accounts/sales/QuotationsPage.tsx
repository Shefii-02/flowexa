import { useCallback, useEffect, useMemo, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, ConfirmModal, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { GST_RATES, GST_STATES, inr, Stat, StatusPill, today, useCanManage } from '../shared'
import { printInvoice } from '../printInvoice'
import AccountsPageFrame from '../components/AccountsPageFrame'

const blankItem = () => ({ item_id: '', description: '', hsn: '', qty: 1, rate: '', gst_rate: 18 })
const addDays = (d: string, n: number) => { const x = new Date(d + 'T00:00:00'); x.setDate(x.getDate() + n); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}` }
const STATUSES: [string, string][] = [['', 'All'], ['draft', 'Draft'], ['sent', 'Sent'], ['accepted', 'Accepted'], ['rejected', 'Rejected'], ['expired', 'Expired'], ['invoiced', 'Invoiced']]

function QuotationsView() {
  const canManage = useCanManage()
  const [list, setList] = useState<any>({ data: [], total: 0, summary: null })
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [customers, setCustomers] = useState<any[]>([])
  const [catalog, setCatalog] = useState<any[]>([])
  const [company, setCompany] = useState<any>(null)
  const [form, setForm] = useState<any>(null)
  const [saving, setSaving] = useState(false)
  const [del, setDel] = useState<any>(null)
  const [convert, setConvert] = useState<any>(null)

  const load = useCallback(() => {
    setLoading(true)
    accountsApi.quotations({ status: status || undefined, search: search || undefined, per_page: 100 })
      .then(r => setList(r.data)).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [status, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    accountsApi.ledgers({ group: 'debtor' }).then(r => setCustomers(r.data.ledgers)).catch(() => {})
    accountsApi.items().then(r => setCatalog(r.data.items)).catch(() => {})
    accountsApi.settings().then(r => setCompany(r.data.settings)).catch(() => {})
  }, [])

  const openNew = () => setForm({ date: today(), valid_until: addDays(today(), company?.quotation_validity_days || 15), party_ledger_id: '', place_of_supply: '', subject: '', notes: '', terms: '', items: [blankItem()] })
  const openEdit = async (id: number) => {
    try {
      const q = (await accountsApi.quotation(id)).data.quotation
      setForm({ id: q.id, number: q.number, date: String(q.date).slice(0, 10), valid_until: String(q.valid_until).slice(0, 10), party_ledger_id: q.party_ledger_id, place_of_supply: q.place_of_supply || '',
        subject: q.subject || '', notes: q.notes || '', terms: q.terms || '', items: q.items.map((i: any) => ({ item_id: i.item_id || '', description: i.description, hsn: i.hsn || '', qty: i.qty, rate: i.rate, gst_rate: i.gst_rate })) })
    } catch (e) { toast.error(getError(e)) }
  }

  const calc = useMemo(() => {
    if (!form) return null
    const party = customers.find(l => String(l.id) === String(form.party_ledger_id))
    const counter = form.place_of_supply || party?.state_code
    const inter = !!(counter && company?.state_code && counter !== company.state_code)
    let taxable = 0, tax = 0
    form.items.forEach((i: any) => { const a = (Number(i.qty) || 0) * (Number(i.rate) || 0); taxable += a; tax += a * (company?.gst_registered === false ? 0 : Number(i.gst_rate) || 0) / 100 })
    return { inter, taxable, tax, total: taxable + tax }
  }, [form, customers, company])

  const set = (k: string, v: any) => setForm((f: any) => ({ ...f, [k]: v }))
  const setItem = (i: number, k: string, v: any) => set('items', form.items.map((x: any, j: number) => j === i ? { ...x, [k]: v } : x))
  const pickItem = (i: number, id: string) => {
    const it = catalog.find(c => String(c.id) === id)
    set('items', form.items.map((x: any, j: number) => j !== i ? x : it ? { ...x, item_id: it.id, description: it.name, hsn: it.hsn || '', gst_rate: it.gst_rate, rate: it.sale_rate || x.rate } : { ...x, item_id: '' }))
  }

  const save = async () => {
    setSaving(true)
    try {
      const body = { ...form, party_ledger_id: form.party_ledger_id || null, place_of_supply: form.place_of_supply || null }
      form.id ? await accountsApi.updateQuotation(form.id, body) : await accountsApi.createQuotation(body)
      toast.success('Saved.'); setForm(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }
  const act = async (fn: () => Promise<any>, ok: string) => { try { await fn(); toast.success(ok); load() } catch (e) { toast.error(getError(e)) } }
  const print = async (id: number) => {
    try { const { data } = await accountsApi.quotation(id); printInvoice({ ...data.quotation, type: 'quotation' }, data.company) } catch (e) { toast.error(getError(e)) }
  }
  const doConvert = async (override = false) => {
    try {
      const { data } = await accountsApi.convertQuotation(convert.id, { date: convert.date, due_date: convert.due_date || undefined, credit_override: override })
      toast.success(data.message); setConvert(null); load()
    } catch (e: any) {
      if (e?.response?.data?.errors?.credit_limit && window.confirm(`${getError(e)}\n\nRaise the invoice anyway?`)) return doConvert(true)
      toast.error(getError(e))
    }
  }

  const s = list.summary
  return (
    <div className="space-y-3">
      {s && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <Stat label="Open quotations" value={inr(s.open_value)} sub={`${(s.counts.draft || 0) + (s.counts.sent || 0)} awaiting a decision`} />
          <Stat label="Won" value={inr(s.won_value)} tone="text-green-600" sub={`${(s.counts.accepted || 0) + (s.counts.invoiced || 0)} accepted / invoiced`} />
          <Stat label="Win rate" value={s.win_rate === null ? '—' : `${s.win_rate}%`} sub="accepted ÷ decided" />
          <Stat label="Expired" value={s.counts.expired || 0} tone={s.counts.expired ? 'text-amber-600' : ''} sub="extend validity to revive" />
        </div>
      )}
      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <select className="select max-w-[160px]" value={status} onChange={e => setStatus(e.target.value)}>{STATUSES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
          <input className="input max-w-[220px]" placeholder="Search number / subject…" value={search} onChange={e => setSearch(e.target.value)} />
          {canManage && <div className="ml-auto"><Button onClick={openNew}>+ New quotation</Button></div>}
        </div>
        {loading ? <TableSkeleton rows={5} cols={6} /> : list.data.length === 0 ? <EmptyState icon="📝" title="No quotations" desc="Send a priced offer first; convert it to an invoice in one click when it is accepted." /> : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Quotation</th><th>Customer</th><th>Valid until</th><th className="text-right">Total</th><th>Status</th><th /></tr></thead>
            <tbody>{list.data.map((q: any) => {
              const st = q.effective_status
              return (
                <tr key={q.id}>
                  <td><p className="font-medium">{q.number}</p><p className="text-xs text-gray-400">{String(q.date).slice(0, 10)}{q.subject ? ` · ${q.subject}` : ''}</p></td>
                  <td className="text-sm">{q.party?.name}</td>
                  <td className="text-sm">{String(q.valid_until).slice(0, 10)}</td>
                  <td className="text-right font-medium">{inr(q.total)}</td>
                  <td><StatusPill status={st} /></td>
                  <td className="text-right whitespace-nowrap text-xs space-x-2">
                    <button className="text-blue-600 hover:underline" onClick={() => print(q.id)}>Print</button>{canManage && <>
                    {['draft', 'sent'].includes(q.status) && <button className="text-gray-600 hover:underline" onClick={() => openEdit(q.id)}>{st === 'expired' ? 'Extend' : 'Edit'}</button>}
                    {q.status === 'draft' && <button className="text-blue-600 hover:underline" onClick={() => act(() => accountsApi.quotationStatus(q.id, 'sent'), 'Marked as sent.')}>Mark sent</button>}
                    {['draft', 'sent'].includes(q.status) && st !== 'expired' && <>
                      <button className="text-green-600 hover:underline" onClick={() => act(() => accountsApi.quotationStatus(q.id, 'accepted'), 'Accepted.')}>Accept</button>
                      <button className="text-red-500 hover:underline" onClick={() => act(() => accountsApi.quotationStatus(q.id, 'rejected'), 'Rejected.')}>Reject</button></>}
                    {q.status === 'rejected' && <button className="text-gray-600 hover:underline" onClick={() => act(() => accountsApi.quotationStatus(q.id, 'draft'), 'Reopened as draft.')}>Reopen</button>}
                    {!['invoiced', 'rejected'].includes(q.status) && st !== 'expired' && <button className="font-medium text-purple-700 hover:underline" onClick={() => setConvert({ id: q.id, number: q.number, date: today(), due_date: '' })}>→ Invoice</button>}
                    <button className="text-gray-600 hover:underline" onClick={() => act(() => accountsApi.duplicateQuotation(q.id), 'Copied as a new draft.')}>Copy</button>
                    {q.status !== 'invoiced' && <button className="text-red-500 hover:underline" onClick={() => setDel(q)}>Delete</button>}
                  </>}</td>
                </tr>
              )
            })}</tbody>
          </table></div>
        )}
      </div>

      {form && (
        <Modal open onClose={() => setForm(null)} size="lg" title={`${form.id ? 'Edit' : 'New'} quotation${form.number ? ` — ${form.number}` : ''}`}
          footer={<><Button variant="secondary" onClick={() => setForm(null)}>Cancel</Button><Button onClick={save} loading={saving}>Save</Button></>}>
          <div className="grid sm:grid-cols-2 gap-4">
            <Select label="Customer" value={form.party_ledger_id} onChange={e => set('party_ledger_id', e.target.value)}
              options={[{ value: '', label: 'Select (add in Ledgers)' }, ...customers.filter(c => c.is_active !== false).map(c => ({ value: c.id, label: c.name }))]} />
            <Input label="Subject" value={form.subject} onChange={e => set('subject', e.target.value)} />
            <Input label="Date" type="date" value={form.date} onChange={e => set('date', e.target.value)} />
            <Input label="Valid until" type="date" value={form.valid_until} onChange={e => set('valid_until', e.target.value)} />
            <Select label="Place of supply" value={form.place_of_supply} onChange={e => set('place_of_supply', e.target.value)}
              options={[{ value: '', label: 'Same as customer state' }, ...GST_STATES.map(([c, n]) => ({ value: c, label: `${c} — ${n}` }))]} />
            <div className="sm:col-span-2 space-y-2">
              <label className="label">Items</label>
              {form.items.map((it: any, i: number) => (
                <div key={i} className="grid grid-cols-12 gap-2 items-center border-b border-gray-100 pb-2">
                  {catalog.length > 0 && (
                    <select className="select col-span-12" value={it.item_id} onChange={e => pickItem(i, e.target.value)}>
                      <option value="">Pick an item (optional)…</option>
                      {catalog.filter(c => c.is_active !== false).map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                  )}
                  <input className="input col-span-8" placeholder="Description" value={it.description} onChange={e => setItem(i, 'description', e.target.value)} />
                  <input className="input col-span-4" placeholder="HSN/SAC" value={it.hsn} onChange={e => setItem(i, 'hsn', e.target.value)} />
                  <input className="input col-span-3" type="number" min="0" placeholder="Qty" value={it.qty} onChange={e => setItem(i, 'qty', e.target.value)} />
                  <input className="input col-span-4" type="number" min="0" placeholder="Rate" value={it.rate} onChange={e => setItem(i, 'rate', e.target.value)} />
                  <select className="select col-span-4" value={it.gst_rate} onChange={e => setItem(i, 'gst_rate', Number(e.target.value))}>{GST_RATES.map(r => <option key={r} value={r}>GST {r}%</option>)}</select>
                  <button className="text-red-500 text-sm col-span-1" disabled={form.items.length === 1} onClick={() => set('items', form.items.filter((_: any, j: number) => j !== i))}>✕</button>
                </div>
              ))}
              <button className="text-xs text-blue-600 hover:underline" onClick={() => set('items', [...form.items, blankItem()])}>+ Add item</button>
            </div>
            <div className="sm:col-span-2"><Input label="Notes (shown on the quotation)" value={form.notes} onChange={e => set('notes', e.target.value)} /></div>
            <div className="sm:col-span-2"><Input label="Terms & conditions" value={form.terms} onChange={e => set('terms', e.target.value)} /></div>
            {calc && (
              <div className="sm:col-span-2 bg-gray-50 rounded-lg p-3 text-sm space-y-1">
                <div className="flex justify-between"><span>Taxable value</span><span>{inr(calc.taxable)}</span></div>
                {calc.inter ? <div className="flex justify-between"><span>IGST</span><span>{inr(calc.tax)}</span></div>
                  : <><div className="flex justify-between"><span>CGST</span><span>{inr(calc.tax / 2)}</span></div><div className="flex justify-between"><span>SGST</span><span>{inr(calc.tax / 2)}</span></div></>}
                <div className="flex justify-between font-semibold border-t pt-1"><span>Total</span><span>{inr(calc.total)}</span></div>
              </div>
            )}
          </div>
        </Modal>
      )}

      {convert && (
        <Modal open onClose={() => setConvert(null)} title={`Create invoice from ${convert.number}`}
          footer={<><Button variant="secondary" onClick={() => setConvert(null)}>Cancel</Button><Button onClick={() => doConvert(false)}>Create invoice</Button></>}>
          <div className="grid sm:grid-cols-2 gap-4">
            <Input label="Invoice date" type="date" value={convert.date} onChange={e => setConvert({ ...convert, date: e.target.value })} />
            <Input label="Due date (blank = customer's credit terms)" type="date" value={convert.due_date} onChange={e => setConvert({ ...convert, due_date: e.target.value })} />
            <p className="sm:col-span-2 text-xs text-gray-500">The invoice copies the quotation's lines and GST exactly. The customer's credit limit is checked before it is posted.</p>
          </div>
        </Modal>
      )}
      <ConfirmModal open={!!del} title="Delete quotation?" message={`Delete ${del?.number}?`} onConfirm={async () => { await act(() => accountsApi.deleteQuotation(del.id), 'Deleted.'); setDel(null) }} onCancel={() => setDel(null)} />
    </div>
  )
}

/** /accounts/sales/quotations */
export default function QuotationsPage() {
  return (
    <AccountsPageFrame path="sales/quotations">
      <QuotationsView />
    </AccountsPageFrame>
  )
}
