import { useMemo, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, Input, Select, Modal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, today, GST_RATES, GST_STATES, VOUCHER_TYPES } from './shared'

const blankItem = () => ({ description: '', hsn: '', qty: 1, rate: '', gst_rate: 18 })
const blankEntry = () => ({ ledger_id: '', debit: '', credit: '' })

export function blankVoucher(type: string) {
  return { type, date: today(), party_ledger_id: '', ledger_id: '', account_ledger_id: '', place_of_supply: '', amount: '', gst_rate: 0,
           description: '', reference_no: '', narration: '', items: [blankItem()], entries: [blankEntry(), blankEntry()] }
}

/** Convert a voucher loaded from the API into the form shape (used for Edit). */
export function voucherToForm(v: any) {
  return { ...blankVoucher(v.type), id: v.id, number: v.number, date: String(v.date).slice(0, 10),
    party_ledger_id: v.party_ledger_id || '', ledger_id: v.ledger_id || '', account_ledger_id: v.account_ledger_id || '',
    place_of_supply: v.place_of_supply || '', reference_no: v.reference_no || '', narration: v.narration || '',
    amount: ['receipt', 'payment', 'contra'].includes(v.type) ? v.total : v.taxable,
    gst_rate: v.items?.[0]?.gst_rate ?? 0, description: v.items?.[0]?.description || '',
    items: v.items?.length ? v.items.map((i: any) => ({ description: i.description, hsn: i.hsn || '', qty: i.qty, rate: i.rate, gst_rate: i.gst_rate })) : [blankItem()],
    entries: v.type === 'journal' ? v.entries.map((e: any) => ({ ledger_id: e.ledger_id, debit: e.debit || '', credit: e.credit || '' })) : [blankEntry(), blankEntry()] }
}

export default function VoucherForm({ form, setForm, ledgers, company, onClose, onSaved }: {
  form: any; setForm: (f: any) => void; ledgers: any[]; company: any; onClose: () => void; onSaved: () => void
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
    const lines = ['sales', 'purchase'].includes(t) ? form.items : [{ qty: 1, rate: form.amount, gst_rate: form.gst_rate }]
    let taxable = 0, tax = 0
    lines.forEach((i: any) => { const a = (Number(i.qty) || 0) * (Number(i.rate) || 0); taxable += a; tax += a * (Number(i.gst_rate) || 0) / 100 })
    return { inter, taxable, tax, total: taxable + tax }
  }, [form, ledgers, company, t])

  if (!form) return null
  const meta = VOUCHER_TYPES[t]
  const gstRow = (
    <>
      <Select label="GST %" value={String(form.gst_rate)} onChange={e => set('gst_rate', Number(e.target.value))} options={GST_RATES.map(r => ({ value: String(r), label: `${r}%` }))} />
      <Input label="HSN / SAC (optional)" value={form.hsn || ''} onChange={e => set('hsn', e.target.value)} />
    </>
  )

  const setItem = (i: number, k: string, v: any) => set('items', form.items.map((x: any, j: number) => j === i ? { ...x, [k]: v } : x))
  const setEntry = (i: number, k: string, v: any) => set('entries', form.entries.map((x: any, j: number) => j === i ? { ...x, [k]: v } : x))

  const save = async () => {
    setSaving(true)
    try {
      const body: any = { ...form, amount: Number(form.amount) || 0,
        party_ledger_id: form.party_ledger_id || null, ledger_id: form.ledger_id || null, account_ledger_id: form.account_ledger_id || null,
        place_of_supply: form.place_of_supply || null }
      form.id ? await accountsApi.updateVoucher(form.id, body) : await accountsApi.createVoucher(body)
      toast.success('Saved.'); onSaved()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  const jDr = form.entries.reduce((s: number, e: any) => s + (Number(e.debit) || 0), 0)
  const jCr = form.entries.reduce((s: number, e: any) => s + (Number(e.credit) || 0), 0)

  return (
    <Modal open onClose={onClose} title={`${form.id ? 'Edit' : 'New'} ${meta.label}${form.number ? ` — ${form.number}` : ''}`} size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={save} loading={saving}>Save</Button></>}>
      <div className="grid sm:grid-cols-2 gap-4">
        <Input label="Date" type="date" value={form.date} onChange={e => set('date', e.target.value)} />
        <Input label={t === 'purchase' ? 'Supplier bill no.' : t === 'receipt' || t === 'payment' ? 'Cheque / UTR / ref' : 'Reference'} value={form.reference_no} onChange={e => set('reference_no', e.target.value)} />

        {(t === 'sales' || t === 'purchase' || t === 'receipt' || t === 'payment') && (
          <Select label={t === 'sales' || t === 'receipt' ? 'Customer' : 'Supplier'} value={form.party_ledger_id} onChange={e => set('party_ledger_id', e.target.value)}
            options={opt(by(t === 'sales' || t === 'receipt' ? 'debtor' : 'creditor', ...(t === 'receipt' || t === 'payment' ? ['debtor', 'creditor'] : [])), 'Select (add in Ledgers)')} />
        )}
        {(t === 'sales' || t === 'purchase' || t === 'expense' || t === 'income') && (
          <Select label="Place of supply" value={form.place_of_supply} onChange={e => set('place_of_supply', e.target.value)}
            options={[{ value: '', label: 'Same as party state' }, ...GST_STATES.map(([c, n]) => ({ value: c, label: `${c} — ${n}` }))]} />
        )}

        {t === 'expense' && (
          <>
            <Select label="Expense head" value={form.ledger_id} onChange={e => set('ledger_id', e.target.value)} options={opt(by('indirect_expense', 'direct_expense', 'purchase', 'fixed_asset'))} />
            <Select label="Paid from" value={form.account_ledger_id} onChange={e => set('account_ledger_id', e.target.value)} options={opt([...cashBank, ...by('creditor')])} />
            <Input label="Description" value={form.description} onChange={e => set('description', e.target.value)} />
            <Input label="Amount (before GST)" type="number" min="0" value={form.amount} onChange={e => set('amount', e.target.value)} />
            {gstRow}
          </>
        )}
        {t === 'income' && (
          <>
            <Select label="Income head" value={form.ledger_id} onChange={e => set('ledger_id', e.target.value)} options={opt(by('indirect_income', 'direct_income', 'sales'))} />
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
          </>
        )}
        {t === 'contra' && (
          <>
            <Select label="From" value={form.account_ledger_id} onChange={e => set('account_ledger_id', e.target.value)} options={opt(cashBank)} />
            <Select label="To" value={form.ledger_id} onChange={e => set('ledger_id', e.target.value)} options={opt(cashBank)} />
            <Input label="Amount" type="number" min="0" value={form.amount} onChange={e => set('amount', e.target.value)} />
          </>
        )}

        {(t === 'sales' || t === 'purchase') && (
          <div className="sm:col-span-2 space-y-2">
            <label className="label">Items</label>
            {form.items.map((it: any, i: number) => (
              <div key={i} className="grid grid-cols-12 gap-2 items-center">
                <input className="input col-span-4" placeholder="Description" value={it.description} onChange={e => setItem(i, 'description', e.target.value)} />
                <input className="input col-span-2" placeholder="HSN/SAC" value={it.hsn} onChange={e => setItem(i, 'hsn', e.target.value)} />
                <input className="input col-span-1" type="number" min="0" placeholder="Qty" value={it.qty} onChange={e => setItem(i, 'qty', e.target.value)} />
                <input className="input col-span-2" type="number" min="0" placeholder="Rate" value={it.rate} onChange={e => setItem(i, 'rate', e.target.value)} />
                <select className="select col-span-2" value={it.gst_rate} onChange={e => setItem(i, 'gst_rate', Number(e.target.value))}>{GST_RATES.map(r => <option key={r} value={r}>{r}%</option>)}</select>
                <button className="text-red-500 text-sm col-span-1" disabled={form.items.length === 1} onClick={() => set('items', form.items.filter((_: any, j: number) => j !== i))}>✕</button>
              </div>
            ))}
            <button className="text-xs text-blue-600 hover:underline" onClick={() => set('items', [...form.items, blankItem()])}>+ Add item</button>
          </div>
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

        <div className="sm:col-span-2"><Input label="Narration / notes" value={form.narration} onChange={e => set('narration', e.target.value)} /></div>

        {calc && ['sales', 'purchase', 'expense', 'income'].includes(t) && (
          <div className="sm:col-span-2 bg-gray-50 rounded-lg p-3 text-sm space-y-1">
            <div className="flex justify-between"><span>Taxable value</span><span>{inr(calc.taxable)}</span></div>
            {calc.inter
              ? <div className="flex justify-between"><span>IGST</span><span>{inr(calc.tax)}</span></div>
              : <><div className="flex justify-between"><span>CGST</span><span>{inr(calc.tax / 2)}</span></div><div className="flex justify-between"><span>SGST</span><span>{inr(calc.tax / 2)}</span></div></>}
            <div className="flex justify-between font-semibold border-t pt-1"><span>Total</span><span>{inr(calc.total)}</span></div>
            {!company?.state_code && <p className="text-xs text-amber-600">Set your state in Settings so CGST/SGST vs IGST is decided correctly.</p>}
          </div>
        )}
      </div>
    </Modal>
  )
}
