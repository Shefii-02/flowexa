import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, Input, Select, Modal, ConfirmModal, Badge, EmptyState, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { GST_RATES, inr } from './shared'

const EMPTY = { name: '', sku: '', hsn: '', unit: 'NOS', gst_rate: 18, sale_rate: '', purchase_rate: '', track_stock: true, opening_qty: '', opening_value: '', reorder_level: '' }

/** Products and services. Stock-tracked items show the quantity and value on hand (weighted-average cost). */
export default function ItemsTab() {
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [form, setForm] = useState<any>(null)
  const [del, setDel] = useState<any>(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    accountsApi.items({ search: search || undefined }).then(r => setRows(r.data.items)).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [search])
  useEffect(() => { load() }, [load])

  const set = (k: string, v: any) => setForm((f: any) => ({ ...f, [k]: v }))
  const num = (v: any) => (v === '' || v == null ? null : Number(v))
  const totalValue = rows.reduce((s, r) => s + (r.stock_value || 0), 0)

  const save = async () => {
    setSaving(true)
    try {
      const body = { ...form, gst_rate: Number(form.gst_rate), sale_rate: num(form.sale_rate) ?? 0, purchase_rate: num(form.purchase_rate) ?? 0,
        opening_qty: num(form.opening_qty) ?? 0, opening_value: num(form.opening_value) ?? 0, reorder_level: num(form.reorder_level) }
      form.id ? await accountsApi.updateItem(form.id, body) : await accountsApi.createItem(body)
      toast.success('Saved.'); setForm(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }
  const remove = async () => { try { await accountsApi.deleteItem(del.id); toast.success('Deleted.'); setDel(null); load() } catch (e) { toast.error(getError(e)); setDel(null) } }

  return (
    <div className="card">
      <div className="card-header gap-3 flex-wrap">
        <input className="input max-w-[220px]" placeholder="Search items…" value={search} onChange={e => setSearch(e.target.value)} />
        <span className="text-sm text-gray-500">Stock value: <b>{inr(totalValue)}</b></span>
        <div className="ml-auto"><Button onClick={() => setForm({ ...EMPTY })}>+ New item</Button></div>
      </div>
      {loading ? <TableSkeleton rows={5} cols={6} /> : rows.length === 0 ? <EmptyState icon="📦" title="No items yet" desc="Add products or services to pick them on invoices and bills." /> : (
        <div className="table-wrapper"><table className="table">
          <thead><tr><th>Item</th><th>HSN/SAC</th><th className="text-right">Sale rate</th><th className="text-right">In stock</th><th className="text-right">Value</th><th /></tr></thead>
          <tbody>{rows.map(r => (
            <tr key={r.id}>
              <td><p className="font-medium">{r.name}{!r.is_active && <span className="text-xs text-gray-400"> (inactive)</span>}</p><p className="text-xs text-gray-400">{r.sku || ''} · GST {r.gst_rate}%</p></td>
              <td className="font-mono text-xs">{r.hsn || '—'}</td>
              <td className="text-right">{inr(r.sale_rate)}</td>
              <td className="text-right">{r.track_stock ? <>{r.stock_qty ?? 0} {r.unit} {r.low && <Badge variant="red">Low</Badge>}</> : <span className="text-gray-400">Service</span>}</td>
              <td className="text-right">{r.track_stock ? inr(r.stock_value) : '—'}</td>
              <td className="text-right whitespace-nowrap">
                <button className="text-xs text-gray-600 hover:underline mr-2" onClick={() => setForm({ ...r, sku: r.sku || '', hsn: r.hsn || '', sale_rate: r.sale_rate || '', purchase_rate: r.purchase_rate || '', opening_qty: r.opening_qty || '', opening_value: r.opening_value || '', reorder_level: r.reorder_level ?? '' })}>Edit</button>
                <button className="text-xs text-red-500 hover:underline" onClick={() => setDel(r)}>Delete</button>
              </td>
            </tr>))}</tbody>
        </table></div>
      )}
      <Modal open={!!form} onClose={() => setForm(null)} title={form?.id ? 'Edit item' : 'New item'} footer={<><Button variant="secondary" onClick={() => setForm(null)}>Cancel</Button><Button onClick={save} loading={saving}>Save</Button></>}>
        {form && (
          <div className="grid sm:grid-cols-2 gap-4">
            <Input label="Name" value={form.name} onChange={e => set('name', e.target.value)} />
            <Input label="SKU / code" value={form.sku} onChange={e => set('sku', e.target.value)} />
            <Input label="HSN / SAC" value={form.hsn} onChange={e => set('hsn', e.target.value)} />
            <Input label="Unit" value={form.unit} onChange={e => set('unit', e.target.value.toUpperCase())} />
            <Select label="GST %" value={String(form.gst_rate)} onChange={e => set('gst_rate', e.target.value)} options={GST_RATES.map(r => ({ value: String(r), label: `${r}%` }))} />
            <Input label="Sale rate" type="number" min="0" value={form.sale_rate} onChange={e => set('sale_rate', e.target.value)} />
            <Input label="Purchase rate" type="number" min="0" value={form.purchase_rate} onChange={e => set('purchase_rate', e.target.value)} />
            <label className="flex items-center gap-2 text-sm mt-6"><input type="checkbox" checked={form.track_stock} onChange={e => set('track_stock', e.target.checked)} /> Track stock (untick for a service)</label>
            {form.track_stock && <>
              <Input label="Opening quantity" type="number" min="0" value={form.opening_qty} onChange={e => set('opening_qty', e.target.value)} />
              <Input label="Opening value (total cost)" type="number" min="0" value={form.opening_value} onChange={e => set('opening_value', e.target.value)} />
              <Input label="Reorder level (low-stock alert)" type="number" min="0" value={form.reorder_level} onChange={e => set('reorder_level', e.target.value)} />
            </>}
            {form.id && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} /> Active</label>}
            <p className="text-xs text-gray-500 sm:col-span-2">Stock is a quantity and value report (weighted-average cost). The profit &amp; loss stays on a purchases basis; no closing-stock entry is posted.</p>
          </div>
        )}
      </Modal>
      <ConfirmModal open={!!del} title="Delete item?" message={`Delete "${del?.name}"?`} onConfirm={remove} onCancel={() => setDel(null)} />
    </div>
  )
}
