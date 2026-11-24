// src/pages/staff/StaffPage.tsx
import { useLimit } from '@/lib/planUsage'
import LimitBadge from '@/components/plan/LimitBadge'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAppDispatch, useAppSelector, usePermission } from '@/store'
import { fetchStaffThunk } from '@/store/slices'
import { staffApi, staffAccessApi, labelApi, deviceApi, staffExtrasApi } from '@/api'
import { type AccessSection } from './AccessSectionPicker'
import {
  Button, Input, Select, Modal, ConfirmModal,
  Badge, EmptyState, Pagination, TableSkeleton, Spinner,
} from '@/components/ui'
import LinkedDevicesModal, { type DeviceService } from '@/components/devices/LinkedDevicesModal'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { pid } from '@/lib/publicId'

interface Role { id: number; name: string; label: string; access_main?: string | null; access_subs?: string[] }

export default function StaffPage() {
  const planLim = useLimit('users')
  const dispatch   = useAppDispatch()
  const { list, total, loading } = useAppSelector((s) => s.staff)
  const me = useAppSelector((s) => s.auth.user)
  const canLoginAs = usePermission('staff.login_as') || me?.role?.name === 'owner'   // the owner always passes; others need the "Sign in as staff" permission
  // Owner / permitted: switch into a staff member's account. Their own token is kept so the banner can switch back.
  const loginAs = async (u: any) => {
    try {
      const { data } = await staffExtrasApi.loginAs(u.id)
      const current = localStorage.getItem('wa_token')
      if (current && !localStorage.getItem('wa_original_token')) localStorage.setItem('wa_original_token', current)
      localStorage.setItem('wa_token', data.access_token)
      localStorage.setItem('wa_impersonation', JSON.stringify({ label: data.name, back: '/staff/index' }))
      toast.success(`Signing in as ${data.name}…`)
      setTimeout(() => { window.location.href = '/dashboard' }, 800)
    } catch (e) { toast.error(getError(e)) }
  }
  // Backend only defines 'staff.manage' (no create/edit/delete split).
  const canManage  = usePermission('staff.manage')
  const canCreate  = canManage
  const canEdit    = canManage
  const canDelete  = canManage
  const canManageAccess = canManage
  const navigate = useNavigate()

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

  // Access (sections + web / app) is managed on Staff Access, not here: a new member follows their role's sections until someone customises them.
  const [sections, setSections] = useState<AccessSection[]>([])
  const sectionLabel = (k?: string) => sections.find((s) => s.key === k)

  const [form, setForm] = useState({
    name: '', email: '', password: '', password_confirmation: '',
    role_id: '', department: '', max_leads: '50',
  })

  // Lead capacity only matters for accounts that handle leads (Sales, admins …) — hidden otherwise. Judged from the chosen role's sections
  // plus any the person already has.
  const pickedRole = roles.find((r) => String(r.id) === String(form.role_id))
  const accessKeys = [pickedRole?.access_main, ...(pickedRole?.access_subs ?? []), ...(editUser?.access_sections ?? [])].filter(Boolean) as string[]
  const handlesLeads = accessKeys.some((k) => {
    const perms = sections.find((s) => s.key === k)?.permissions ?? []
    return perms.includes('*') || perms.includes('leads.view')
  })
  const maxLeadsValue = handlesLeads ? Math.max(1, +form.max_leads || 50) : 0

  useEffect(() => {
    // Only this company's own roles — the platform's built-in system roles
    // (owner / admin / counsellor …) aren't assignable to company staff here.
    staffAccessApi.sections().then((r) => setSections(r.data.sections ?? [])).catch(() => {})
    staffApi.roles().then((r) => setRoles((r.data.roles ?? []).filter((x: any) => !x.is_system)))
  }, [])

  useEffect(() => {
    dispatch(fetchStaffThunk({ page, search, per_page: 20 }))
  }, [dispatch, page, search])

  // Profile extras: designation (a list the company grows), joining date, accessible users, documents
  const [extras, setExtras] = useState<{ designation: string; joined_on: string; accessible: number[] }>({ designation: '', joined_on: '', accessible: [] })
  const [designations, setDesignations] = useState<{ id: number; name: string }[]>([])
  const [docTypes, setDocTypes] = useState<{ id: number; name: string }[]>([])
  const [allStaff, setAllStaff] = useState<{ id: number; name: string }[]>([])
  const [docs, setDocs] = useState<any[]>([])
  const [docType, setDocType] = useState('')
  const [docFile, setDocFile] = useState<File | null>(null)
  const [docBusy, setDocBusy] = useState(false)
  useEffect(() => {
    if (!showModal) return
    staffExtrasApi.designations().then((r) => setDesignations(r.data.data ?? [])).catch(() => {})
    staffExtrasApi.documentTypes().then((r) => { setDocTypes(r.data.data ?? []); setDocType((t) => t || r.data.data?.[0]?.name || '') }).catch(() => {})
    staffApi.list({ per_page: 200 }).then((r) => setAllStaff((r.data.data ?? r.data.staff ?? []).map((x: any) => ({ id: x.id, name: x.name })))).catch(() => {})
  }, [showModal])
  const loadExtras = (id: number) => {
    staffExtrasApi.show(id).then((r) => setExtras({ designation: r.data.designation ?? '', joined_on: r.data.joined_on ?? '', accessible: r.data.accessible_user_ids ?? [] })).catch(() => {})
    staffExtrasApi.documents(id).then((r) => setDocs(r.data.data ?? [])).catch(() => {})
  }
  const addDocument = async () => {
    if (!editUser || !docFile || !docType) return
    setDocBusy(true)
    try { const r = await staffExtrasApi.upload(editUser.id, docType, docFile); setDocs(r.data.data ?? []); setDocFile(null) }
    catch (e) { toast.error(getError(e)) } finally { setDocBusy(false) }
  }
  const addDesignation = async () => {
    const name = window.prompt('New designation')?.trim()
    if (!name) return
    try { const r = await staffExtrasApi.addDesignation(name); setDesignations(r.data.data ?? []); setExtras((x) => ({ ...x, designation: name })) } catch (e) { toast.error(getError(e)) }
  }
  const addDocType = async () => {
    const name = window.prompt('New document type (max 30 characters)')?.trim()
    if (!name) return
    try { const r = await staffExtrasApi.addDocumentType(name); setDocTypes(r.data.data ?? []); setDocType(name) } catch (e) { toast.error(getError(e)) }
  }

  const openCreate = () => {
    setExtras({ designation: '', joined_on: '', accessible: [] }); setDocs([])
    setEditUser(null)
    setForm({ name:'', email:'', password:'', password_confirmation:'', role_id: roles[0]?.id?.toString() || '', department:'', max_leads:'50' })
    setShowModal(true)
  }

  const openEdit = (u: any) => {
    setEditUser(u)
    loadExtras(u.id)
    setForm({ name: u.name, email: u.email, password:'', password_confirmation:'', role_id: u.role?.id?.toString() || '', department: u.department || '', max_leads: u.max_leads?.toString() || '50' })
    setShowModal(true)
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      if (editUser) {
        const payload: any = { name: form.name, role_id: +form.role_id, department: form.department, max_leads: maxLeadsValue }
        if (form.password) { payload.password = form.password; payload.password_confirmation = form.password_confirmation }
        await staffApi.update(editUser.id, payload)
        await staffExtrasApi.update(editUser.id, { designation: extras.designation || null, joined_on: extras.joined_on || null, accessible_user_ids: extras.accessible })
        toast.success('Staff updated.')
      } else {
        const { data } = await staffApi.create({ ...form, role_id: +form.role_id, max_leads: maxLeadsValue })
        const newId = data?.staff?.id ?? data?.data?.id
        if (newId) await staffExtrasApi.update(newId, { designation: extras.designation || null, joined_on: extras.joined_on || null, accessible_user_ids: extras.accessible }).catch(() => {})
        toast.success('Staff created — set their access next.')
        setShowModal(false)
        if (newId && canManageAccess) { navigate(`/staff/access?staff=${pid(newId)}`); return }
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
        <div className="flex items-center gap-2"><LimitBadge resource="users" />{canCreate && <Button onClick={() => planLim.guard() && openCreate()}>+ Add staff</Button>}</div>
      </div>

      <div className="card">
        <div className="card-header gap-3">
          <Input placeholder="Search by name, email..." value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} className="max-w-xs" />
        </div>

        {loading ? <TableSkeleton rows={6} cols={5} /> : list.length === 0 ? (
          <EmptyState icon="👤" title="No staff yet" desc="Add your first team member" action={canCreate ? <Button onClick={() => planLim.guard() && openCreate()}>Add staff</Button> : undefined} />
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
                        <span className="text-gray-400">/{u.max_leads || '—'}</span>
                        <div className="w-16 h-1 bg-gray-100 rounded mt-1">
                          <div className="h-full bg-brand-400 rounded" style={{ width: `${Math.min(100, ((u.active_leads ?? 0) / (u.max_leads || 1)) * 100)}%` }} />
                        </div>
                      </div>
                    </td>
                    <td>
                      <Badge variant={u.is_active ? 'green' : 'gray'}>{u.is_active ? 'Active' : 'Inactive'}</Badge>
                    </td>
                    <td>
                      <div className="flex gap-1">
                        <button onClick={() => setDeviceUser(u)} className="text-xs text-indigo-600 hover:underline px-1">Devices</button>
                        {canLoginAs && u.id !== me?.id && u.role?.name !== 'owner' && u.is_active && (
                          <button onClick={() => loginAs(u)} className="text-xs text-emerald-600 hover:underline px-1" title="Sign in as this staff member">Login as</button>
                        )}
                        {canEdit && (
                          <>
                            <button onClick={() => openEdit(u)} className="text-xs text-blue-600 hover:underline px-1">Edit</button>
                            {canManageAccess && <Link to={`/staff/access?staff=${pid(u.id)}`} className="text-xs text-violet-600 hover:underline px-1">Access</Link>}
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
          {handlesLeads && (
            <Input label="Max leads" type="number" min={1} max={500} value={form.max_leads} onChange={(e) => set('max_leads', e.target.value)} />
          )}
        </div>

        <div className="mt-5 grid grid-cols-2 gap-4" data-testid="staff-extras">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">Designation</label>
            <div className="flex gap-2">
              <select className="input flex-1" value={extras.designation} onChange={(e) => setExtras({ ...extras, designation: e.target.value })}>
                <option value="">Select</option>
                {extras.designation && !designations.some((d) => d.name === extras.designation) && <option value={extras.designation}>{extras.designation}</option>}
                {designations.map((d) => <option key={d.id} value={d.name}>{d.name}</option>)}
              </select>
              <button type="button" onClick={addDesignation} className="px-3 rounded-lg bg-brand-600 text-white text-sm" title="Add a designation">+</button>
            </div>
          </div>
          <Input label="Joining date" type="date" value={extras.joined_on} onChange={(e) => setExtras({ ...extras, joined_on: e.target.value })} />
          <div className="col-span-2">
            <label className="block text-xs font-medium text-gray-600 mb-1">Accessible users <span className="font-normal text-gray-400">— whose leads {editUser?.name ?? 'this person'} can also see (besides their own)</span></label>
            <div className="max-h-32 overflow-y-auto border border-gray-200 rounded-lg p-2 grid grid-cols-2 gap-x-3">
              {allStaff.filter((p) => p.id !== editUser?.id).map((p) => (
                <label key={p.id} className="flex items-center gap-1.5 text-sm py-0.5">
                  <input type="checkbox" checked={extras.accessible.includes(p.id)} onChange={(e) => setExtras({ ...extras, accessible: e.target.checked ? [...extras.accessible, p.id] : extras.accessible.filter((x) => x !== p.id) })} /> {p.name}
                </label>
              ))}
              {allStaff.length === 0 && <span className="text-xs text-gray-400">No other staff yet.</span>}
            </div>
          </div>
        </div>

        <div className="mt-5" data-testid="staff-documents">
          <p className="text-sm font-semibold text-gray-800 mb-2">Documents</p>
          {!editUser ? <p className="text-xs text-gray-400">Save the staff member first, then edit them to upload documents.</p> : (
            <>
              <div className="flex flex-wrap items-end gap-2">
                <div className="flex gap-1">
                  <select className="input" value={docType} onChange={(e) => setDocType(e.target.value)}>{docTypes.map((t) => <option key={t.id} value={t.name}>{t.name}</option>)}</select>
                  <button type="button" onClick={addDocType} className="px-2.5 rounded-lg bg-gray-100 text-sm" title="Add a document type">+</button>
                </div>
                <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" onChange={(e) => setDocFile(e.target.files?.[0] ?? null)} className="text-xs" />
                <Button size="sm" onClick={addDocument} loading={docBusy} disabled={!docFile || !docType}>Upload</Button>
              </div>
              <ul className="mt-2 divide-y divide-gray-100 border border-gray-100 rounded-lg">
                {docs.length === 0 ? <li className="p-2 text-xs text-gray-400">No documents uploaded.</li> : docs.map((d) => (
                  <li key={d.id} className="flex items-center gap-2 px-2 py-1.5 text-sm">
                    <span className="text-[10px] px-1.5 py-0.5 rounded bg-gray-100">{d.type}</span>
                    <a href={d.url} target="_blank" rel="noreferrer" className="flex-1 truncate text-brand-600 hover:underline">{d.title}</a>
                    <button type="button" className="text-xs text-red-500" onClick={async () => { try { const r = await staffExtrasApi.removeDocument(editUser.id, d.id); setDocs(r.data.data ?? []) } catch (e) { toast.error(getError(e)) } }}>Remove</button>
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>

        <div className="mt-5 rounded-lg bg-gray-50 border border-gray-200 p-3 text-xs text-gray-600" data-testid="access-moved">
          <p>
            <b>Access sections</b> follow the role
            {pickedRole?.access_main || (pickedRole?.access_subs ?? []).length
              ? <> ({[pickedRole?.access_main, ...(pickedRole?.access_subs ?? [])].filter(Boolean).map((k) => sectionLabel(k as string)?.label ?? k).join(', ')})</>
              : null}
            {' '}and change automatically when the role changes.
            {canManageAccess && editUser && <> To customise them, or set web / app access and screens, use <Link to={`/staff/access?staff=${pid(editUser.id)}`} className="text-brand-600 underline">Staff Access</Link>.</>}
            {canManageAccess && !editUser && ' After you create the account you land on Staff Access to set web / app access and screens.'}
          </p>
        </div>
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
