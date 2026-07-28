// src/pages/superadmin/SuperAdminStaffPage.tsx
import { useEffect, useState } from 'react'
import { saStaffApi } from '@/api'
import { Button, Input, Modal, Badge, EmptyState, ConfirmModal, TableSkeleton } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

export default function SuperAdminStaffPage() {
  const [list,    setList]    = useState<any[]>([])
  const [loading, setLoading] = useState(true)
  const [showModal,setShowModal] = useState(false)
  const [editItem, setEditItem]  = useState<any>(null)
  const [delItem,  setDelItem]   = useState<any>(null)
  const [saving,   setSaving]    = useState(false)
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'superadmin_staff' })
  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))

  const load = () => { setLoading(true); saStaffApi.list().then(r => setList(r.data.data)).finally(() => setLoading(false)) }
  useEffect(() => { load() }, [])

  const openCreate = () => { setEditItem(null); setForm({ name:'', email:'', password:'', role:'superadmin_staff' }); setShowModal(true) }
  const openEdit   = (s: any) => { setEditItem(s); setForm({ name: s.name, email: s.email, password: '', role: s.role?.name || 'superadmin_staff' }); setShowModal(true) }

  const handleSave = async () => {
    setSaving(true)
    try {
      if (editItem) { await saStaffApi.update(editItem.id, { name: form.name, ...(form.password ? { password: form.password } : {}) }); toast.success('Updated.') }
      else          { await saStaffApi.create(form); toast.success('Staff created.') }
      setShowModal(false); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleToggle = async (s: any) => {
    try { await saStaffApi.toggle(s.id); toast.success(s.is_active ? 'Deactivated.' : 'Activated.'); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const handleDelete = async () => {
    try { await saStaffApi.delete(delItem.id); toast.success('Staff removed.'); setDelItem(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Platform Staff</h1><p className="page-sub">Superadmin team members</p></div>
        <Button onClick={openCreate}>+ Add staff</Button>
      </div>
      <div className="card">
        {loading ? <TableSkeleton rows={5} cols={4} /> : list.length === 0 ? (
          <EmptyState icon="👤" title="No platform staff" desc="Add team members who can manage the platform"
            action={<Button onClick={openCreate}>Add staff</Button>} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                {list.map((s: any) => (
                  <tr key={s.id}>
                    <td className="font-medium">{s.name}</td>
                    <td className="text-gray-500 text-sm">{s.email}</td>
                    <td><Badge variant="purple">{s.role?.label || 'Platform Staff'}</Badge></td>
                    <td><Badge variant={s.is_active ? 'green' : 'gray'}>{s.is_active ? 'Active' : 'Inactive'}</Badge></td>
                    <td>
                      <div className="flex gap-1">
                        {s.role?.name !== 'superadmin' && (
                          <>
                            <button onClick={() => openEdit(s)} className="text-xs text-blue-600 hover:underline">Edit</button>
                            <button onClick={() => handleToggle(s)} className="text-xs text-gray-500 hover:underline">{s.is_active ? 'Deactivate' : 'Activate'}</button>
                            <button onClick={() => setDelItem(s)} className="text-xs text-red-500 hover:underline">Remove</button>
                          </>
                        )}
                        {s.role?.name === 'superadmin' && <span className="text-xs text-gray-300">Protected</span>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
      <Modal open={showModal} onClose={() => setShowModal(false)} title={editItem ? 'Edit staff' : 'Add platform staff'} size="sm"
        footer={<><Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button><Button onClick={handleSave} loading={saving}>{editItem ? 'Save' : 'Create'}</Button></>}>
        <Input label="Full name" value={form.name} onChange={e => set('name', e.target.value)} required />
        {!editItem && <Input label="Email" type="email" value={form.email} onChange={e => set('email', e.target.value)} required className="mt-3" />}
        <Input label={editItem ? 'New password (optional)' : 'Password'} type="password" value={form.password} onChange={e => set('password', e.target.value)} required={!editItem} className="mt-3" />
      </Modal>
      <ConfirmModal open={!!delItem} title="Remove staff?" message={`Remove ${delItem?.name} from platform staff?`}
        onConfirm={handleDelete} onCancel={() => setDelItem(null)} />
    </div>
  )
}
