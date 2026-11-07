import { useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, Input, Select, Skeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { GST_STATES } from '../shared'
import AccountsPageFrame from '../components/AccountsPageFrame'

/** Load realistic sample books to explore every feature, then remove them again without touching real entries. */
function SampleData() {
  const [st, setSt] = useState<any>(null)
  const [busy, setBusy] = useState(false)
  const load = () => accountsApi.demoStatus().then(r => setSt(r.data)).catch(() => {})
  useEffect(() => { load() }, [])
  const act = async (fn: () => Promise<any>, ask?: string) => {
    if (ask && !window.confirm(ask)) return
    setBusy(true)
    try { const { data } = await fn(); toast.success(data.message); await load(); setTimeout(() => window.location.reload(), 600) }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  if (!st) return null
  return (
    <div className="card max-w-3xl">
      <div className="card-header"><h3 className="text-sm font-semibold">Sample data</h3></div>
      <div className="card-body space-y-3 text-sm">
        {st.loaded ? <>
          <p className="text-gray-600">Sample books are loaded (customers, invoices, quotations, payments, assets, payroll, petty cash and a closed year). Remove them when you are done — only the sample records are deleted, and your settings are put back.</p>
          <Button variant="secondary" loading={busy} onClick={() => act(() => accountsApi.removeDemo(), 'Remove all sample data? Real entries are never touched; if you have added real entries since loading, nothing is removed.')}>Remove sample data</Button>
        </> : <>
          <p className="text-gray-600">Fill an empty set of books with realistic sample data covering every part of the module, so you can explore it. It can be removed again with one click.</p>
          <Button loading={busy} disabled={!st.can_load} onClick={() => act(() => accountsApi.loadDemo())}>Load sample data</Button>
          {!st.can_load && <p className="text-xs text-amber-600">Only available while the books are empty.</p>}
        </>}
      </div>
    </div>
  )
}

function SettingsView() {
  const [f, setF] = useState<any>(null)
  const [saving, setSaving] = useState(false)
  useEffect(() => { accountsApi.settings().then(r => setF(r.data.settings)).catch(e => toast.error(getError(e))) }, [])
  if (!f) return <Skeleton className="h-40 w-full" />
  const set = (k: string, v: any) => setF((x: any) => ({ ...x, [k]: v }))

  const save = async () => {
    setSaving(true)
    try {
      const { data } = await accountsApi.saveSettings({ ...f, petty_cash_limit: Number(f.petty_cash_limit) || 0, gstin: f.gstin || null, pan: f.pan || null, pincode: f.pincode || null, city: f.city || null })
      setF(data.settings); toast.success('Saved.')
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  return (
    <div className="space-y-4">
    <div className="card max-w-3xl">
      <div className="card-header"><h3 className="text-sm font-semibold">Business &amp; GST profile</h3></div>
      <div className="card-body grid sm:grid-cols-2 gap-4">
        <Input label="Legal / trade name" value={f.legal_name || ''} onChange={e => set('legal_name', e.target.value)} />
        <Input label="GSTIN" placeholder="32ABCDE1234F1Z5" value={f.gstin || ''} onChange={e => set('gstin', e.target.value.toUpperCase())} />
        <Input label="PAN" value={f.pan || ''} onChange={e => set('pan', e.target.value.toUpperCase())} />
        <Select label="State (place of business)" value={f.state_code || ''} onChange={e => set('state_code', e.target.value)}
          options={[{ value: '', label: 'Select state' }, ...GST_STATES.map(([c, n]) => ({ value: c, label: `${c} — ${n}` }))]} />
        <Input label="City" value={f.city || ''} onChange={e => set('city', e.target.value)} />
        <Input label="Pincode" maxLength={6} value={f.pincode || ''} onChange={e => set('pincode', e.target.value.replace(/\D/g, ''))} />
        <Input label="Invoice prefix" value={f.invoice_prefix || ''} onChange={e => set('invoice_prefix', e.target.value)} />
        <Select label="Financial year starts" value={String(f.fy_start_month || 4)} onChange={e => set('fy_start_month', Number(e.target.value))}
          options={['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'].map((m, i) => ({ value: String(i + 1), label: m }))} />
        <Input label="Petty cash float (imprest)" type="number" min="0" value={f.petty_cash_limit ?? ''} onChange={e => set('petty_cash_limit', e.target.value)} />
        <Input label="Quotation validity (days)" type="number" min="1" max="365" value={f.quotation_validity_days ?? 15} onChange={e => set('quotation_validity_days', Number(e.target.value))} />
        <div className="sm:col-span-2">
          <label className="label">Address (printed on invoices)</label>
          <textarea className="input" rows={2} value={f.address || ''} onChange={e => set('address', e.target.value)} />
        </div>
        <label className="flex items-center gap-2 text-sm sm:col-span-2">
          <input type="checkbox" checked={!!f.gst_registered} onChange={e => set('gst_registered', e.target.checked)} />
          GST registered (unchecked: no GST is charged on sales and input credit is not claimed)
        </label>
        <p className="text-xs text-gray-500 sm:col-span-2">
          The state decides CGST + SGST (same state) or IGST (other state) on every invoice and bill.
        </p>
        <div className="sm:col-span-2"><Button onClick={save} loading={saving}>Save</Button></div>
      </div>
    </div>
    <SampleData />
    </div>
  )
}

/** /accounts/settings */
export default function AccountSettingsPage() {
  return (
    <AccountsPageFrame path="settings">
      <SettingsView />
    </AccountsPageFrame>
  )
}
