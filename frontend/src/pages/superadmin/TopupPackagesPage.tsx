// src/pages/superadmin/TopupPackagesPage.tsx
import { useEffect, useState } from 'react'
import { planApi } from '@/api'
import { Button, Input, Modal, Badge, EmptyState, ConfirmModal } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

export default function TopupPackagesPage() {
  const [packages, setPackages] = useState<any[]>([])
  const [showModal,setShowModal]= useState(false)
  const [editItem, setEditItem] = useState<any>(null)
  const [delItem,  setDelItem]  = useState<any>(null)
  const [saving,   setSaving]   = useState(false)
  const [form, setForm] = useState({ messages: '', price: '', label: '', is_popular: false, is_active: true, sort_order: '' })
  const set = (k: string, v: any) => setForm(f => ({ ...f, [k]: v }))

  const load = () => planApi.saTopupPackages().then(r => setPackages(r.data.packages))
  useEffect(() => { load() }, [])

  const openCreate = () => { setEditItem(null); setForm({ messages:'', price:'', label:'', is_popular: false, is_active: true, sort_order:'' }); setShowModal(true) }
  const openEdit   = (p: any) => { setEditItem(p); setForm({ messages: p.messages, price: p.price, label: p.label || '', is_popular: p.is_popular, is_active: p.is_active, sort_order: p.sort_order }); setShowModal(true) }

  const handleSave = async () => {
    setSaving(true)
    try {
      const payload = { ...form, messages: +form.messages, price: +form.price, sort_order: form.sort_order ? +form.sort_order : undefined }
      if (editItem) { await planApi.saUpdateTopup(editItem.id, payload); toast.success('Updated.') }
      else          { await planApi.saCreateTopup(payload); toast.success('Package created.') }
      setShowModal(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleDelete = async () => {
    try { await planApi.saDeleteTopup(delItem.id); toast.success('Deleted.'); setDelItem(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Top-up Packages</h1><p className="page-sub">Message credit packages available to companies (100–10,000 msgs)</p></div>
        <Button onClick={openCreate}>+ Add package</Button>
      </div>
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
        {packages.map(p => (
          <div key={p.id} className={`card p-4 relative ${!p.is_active ? 'opacity-50' : ''}`}>
            {p.is_popular && <div className="absolute -top-2.5 left-1/2 -translate-x-1/2 bg-brand-500 text-white text-xs px-2 py-0.5 rounded-full">Popular</div>}
            <p className="text-xl font-bold text-gray-900">{fmt.number(p.messages)}</p>
            <p className="text-xs text-gray-400 mb-2">messages</p>
            <p className="text-lg font-semibold text-brand-600">₹{p.price}</p>
            {p.label && <p className="text-xs text-gray-400 mt-1">{p.label}</p>}
            <div className="flex gap-2 mt-3">
              <button onClick={() => openEdit(p)} className="text-xs text-blue-600 hover:underline">Edit</button>
              <button onClick={() => setDelItem(p)} className="text-xs text-red-500 hover:underline">Delete</button>
            </div>
          </div>
        ))}
        {packages.length === 0 && <div className="col-span-4"><EmptyState icon="📦" title="No packages" action={<Button onClick={openCreate}>Add package</Button>} /></div>}
      </div>
      <Modal open={showModal} onClose={() => setShowModal(false)} title={editItem ? 'Edit package' : 'Add top-up package'} size="sm"
        footer={<><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleSave} loading={saving}>Save</Button></>}>
        <div className="space-y-3">
          <Input label="Messages *" type="number" min={100} max={100000} value={form.messages} onChange={e => set('messages', e.target.value)} />
          <Input label="Price (₹) *" type="number" min={1} step={0.01} value={form.price} onChange={e => set('price', e.target.value)} />
          <Input label="Label (optional)" placeholder="5,000 messages" value={form.label} onChange={e => set('label', e.target.value)} />
          <Input label="Sort order" type="number" value={form.sort_order} onChange={e => set('sort_order', e.target.value)} />
          <div className="flex gap-4">
            <label className="flex items-center gap-2 text-sm cursor-pointer"><input type="checkbox" checked={form.is_popular} onChange={e => set('is_popular', e.target.checked)} /> Popular</label>
            <label className="flex items-center gap-2 text-sm cursor-pointer"><input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} /> Active</label>
          </div>
        </div>
      </Modal>
      <ConfirmModal open={!!delItem} title="Delete package?" message={`Delete ${fmt.number(delItem?.messages)} messages package?`}
        onConfirm={handleDelete} onCancel={() => setDelItem(null)} />
    </div>
  )
}
