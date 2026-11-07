import { useEffect, useMemo, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, Input, Select, Modal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, today, GST_RATES, GST_STATES, TDS_SECTIONS, VOUCHER_TYPES } from './shared'

const SETTLES: Record<string, 'receivable' | 'payable'> = { receipt: 'receivable', credit_note: 'receivable', payment: 'payable', debit_note: 'payable' }
const JOURNAL_KINDS: [string, string][] = [['general', 'General'], ['adjusting', 'Adjusting entry'], ['revenue', 'Revenue transaction'], ['expense', 'Expense transaction'], ['asset', 'Asset transaction'], ['liability', 'Liability transaction'], ['equity', 'Equity transaction']]

/** Vouchers entered as line items with GST, and the ones whose party is a customer. */
const ITEM_BASED = ['sales', 'purchase', 'credit_note', 'debit_note']
const CUSTOMER_SIDE = ['sales', 'receipt', 'credit_note']
const blankItem = () => ({ item_id: '', description: '', hsn: '', qty: 1, rate: '', gst_rate: 18 })
const blankEntry = () => ({ ledger_id: '', debit: '', credit: '' })

export function blankVoucher(type: string) {
  return { type, date: today(), party_ledger_id: '', ledger_id: '', account_ledger_id: '', place_of_supply: '', amount: '', gst_rate: 0,
           description: '', tds_section: '', tds_rate: 0, reference_no: '', narration: '', items: [blankItem()], entries: [blankEntry(), blankEntry()],
           due_date: '', is_advance: false, alloc: {} as Record<string, number | string>, origAlloc: {} as Record<string, number>, sub_type: 'general', reverse_on: '', credit_override: false }
}

/** Convert a voucher loaded from the API into the form shape (used for Edit). */
export function voucherToForm(v: any) {
  return { ...blankVoucher(v.type), id: v.id, number: v.number, date: String(v.date).slice(0, 10),
    party_ledger_id: v.party_ledger_id || '', ledger_id: v.ledger_id || '', account_ledger_id: v.account_ledger_id || '',
    place_of_supply: v.place_of_supply || '', reference_no: v.reference_no || '', narration: v.narration || '',
    amount: ['receipt', 'payment', 'contra'].includes(v.type) ? v.total : v.taxable,
    tds_section: v.tds_section || '', tds_rate: v.tds_rate || 0, due_date: v.due_date ? String(v.due_date).slice(0, 10) : '', is_advance: !!v.is_advance,
    sub_type: v.sub_type || 'general', reverse_on: v.reverse_on ? String(v.reverse_on).slice(0, 10) : '',
    ...(() => { const m: Record<string, number> = {}; (v.allocations || []).filter((a: any) => a.payment_voucher_id === v.id && !a.journal_voucher_id).forEach((a: any) => { m[a.invoice_voucher_id] = a.amount }); return { alloc: { ...m }, origAlloc: m } })(),
    gst_rate: v.items?.[0]?.gst_rate ?? 0, description: v.items?.[0]?.description || '',
    items: v.items?.length ? v.items.map((i: any) => ({ item_id: i.item_id || '', description: i.description, hsn: i.hsn || '', qty: i.qty, rate: i.rate, gst_rate: i.gst_rate })) : [blankItem()],
    entries: v.type === 'journal' ? v.entries.map((e: any) => ({ ledger_id: e.ledger_id, debit: e.debit || '', credit: e.credit || '' })) : [blankEntry(), blankEntry()] }
}

export default function VoucherForm({ form, setForm, ledgers, items: catalog = [], company, onClose, onSaved }: {
  form: any; setForm: (f: any) => void; ledgers: any[]; items?: any[]; company: any; onClose: () => void; onSaved: () => void
}) {
  const [saving, setSaving] = useState(false)
  const set = (k: string, v: any) => setForm({ ...form, [k]: v })
  const t = form?.type as string
  const opt = (rows: any[], blank = 'Select…') => [{ value: '', label: blank }, ...rows.map(l => ({ value: l.id, label: l.name }))]
  const by = (...g: string[]) => ledgers.filter(l => g.includes(l.group) && l.is_active !== false)
  const cashBank = by('cash', 'bank')

  // live tax preview — same rule the server applies (other state => IGST)
  const calc = useMemo(() => {
    if (!form) return null
    const party = ledgers.find(l => String(l.id) === String(form.party_ledger_id))
    const counter = form.place_of_supply || party?.state_code
    const inter = !!(counter && company?.state_code && counter !== company.state_code)
    const lines = ITEM_BASED.includes(t) ? form.items : [{ qty: 1, rate: form.amount, gst_rate: form.gst_rate }]
    let taxable = 0, tax = 0
    lines.forEach((i: any) => { const a = (Number(i.qty) || 0) * (Number(i.rate) || 0); taxable += a; tax += a * (Number(i.gst_rate) || 0) / 100 })
    const tds = ['purchase', 'expense'].includes(t) ? taxable * (Number(form.tds_rate) || 0) / 100 : 0
    return { inter, taxable, tax, total: taxable + tax, tds }
  }, [form, ledgers, company, t])

  // open invoices/bills of the chosen party, for allocating a receipt / payment / credit or debit note
  const kind = SETTLES[t]
  const [docs, setDocs] = useState<any[]>([])
  useEffect(() => {
    if (!form || !kind || !form.party_ledger_id || form.is_advance) { setDocs([]); return }
    let live = true
    accountsApi.paymentDocs(kind, { party: form.party_ledger_id, status: 'all' })
      .then(r => { if (live) setDocs(r.data.rows.filter((d: any) => d.outstanding > 0.004 || (form.origAlloc || {})[d.voucher_id] > 0)) }).catch(() => {})
    return () => { live = false }
  }, [form?.party_ledger_id, form?.type, form?.is_advance, form?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!form) return null
  const meta = VOUCHER_TYPES[t]
  const gstRow = (
    <>
      <Select label="GST %" value={String(form.gst_rate)} onChange={e => set('gst_rate', Number(e.target.value))} options={GST_RATES.map(r => ({ value: String(r), label: `${r}%` }))} />
      <Input label="HSN / SAC (optional)" value={form.hsn || ''} onChange={e => set('hsn', e.target.value)} />
    </>
  )

  /** Picking a catalogue item fills description, HSN, GST and the usual rate for this voucher type. */
  const pickItem = (i: number, id: string) => {
    const it = catalog.find(c => String(c.id) === id)
    const useSale = t === 'sales' || t === 'credit_note'
    set('items', form.items.map((x: any, j: number) => j !== i ? x : it
      ? { ...x, item_id: it.id, description: it.name, hsn: it.hsn || '', gst_rate: it.gst_rate, rate: (useSale ? it.sale_rate : it.purchase_rate) || x.rate }
      : { ...x, item_id: '' }))
  }
  const setItem = (i: number, k: string, v: any) => set('items', form.items.map((x: any, j: number) => j === i ? { ...x, [k]: v } : x))
  const setEntry = (i: number, k: string, v: any) => set('entries', form.entries.map((x: any, j: number) => j === i ? { ...x, [k]: v } : x))

  /** What this document can allocate: the receipt/payment amount, or the note's total. */
  const target = t === 'receipt' || t === 'payment' ? Number(form.amount) || 0 : (calc?.total || 0)
  const allocated = Object.values(form.alloc || {}).reduce((a: number, b: any) => a + (Number(b) || 0), 0)
  const room = (d: any) => d.outstanding + ((form.origAlloc || {})[d.voucher_id] || 0)   // an edit may re-use what it already settled
  const setAlloc = (id: number, v: string) => set('alloc', { ...form.alloc, [id]: v })
  const autoAlloc = () => {   // oldest due first
    let left = target; const m: Record<string, number> = {}
    for (const d of [...docs].sort((a, b) => String(a.due_date).localeCompare(String(b.due_date)))) { if (left <= 0.004) break; const take = Math.min(left, room(d)); m[d.voucher_id] = Math.round(take * 100) / 100; left -= take }
    set('alloc', m)
  }

  const save = async (override = false) => {
    setSaving(true)
    try {
      const body: any = { ...form, amount: Number(form.amount) || 0,
        party_ledger_id: form.party_ledger_id || null, ledger_id: form.ledger_id || null, account_ledger_id: form.account_ledger_id || null,
        place_of_supply: form.place_of_supply || null, tds_section: form.tds_section || null, tds_rate: Number(form.tds_rate) || 0,
        due_date: form.due_date || null, credit_override: override || undefined,
        sub_type: t === 'journal' ? form.sub_type : undefined, reverse_on: t === 'journal' && form.sub_type === 'adjusting' && form.reverse_on ? form.reverse_on : null }
      if (kind) {
        body.is_advance = ['receipt', 'payment'].includes(t) && !!form.is_advance
        body.allocations = body.is_advance ? [] : Object.entries(form.alloc || {}).filter(([, a]) => Number(a) > 0).map(([id, a]) => ({ voucher_id: Number(id), amount: Number(a) }))
      }
      form.id ? await accountsApi.updateVoucher(form.id, body) : await accountsApi.createVoucher(body)
      toast.success('Saved.'); onSaved()
    } catch (e: any) {
      // the server refuses a sale that breaches the customer's credit limit; the user may knowingly override it
      if (e?.response?.data?.errors?.credit_limit && !override && window.confirm(`${getError(e)}\n\nSave this invoice anyway?`)) { setSaving(false); return save(true) }
      toast.error(getError(e))
    } finally { setSaving(false) }
  }

  const jDr = form.entries.reduce((s: number, e: any) => s + (Number(e.debit) || 0), 0)
  const jCr = form.entries.reduce((s: number, e: any) => s + (Number(e.credit) || 0), 0)

  return (
    <Modal open onClose={onClose} title={`${form.id ? 'Edit' : 'New'} ${meta.label}${form.number ? ` — ${form.number}` : ''}`} size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={() => save()} loading={saving}>Save</Button></>}>
      <div className="grid sm:grid-cols-2 gap-4">
        <Input label="Date" type="date" value={form.date} onChange={e => set('date', e.target.value)} />
        <Input label={t === 'purchase' ? 'Supplier bill no.' : t === 'receipt' || t === 'payment' ? 'Cheque / UTR / ref' : 'Reference'} value={form.reference_no} onChange={e => set('reference_no', e.target.value)} />

        {(ITEM_BASED.includes(t) || t === 'receipt' || t === 'payment') && (
          <Select label={CUSTOMER_SIDE.includes(t) ? 'Customer' : 'Supplier'} value={form.party_ledger_id} onChange={e => set('party_ledger_id', e.target.value)}
            options={opt(by(CUSTOMER_SIDE.includes(t) ? 'debtor' : 'creditor', ...(t === 'receipt' || t === 'payment' ? ['debtor', 'creditor', 'tds'] : [])), 'Select (add in Ledgers)')} />
        )}
        {(ITEM_BASED.includes(t) || t === 'expense' || t === 'income') && (
          <Select label="Place of supply" value={form.place_of_supply} onChange={e => set('place_of_supply', e.target.value)}
            options={[{ value: '', label: 'Same as party state' }, ...GST_STATES.map(([c, n]) => ({ value: c, label: `${c} — ${n}` }))]} />
        )}

        {['sales', 'purchase'].includes(t) && (
          <Input label="Due date (blank = party's credit terms)" type="date" value={form.due_date} onChange={e => set('due_date', e.target.value)} />
        )}

        {t === 'expense' && (
          <>
            <Select label="Expense head" value={form.ledger_id} onChange={e => set('ledger_id', e.target.value)} options={opt(by('indirect_expense', 'non_operating_expense', 'direct_expense', 'purchase'))} />
            <Select label="Paid from" value={form.account_ledger_id} onChange={e => set('account_ledger_id', e.target.value)} options={opt([...cashBank, ...by('creditor')])} />
            <Input label="Description" value={form.description} onChange={e => set('description', e.target.value)} />
            <Input label="Amount (before GST)" type="number" min="0" value={form.amount} onChange={e => set('amount', e.target.value)} />
            {gstRow}
          </>
        )}
        {(t === 'purchase' || t === 'expense') && (
          <>
            <Select label="TDS deducted (optional)" value={form.tds_section} onChange={e => {
              const sec = TDS_SECTIONS.find(x => x[0] === e.target.value)
              setForm({ ...form, tds_section: e.target.value, tds_rate: sec ? sec[2] : 0 })
            }} options={[{ value: '', label: 'No TDS' }, ...TDS_SECTIONS.map(([k, l]) => ({ value: k, label: l }))]} />
            {form.tds_section && <Input label="TDS rate %" type="number" min="0" max="30" step="0.1" value={form.tds_rate} onChange={e => set('tds_rate', e.target.value)} />}
          </>
        )}
        {t === 'income' && (
          <>
            <Select label="Income head" value={form.ledger_id} onChange={e => set('ledger_id', e.target.value)} options={opt(by('indirect_income', 'non_operating_income', 'direct_income', 'sales'))} />
            <Select label="Received in" value={form.account_ledger_id} onChange={e => set('account_ledger_id', e.target.value)} options={opt([...cashBank, ...by('debtor')])} />
            <Input label="Description" value={form.description} onChange={e => set('description', e.target.value)} />
            <Input label="Amount (before GST)" type="number" min="0" value={form.amount} onChange={e => set('amount', e.target.value)} />
            {gstRow}
          </>
        )}
        {(t === 'receipt' || t === 'payment') && (
          <>
            <Select label={t === 'receipt' ? 'Received in' : 'Paid from'} value={form.account_ledger_id} onChange={e => set('account_ledger_id', e.target.value)} options={opt(cashBank)} />
            <Input label="Amount" type="number" min="0" value={form.amount} onChange={e => set('amount', e.target.value)} />
            <label className="flex items-center gap-2 text-sm sm:col-span-2">
              <input type="checkbox" checked={!!form.is_advance} disabled={Object.keys(form.origAlloc || {}).length > 0} onChange={e => set('is_advance', e.target.checked)} />
              {t === 'receipt' ? 'Advance received — no invoice yet (held as Customer Advance until applied to an invoice)' : 'Advance paid — no bill yet (held as Advance to Supplier until applied to a bill)'}
            </label>
          </>
        )}
        {t === 'contra' && (
          <>
            <Select label="From" value={form.account_ledger_id} onChange={e => set('account_ledger_id', e.target.value)} options={opt(cashBank)} />
            <Select label="To" value={form.ledger_id} onChange={e => set('ledger_id', e.target.value)} options={opt(cashBank)} />
            <Input label="Amount" type="number" min="0" value={form.amount} onChange={e => set('amount', e.target.value)} />
          </>
        )}

        {ITEM_BASED.includes(t) && (
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
        )}

        {t === 'journal' && (
          <>
            <Select label="Journal category" value={form.sub_type} onChange={e => set('sub_type', e.target.value)} options={JOURNAL_KINDS.map(([v, l]) => ({ value: v, label: l }))} />
            {form.sub_type === 'adjusting' && <Input label="Auto-reverse on (optional)" type="date" value={form.reverse_on} onChange={e => set('reverse_on', e.target.value)} />}
          </>
        )}
        {t === 'journal' && (
          <div className="sm:col-span-2 space-y-2">
            <label className="label">Lines</label>
            {form.entries.map((e: any, i: number) => (
              <div key={i} className="grid grid-cols-12 gap-2">
                <select className="select col-span-6" value={e.ledger_id} onChange={ev => setEntry(i, 'ledger_id', ev.target.value)}>
                  {opt(ledgers).map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
                <input className="input col-span-3" type="number" min="0" placeholder="Debit" value={e.debit} onChange={ev => setEntry(i, 'debit', ev.target.value)} />
                <input className="input col-span-3" type="number" min="0" placeholder="Credit" value={e.credit} onChange={ev => setEntry(i, 'credit', ev.target.value)} />
              </div>
            ))}
            <div className="flex justify-between text-sm">
              <button className="text-xs text-blue-600 hover:underline" onClick={() => set('entries', [...form.entries, blankEntry()])}>+ Add line</button>
              <span className={Math.abs(jDr - jCr) < 0.01 ? 'text-green-600' : 'text-red-500'}>Dr {inr(jDr)} · Cr {inr(jCr)}</span>
            </div>
          </div>
        )}

        {kind && form.party_ledger_id && !form.is_advance && (
          <div className="sm:col-span-2 border rounded-lg p-3 space-y-2">
            <div className="flex items-center justify-between">
              <label className="label !mb-0">Settle {kind === 'receivable' ? 'invoices' : 'bills'}</label>
              <button className="text-xs text-blue-600 hover:underline" onClick={autoAlloc}>Auto — oldest due first</button>
            </div>
            {docs.length === 0 ? <p className="text-xs text-gray-400">No open {kind === 'receivable' ? 'invoices' : 'bills'} for this party. The amount stays on their account.</p> : (
              <table className="table text-sm"><thead><tr><th>Document</th><th>Due</th><th className="text-right">Outstanding</th><th className="text-right w-32">Settle</th></tr></thead><tbody>
                {docs.map(d => (
                  <tr key={d.voucher_id}>
                    <td>{d.number}</td><td className={d.days_overdue > 0 ? 'text-red-600' : ''}>{d.due_date}{d.days_overdue > 0 ? ` (${d.days_overdue}d late)` : ''}</td>
                    <td className="text-right">{inr(room(d))}</td>
                    <td className="text-right"><input className="input !py-1 text-right" type="number" min="0" max={room(d)} value={form.alloc?.[d.voucher_id] ?? ''} onChange={e => setAlloc(d.voucher_id, e.target.value)} /></td>
                  </tr>
                ))}
              </tbody></table>
            )}
            <div className="flex justify-between text-xs">
              <span className={allocated > target + 0.005 ? 'text-red-500' : 'text-gray-500'}>Allocated {inr(allocated)} of {inr(target)}</span>
              <span className="text-gray-500">{target - allocated > 0.005 ? `${inr(target - allocated)} stays on account` : ''}</span>
            </div>
          </div>
        )}

        <div className="sm:col-span-2"><Input label="Narration / notes" value={form.narration} onChange={e => set('narration', e.target.value)} /></div>

        {calc && [...ITEM_BASED, 'expense', 'income'].includes(t) && (
          <div className="sm:col-span-2 bg-gray-50 rounded-lg p-3 text-sm space-y-1">
            <div className="flex justify-between"><span>Taxable value</span><span>{inr(calc.taxable)}</span></div>
            {calc.inter
              ? <div className="flex justify-between"><span>IGST</span><span>{inr(calc.tax)}</span></div>
              : <><div className="flex justify-between"><span>CGST</span><span>{inr(calc.tax / 2)}</span></div><div className="flex justify-between"><span>SGST</span><span>{inr(calc.tax / 2)}</span></div></>}
            <div className="flex justify-between font-semibold border-t pt-1"><span>Total</span><span>{inr(calc.total)}</span></div>
            {calc.tds > 0 && <div className="flex justify-between text-orange-600"><span>TDS deducted ({form.tds_section})</span><span>− {inr(calc.tds)} → pay {inr(calc.total - calc.tds)}</span></div>}
            {!company?.state_code && <p className="text-xs text-amber-600">Set your state in Settings so CGST/SGST vs IGST is decided correctly.</p>}
          </div>
        )}
      </div>
    </Modal>
  )
}
