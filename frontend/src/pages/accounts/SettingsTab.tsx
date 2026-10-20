import { useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, Input, Select, Skeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { GST_STATES } from './shared'

export default function SettingsTab() {
  const [f, setF] = useState<any>(null)
  const [saving, setSaving] = useState(false)
  useEffect(() => { accountsApi.settings().then(r => setF(r.data.settings)).catch(e => toast.error(getError(e))) }, [])
  if (!f) return <Skeleton className="h-40 w-full" />
  const set = (k: string, v: any) => setF((x: any) => ({ ...x, [k]: v }))

  const save = async () => {
    setSaving(true)
    try {
      const { data } = await accountsApi.saveSettings({ ...f, gstin: f.gstin || null, pan: f.pan || null })
      setF(data.settings); toast.success('Saved.')
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  return (
    <div className="card max-w-3xl">
      <div className="card-header"><h3 className="text-sm font-semibold">Business &amp; GST profile</h3></div>
      <div className="card-body grid sm:grid-cols-2 gap-4">
        <Input label="Legal / trade name" value={f.legal_name || ''} onChange={e => set('legal_name', e.target.value)} />
        <Input label="GSTIN" placeholder="32ABCDE1234F1Z5" value={f.gstin || ''} onChange={e => set('gstin', e.target.value.toUpperCase())} />
        <Input label="PAN" value={f.pan || ''} onChange={e => set('pan', e.target.value.toUpperCase())} />
        <Select label="State (place of business)" value={f.state_code || ''} onChange={e => set('state_code', e.target.value)}
          options={[{ value: '', label: 'Select state' }, ...GST_STATES.map(([c, n]) => ({ value: c, label: `${c} — ${n}` }))]} />
        <Input label="Invoice prefix" value={f.invoice_prefix || ''} onChange={e => set('invoice_prefix', e.target.value)} />
        <Select label="Financial year starts" value={String(f.fy_start_month || 4)} onChange={e => set('fy_start_month', Number(e.target.value))}
          options={['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'].map((m, i) => ({ value: String(i + 1), label: m }))} />
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
  )
}
