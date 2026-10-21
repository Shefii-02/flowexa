import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, Input, Select, Modal, ConfirmModal, Badge, EmptyState, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, GST_STATES } from './shared'

const GROUPS: Record<string, { nature: string; label: string }> = {
  debtor: { nature: 'asset', label: 'Customer (receivable)' },
  creditor: { nature: 'liability', label: 'Supplier / vendor (payable)' },
  cash: { nature: 'asset', label: 'Cash' },
  bank: { nature: 'asset', label: 'Bank account' },
  fixed_asset: { nature: 'asset', label: 'Fixed asset' },
  gst_input: { nature: 'asset', label: 'GST input credit' },
  gst_output: { nature: 'liability', label: 'GST payable' },
  tds: { nature: 'liability', label: 'TDS payable' },
  loan: { nature: 'liability', label: 'Loan' },
  capital: { nature: 'equity', label: 'Capital / drawings' },
  sales: { nature: 'income', label: 'Sales income' },
  direct_income: { nature: 'income', label: 'Direct income' },
  indirect_income: { nature: 'income', label: 'Other income' },
  purchase: { nature: 'expense', label: 'Purchases' },
  direct_expense: { nature: 'expense', label: 'Direct expense' },
  indirect_expense: { nature: 'expense', label: 'Office / indirect expense' },
  other: { nature: 'asset', label: 'Other asset' },
}
const EMPTY = { name: '', group: 'indirect_expense', opening_balance: '', opening_type: 'dr', gstin: '', state_code: '', phone: '', email: '', address: '', city: '', pincode: '' }
const NATURES = ['', 'asset', 'liability', 'income', 'expense', 'equity']

export default function LedgersTab() {
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [nature, setNature] = useState('')
  const [search, setSearch] = useState('')
  const [form, setForm] = useState<any>(null)
  const [del, setDel] = useState<any>(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    accountsApi.ledgers({ nature: nature || undefined, search: search || undefined })
      .then(r => setRows(r.data.ledgers)).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [nature, search])
  useEffect(() => { load() }, [load])

  const set = (k: string, v: any) => setForm((f: any) => ({ ...f, [k]: v }))
  const isParty = form && ['debtor', 'creditor'].includes(form.group)

  const save = async () => {
    setSaving(true)
    try {
      const body = { ...form, nature: GROUPS[form.group].nature, opening_balance: Number(form.opening_balance) || 0,
        gstin: form.gstin || null, state_code: form.state_code || null, pincode: form.pincode || null, city: form.city || null }
      form.id ? await accountsApi.updateLedger(form.id, body) : await accountsApi.createLedger(body)
      toast.success('Saved.'); setForm(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }
  const remove = async () => {
    try { await accountsApi.deleteLedger(del.id); toast.success('Deleted.'); setDel(null); load() } catch (e) { toast.error(getError(e)); setDel(null) }
  }

  return (
    <div className="card">
      <div className="card-header gap-3 flex-wrap">
        <input className="input max-w-[220px]" placeholder="Search ledger…" value={search} onChange={e => setSearch(e.target.value)} />
        <select className="select max-w-[160px]" value={nature} onChange={e => setNature(e.target.value)}>
          {NATURES.map(n => <option key={n} value={n}>{n ? n[0].toUpperCase() + n.slice(1) : 'All types'}</option>)}
        </select>
        <div className="ml-auto"><Button onClick={() => setForm({ ...EMPTY })}>+ New ledger</Button></div>
      </div>
      {loading ? <TableSkeleton rows={6} cols={5} /> : rows.length === 0 ? <EmptyState icon="📒" title="No ledgers" /> : (
        <div className="table-wrapper">
          <table className="table">
            <thead><tr><th>Name</th><th>Type</th><th>GSTIN</th><th className="text-right">Balance</th><th /></tr></thead>
            <tbody>
              {rows.map(l => (
                <tr key={l.id}>
                  <td><p className="font-medium">{l.name}</p>{l.phone && <p className="text-xs text-gray-400">{l.phone}</p>}</td>
                  <td><Badge variant="gray">{GROUPS[l.group]?.label || l.group}</Badge></td>
                  <td className="text-xs font-mono">{l.gstin || '—'}</td>
                  <td className="text-right">{inr(Math.abs(l.balance))} <span className="text-xs text-gray-400">{l.balance === 0 ? '' : l.balance > 0 ? 'Dr' : 'Cr'}</span></td>
                  <td className="text-right whitespace-nowrap">
                    <button className="text-xs text-gray-600 hover:underline mr-2" onClick={() => setForm({ ...l, opening_balance: l.opening_balance || '', gstin: l.gstin || '', state_code: l.state_code || '', phone: l.phone || '', email: l.email || '', address: l.address || '', city: l.city || '', pincode: l.pincode || '' })}>Edit</button>
                    {!l.system_key && <button className="text-xs text-red-500 hover:underline" onClick={() => setDel(l)}>Delete</button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Modal open={!!form} onClose={() => setForm(null)} title={form?.id ? 'Edit ledger' : 'New ledger'}
        footer={<><Button variant="secondary" onClick={() => setForm(null)}>Cancel</Button><Button onClick={save} loading={saving}>Save</Button></>}>
        {form && (
          <div className="grid sm:grid-cols-2 gap-4">
            <Input label="Name" value={form.name} onChange={e => set('name', e.target.value)} />
            <Select label="Ledger type" value={form.group} disabled={!!form.system_key} onChange={e => set('group', e.target.value)}
              options={Object.entries(GROUPS).map(([v, g]) => ({ value: v, label: g.label }))} />
            <Input label="Opening balance" type="number" min="0" value={form.opening_balance} onChange={e => set('opening_balance', e.target.value)} />
            <Select label="Opening is" value={form.opening_type} onChange={e => set('opening_type', e.target.value)}
              options={[{ value: 'dr', label: 'Debit (Dr)' }, { value: 'cr', label: 'Credit (Cr)' }]} />
            {isParty && (
              <>
                <Input label="GSTIN" value={form.gstin} onChange={e => set('gstin', e.target.value.toUpperCase())} />
                <Select label="State" value={form.state_code} onChange={e => set('state_code', e.target.value)}
                  options={[{ value: '', label: 'Select state' }, ...GST_STATES.map(([c, n]) => ({ value: c, label: `${c} — ${n}` }))]} />
                <Input label="Phone" value={form.phone} onChange={e => set('phone', e.target.value)} />
                <Input label="Email" value={form.email} onChange={e => set('email', e.target.value)} />
                <div className="sm:col-span-2"><Input label="Address" value={form.address} onChange={e => set('address', e.target.value)} /></div>
                <Input label="City" value={form.city} onChange={e => set('city', e.target.value)} />
                <Input label="Pincode" maxLength={6} value={form.pincode} onChange={e => set('pincode', e.target.value.replace(/\D/g, ''))} />
              </>
            )}
          </div>
        )}
      </Modal>
      <ConfirmModal open={!!del} title="Delete ledger?" message={`Delete "${del?.name}"?`} onConfirm={remove} onCancel={() => setDel(null)} />
    </div>
  )
}
