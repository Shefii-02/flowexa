// src/pages/staff/StaffPage.tsx
import { useEffect, useMemo, useState } from 'react'
import { useAppDispatch, useAppSelector, usePermission } from '@/store'
import { fetchStaffThunk } from '@/store/slices'
import { staffApi, staffAccessApi, labelApi, deviceApi } from '@/api'
import AccessSectionPicker, { type AccessSection } from './AccessSectionPicker'
import {
  Button, Input, Select, Modal, ConfirmModal,
  Badge, EmptyState, Pagination, TableSkeleton, Spinner,
} from '@/components/ui'
import LinkedDevicesModal, { type DeviceService } from '@/components/devices/LinkedDevicesModal'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

interface Role { id: number; name: string; label: string }

export default function StaffPage() {
  const dispatch   = useAppDispatch()
  const { list, total, loading } = useAppSelector((s) => s.staff)
  // Backend only defines 'staff.manage' (no create/edit/delete split).
  const canManage  = usePermission('staff.manage')
  const canCreate  = canManage
  const canEdit    = canManage
  const canDelete  = canManage
  const canManageAccess = canManage

  const [deviceUser, setDeviceUser] = useState<any>(null)
  const deviceService: DeviceService | null = useMemo(() => {
    if (!deviceUser) return null
    const uid = deviceUser.id
    return {
      list: () => deviceApi.userDevices(uid).then((r) => r.data),
      createChallenge: () => deviceApi.createChallenge(uid).then((r) => r.data),
      challengeStatus: (id) => deviceApi.challengeStatus(id).then((r) => r.data),
      revoke: (deviceId) => deviceApi.revoke(deviceId).then(() => undefined),
    }
  }, [deviceUser])

  const [page,    setPage]    = useState(1)
  const [search,  setSearch]  = useState('')
  const [roles,   setRoles]   = useState<Role[]>([])
  const [showModal, setShowModal] = useState(false)
  const [editUser,  setEditUser]  = useState<any>(null)
  const [delUser,   setDelUser]   = useState<any>(null)
  const [saving,    setSaving]    = useState(false)
  const [deleting,  setDeleting]  = useState(false)

  // Access sections (main + subs) and channels, saved together with the staff record.
  const [sections, setSections] = useState<AccessSection[]>([])
  const [access, setAccess] = useState<{ main: string | null; subs: string[]; portal: boolean; mobile: boolean }>(
    { main: null, subs: [], portal: true, mobile: true })
  const sectionLabel = (k?: string) => sections.find((s) => s.key === k)

  const [form, setForm] = useState({
    name: '', email: '', password: '', password_confirmation: '',
    role_id: '', department: '', max_leads: '50',
  })

  useEffect(() => {
    // Only this company's own roles — the platform's built-in system roles
    // (owner / admin / counsellor …) aren't assignable to company staff here.
    staffAccessApi.sections().then((r) => setSections(r.data.sections ?? [])).catch(() => {})
    staffApi.roles().then((r) => setRoles((r.data.roles ?? []).filter((x: any) => !x.is_system)))
  }, [])

  useEffect(() => {
    dispatch(fetchStaffThunk({ page, search, per_page: 20 }))
  }, [dispatch, page, search])

  const openCreate = () => {
    setEditUser(null)
    setAccess({ main: null, subs: [], portal: true, mobile: true })
    setForm({ name:'', email:'', password:'', password_confirmation:'', role_id: roles[0]?.id?.toString() || '', department:'', max_leads:'50' })
    setShowModal(true)
  }

  const openEdit = (u: any) => {
    setEditUser(u)
    setAccess({ main: u.access_sections?.[0] ?? null, subs: (u.access_sections ?? []).slice(1), portal: u.portal_access ?? true, mobile: u.mobile_access ?? true })
    setForm({ name: u.name, email: u.email, password:'', password_confirmation:'', role_id: u.role?.id?.toString() || '', department: u.department || '', max_leads: u.max_leads?.toString() || '50' })
    setShowModal(true)
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      if (editUser) {
        const payload: any = { name: form.name, role_id: +form.role_id, department: form.department, max_leads: +form.max_leads }
        if (form.password) { payload.password = form.password; payload.password_confirmation = form.password_confirmation }
        await staffApi.update(editUser.id, payload)
        await staffAccessApi.update(editUser.id, { portal_access: access.portal, mobile_access: access.mobile, main: access.main, subs: access.subs })
        toast.success('Staff updated.')
      } else {
        const { data } = await staffApi.create({ ...form, role_id: +form.role_id, max_leads: +form.max_leads })
        const newId = data?.staff?.id ?? data?.data?.id
        if (newId) await staffAccessApi.update(newId, { portal_access: access.portal, mobile_access: access.mobile, main: access.main, subs: access.subs })
        toast.success('Staff created.')
      }
      setShowModal(false)
      dispatch(fetchStaffThunk({ page, search, per_page: 20 }))
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleToggle = async (u: any) => {
    try {
      await staffApi.toggle(u.id)
      toast.success(u.is_active ? 'Staff deactivated.' : 'Staff activated.')
      dispatch(fetchStaffThunk({ page, search, per_page: 20 }))
    } catch (e) { toast.error(getError(e)) }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await staffApi.delete(delUser.id)
      toast.success('Staff removed.')
      setDelUser(null)
      dispatch(fetchStaffThunk({ page, search, per_page: 20 }))
    } catch (e) { toast.error(getError(e)) }
    finally     { setDeleting(false) }
  }

  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }))

  const roleOptions = [{ value: '', label: '— Select role —' }, ...roles.map((r) => ({ value: r.id, label: r.label }))]

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Staff</h1><p className="page-sub">{total} team members</p></div>
        {canCreate && <Button onClick={openCreate}>+ Add staff</Button>}
      </div>

      <div className="card">
        <div className="card-header gap-3">
          <Input placeholder="Search by name, email..." value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} className="max-w-xs" />
        </div>

        {loading ? <TableSkeleton rows={6} cols={5} /> : list.length === 0 ? (
          <EmptyState icon="👤" title="No staff yet" desc="Add your first team member" action={canCreate ? <Button onClick={openCreate}>Add staff</Button> : undefined} />
        ) : (
          <div className="table-wrapper">
            <table className="table">
              <thead>
                <tr>
                  <th>Name</th><th>Role</th><th>Access</th><th>Department</th><th>Leads</th><th>Status</th><th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {list.map((u) => (
                  <tr key={u.id}>
                    <td>
                      <div className="flex items-center gap-2">
                        <div className="w-7 h-7 rounded-full bg-brand-100 flex items-center justify-center text-xs font-medium text-brand-700">
                          {u.name?.charAt(0).toUpperCase()}
                        </div>
                        <div>
                          <p className="font-medium text-gray-900">{u.name}</p>
                          <p className="text-xs text-gray-400">{u.email}</p>
                        </div>
                      </div>
                    </td>
                    <td><Badge variant="blue">{u.role?.label}</Badge></td>
                    <td>
                      <div className="flex flex-wrap items-center gap-1">
                        {(u.access_sections ?? []).map((k: string, i: number) => {
                          const sec = sectionLabel(k)
                          return <Badge key={k} variant={i === 0 ? 'blue' : 'gray'}>{sec ? `${sec.icon} ${sec.label}` : k}</Badge>
                        })}
                        {u.portal_access === false && <span title="Web portal off" className="text-xs line-through text-gray-400">🖥️</span>}
                        {u.mobile_access === false && <span title="Mobile app off" className="text-xs line-through text-gray-400">📱</span>}
                      </div>
                    </td>
                    <td className="text-gray-600">{u.department || '—'}</td>
                    <td>
                      <div className="text-xs">
                        <span className="font-medium">{u.active_leads ?? 0}</span>
                        <span className="text-gray-400">/{u.max_leads}</span>
                        <div className="w-16 h-1 bg-gray-100 rounded mt-1">
                          <div className="h-full bg-brand-400 rounded" style={{ width: `${Math.min(100, ((u.active_leads ?? 0) / u.max_leads) * 100)}%` }} />
                        </div>
                      </div>
                    </td>
                    <td>
                      <Badge variant={u.is_active ? 'green' : 'gray'}>{u.is_active ? 'Active' : 'Inactive'}</Badge>
                    </td>
                    <td>
                      <div className="flex gap-1">
                        <button onClick={() => setDeviceUser(u)} className="text-xs text-indigo-600 hover:underline px-1">Devices</button>
                        {canEdit && (
                          <>
                            <button onClick={() => openEdit(u)} className="text-xs text-blue-600 hover:underline px-1">Edit</button>
                            <button onClick={() => handleToggle(u)} className="text-xs text-gray-500 hover:underline px-1">
                              {u.is_active ? 'Deactivate' : 'Activate'}
                            </button>
                          </>
                        )}
                        {canDelete && (
                          <button onClick={() => setDelUser(u)} className="text-xs text-red-500 hover:underline px-1">Remove</button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
          </div>
        )}
      </div>

      {/* Create / Edit Modal */}
      <Modal open={showModal} onClose={() => setShowModal(false)} title={editUser ? 'Edit staff' : 'Add staff'} size="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving}>{editUser ? 'Save changes' : 'Create staff'}</Button>
          </>
        }
      >
        <div className="grid grid-cols-2 gap-4">
          <Input label="Full name" value={form.name} onChange={(e) => set('name', e.target.value)} required />
          <Input label="Email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} disabled={!!editUser} required={!editUser} />
          <Input label={editUser ? 'New password (optional)' : 'Password'} type="password" value={form.password} onChange={(e) => set('password', e.target.value)} required={!editUser} />
          <Input label="Confirm password" type="password" value={form.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} required={!editUser} />
          <Select label="Role" value={form.role_id} onChange={(e) => set('role_id', e.target.value)} options={roleOptions} />
          <Input label="Department" placeholder="Sales, Support..." value={form.department} onChange={(e) => set('department', e.target.value)} />
          <Input label="Max leads" type="number" min={1} max={500} value={form.max_leads} onChange={(e) => set('max_leads', e.target.value)} />
        </div>

        {canManageAccess && (
          <div className="mt-5 space-y-3">
            <p className="text-sm font-semibold text-gray-800">Access</p>
            <div className="flex gap-5 text-sm text-gray-700">
              <label className="flex items-center gap-2"><input type="checkbox" checked={access.portal} onChange={(e) => setAccess((a) => ({ ...a, portal: e.target.checked }))} /> 🖥️ Web portal</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={access.mobile} onChange={(e) => setAccess((a) => ({ ...a, mobile: e.target.checked }))} /> 📱 Mobile app</label>
            </div>
            <p className="text-xs text-gray-400">Choose one main section, tick any extra sub sections. They add to what the role allows and decide the home screen they see.</p>
            <AccessSectionPicker compact sections={sections} main={access.main} subs={access.subs}
              onChange={(n) => setAccess((a) => ({ ...a, ...n }))} />
          </div>
        )}
      </Modal>

      {/* Linked devices */}
      <LinkedDevicesModal
        open={!!deviceService}
        onClose={() => setDeviceUser(null)}
        title={deviceUser ? `${deviceUser.name} — linked devices` : 'Linked devices'}
        subtitle="Active phone-app devices for this staff member. Start a QR/PIN login to link a new one."
        service={deviceService ?? { list: async () => ({ max: 0, active: 0, devices: [] }), createChallenge: async () => { throw new Error('n/a') }, revoke: async () => undefined }}
      />

      {/* Delete Confirm */}
      <ConfirmModal
        open={!!delUser}
        title="Remove staff member?"
        message={`This will remove ${delUser?.name} and unassign all their leads.`}
        onConfirm={handleDelete}
        onCancel={() => setDelUser(null)}
        loading={deleting}
      />
    </div>
  )
}
