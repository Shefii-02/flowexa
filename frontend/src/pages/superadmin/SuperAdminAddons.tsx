// Superadmin: list and edit add-ons, with separate monthly and yearly prices.
import { useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { planApi } from '@/api'
import { Badge, Button, Input, Modal, Spinner } from '@/components/ui'
import PricingPdfButton from '@/components/plan/PricingPdfButton'
import { fmt, getError } from '@/utils'

type Row = { id: number; name: string; slug: string; description: string | null; type: string; price: string | number; price_yearly: string | number | null; billing_cycle: string; group?: { title: string; icon: string }; config: { resource?: string; amount?: number } | null; is_active: boolean }
type Res = { key: string; label: string; kind: string }
const EMPTY = { name: '', slug: '', description: '', type: 'limit', price: '', price_yearly: '', billing_cycle: 'monthly', resource: '', amount: '', is_active: true }

export default function SuperAdminAddons() {
  const [rows, setRows] = useState<Row[]>([])
  const [resources, setResources] = useState<Res[]>([])
  const [loading, setLoading] = useState(true)
  const [edit, setEdit] = useState<Row | 'new' | null>(null)
  const [form, setForm] = useState(EMPTY)
  const [saving, setSaving] = useState(false)

  const load = () => Promise.all([planApi.saAddons(), planApi.saAddonResources()])
    .then(([a, r]) => { setRows(a.data.addons ?? a.data.data ?? a.data); setResources(r.data.resources) })
    .catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  useEffect(() => { load() }, [])

  const open = (r: Row | 'new') => {
    setEdit(r)
    setForm(r === 'new' ? EMPTY : { name: r.name, slug: r.slug, description: r.description ?? '', type: r.type, price: String(r.price), price_yearly: r.price_yearly != null ? String(r.price_yearly) : '', billing_cycle: r.billing_cycle, resource: r.config?.resource ?? '', amount: r.config?.amount != null ? String(r.config.amount) : '', is_active: !!r.is_active })
  }
  const set = (k: keyof typeof EMPTY, v: string | boolean) => setForm(f => ({ ...f, [k]: v }))

  const save = async () => {
    setSaving(true)
    const payload = {
      name: form.name, slug: form.slug, description: form.description || null, type: form.type,
      price: +form.price, price_yearly: form.price_yearly ? +form.price_yearly : null,
      billing_cycle: form.billing_cycle, is_active: form.is_active,
      config: form.type === 'limit' ? { resource: form.resource, amount: +form.amount } : null,
    }
    try {
      if (edit === 'new') await planApi.saCreateAddon(payload); else if (edit) await planApi.saUpdateAddon(edit.id, payload)
      toast.success('Saved'); setEdit(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  if (loading) return <div className="flex justify-center p-10"><Spinner /></div>
  const label = (k?: string) => resources.find(r => r.key === k)?.label ?? k
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-xl font-semibold text-gray-900">Add-ons</h1>
          <p className="text-sm text-gray-500">Extra capacity sold on top of any plan. Each has its own monthly and yearly price and expires on its own.</p></div>
        <div className="flex items-center gap-2"><PricingPdfButton /><Button onClick={() => open('new')}>+ New add-on</Button></div>
      </div>
      <div className="card"><div className="table-wrapper"><table className="table">
        <thead><tr><th>Add-on</th><th>Gives</th><th>Monthly</th><th>Yearly</th><th>Status</th><th /></tr></thead>
        <tbody>{rows.map(r => (
          <tr key={r.id}>
            <td><p className="font-medium">{r.name}</p><p className="text-xs text-gray-400">{r.group ? `${r.group.icon} ${r.group.title} · ` : ''}{r.slug}</p></td>
            <td className="text-sm">{r.type === 'limit' ? `+${fmt.number(r.config?.amount ?? 0)} ${label(r.config?.resource)}` : r.type}</td>
            <td>{r.billing_cycle === 'one_time' ? 'One time ' : ''}₹{fmt.number(Number(r.price))}</td>
            <td>{r.billing_cycle === 'one_time' ? '—' : r.price_yearly != null ? `₹${fmt.number(Number(r.price_yearly))}` : <span className="text-xs text-gray-400">₹{fmt.number(Number(r.price) * 12)} (12 × monthly)</span>}</td>
            <td><Badge variant={r.is_active ? 'green' : 'gray'}>{r.is_active ? 'active' : 'hidden'}</Badge></td>
            <td className="text-right"><Button size="sm" variant="secondary" onClick={() => open(r)}>Edit</Button></td>
          </tr>))}
          {!rows.length && <tr><td colSpan={6} className="py-8 text-center text-sm text-gray-500">No add-ons yet.</td></tr>}
        </tbody></table></div></div>

      <Modal open={!!edit} onClose={() => setEdit(null)} title={edit === 'new' ? 'New add-on' : 'Edit add-on'}>
        <div className="space-y-3">
          <Input label="Name" value={form.name} onChange={e => set('name', e.target.value)} />
          <Input label="Slug" value={form.slug} onChange={e => set('slug', e.target.value)} disabled={edit !== 'new'} />
          <Input label="Description" value={form.description} onChange={e => set('description', e.target.value)} />
          <label className="block text-sm font-medium text-gray-700">Type
            <select className="select mt-1 w-full" value={form.type} onChange={e => set('type', e.target.value)}>
              <option value="limit">Raise a limit</option><option value="feature">Feature</option><option value="message_pack">Message pack</option><option value="storage">Storage</option>
            </select></label>
          {form.type === 'limit' && (
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm font-medium text-gray-700">Which limit
                <select className="select mt-1 w-full" value={form.resource} onChange={e => set('resource', e.target.value)}>
                  <option value="">Choose…</option>{resources.map(r => <option key={r.key} value={r.key}>{r.label}{r.kind === 'monthly' ? ' (per month)' : ''}</option>)}
                </select></label>
              <Input label="Adds how many" type="number" min={1} value={form.amount} onChange={e => set('amount', e.target.value)} />
            </div>)}
          <label className="block text-sm font-medium text-gray-700">Billing
            <select className="select mt-1 w-full" value={form.billing_cycle} onChange={e => set('billing_cycle', e.target.value)}>
              <option value="monthly">Monthly or yearly (customer picks)</option><option value="one_time">One time (never expires)</option>
            </select></label>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Monthly price ₹" type="number" min={0} value={form.price} onChange={e => set('price', e.target.value)} />
            {form.billing_cycle !== 'one_time' && <Input label="Yearly price ₹" type="number" min={0} placeholder="blank = 12 × monthly" value={form.price_yearly} onChange={e => set('price_yearly', e.target.value)} />}
          </div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} /> Available to buy</label>
          <div className="flex justify-end gap-2 pt-2"><Button variant="secondary" onClick={() => setEdit(null)}>Cancel</Button><Button onClick={save} loading={saving}>Save</Button></div>
        </div>
      </Modal>
    </div>
  )
}
