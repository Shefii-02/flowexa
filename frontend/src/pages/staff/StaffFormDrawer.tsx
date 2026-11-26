// src/pages/staff/StaffFormDrawer.tsx
// The Add / Edit staff form — one drawer used by the Staff page and by Settings → Staff, so both always look and behave the same.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { staffApi, staffAccessApi, staffExtrasApi } from '@/api'
import { type AccessSection } from './AccessSectionPicker'
import { Button, Input, PasswordInput, Select, Drawer } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { pid } from '@/lib/publicId'
import StaffPersonalFields, { blankPersonal, personalFromApi, type PersonalForm } from './StaffPersonalFields'

interface Role { id: number; name: string; label: string; access_main?: string | null; access_subs?: string[] }

interface Props {
  open: boolean
  onClose: () => void
  /** null / undefined = add a new member */
  editUser?: any
  /** can this person open Staff Access afterwards? (decides the hint and the link) */
  canManageAccess?: boolean
  /** called after a successful save — the parent closes the drawer, reloads its list and (for a new member) may go to Staff Access */
  onSaved: (r: { created: boolean; id?: number }) => void
}

export default function StaffFormDrawer({ open, onClose, editUser = null, canManageAccess = false, onSaved }: Props) {
  const [roles, setRoles] = useState<Role[]>([])
  const [sections, setSections] = useState<AccessSection[]>([])
  const [saving, setSaving] = useState(false)
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


  // Profile extras: designation (a list the company grows), joining date, accessible users, documents
  const [extras, setExtras] = useState<{ designation: string; joined_on: string; accessible: number[] }>({ designation: '', joined_on: '', accessible: [] })
  const [designations, setDesignations] = useState<{ id: number; name: string }[]>([])
  const [docTypes, setDocTypes] = useState<{ id: number; name: string }[]>([])
  const [allStaff, setAllStaff] = useState<{ id: number; name: string }[]>([])
  const [personal, setPersonal] = useState<PersonalForm>(blankPersonal())
  const [docs, setDocs] = useState<any[]>([])
  const [docType, setDocType] = useState('')
  const [docFile, setDocFile] = useState<File | null>(null)
  const [docBusy, setDocBusy] = useState(false)
  useEffect(() => {
    if (!open) return
    staffExtrasApi.designations().then((r) => setDesignations(r.data.data ?? [])).catch(() => {})
    staffExtrasApi.documentTypes().then((r) => { setDocTypes(r.data.data ?? []); setDocType((t) => t || r.data.data?.[0]?.name || '') }).catch(() => {})
    staffApi.list({ per_page: 200 }).then((r) => setAllStaff((r.data.data ?? r.data.staff ?? []).map((x: any) => ({ id: x.id, name: x.name })))).catch(() => {})
  }, [open])
  const loadExtras = (id: number) => {
    staffExtrasApi.show(id).then((r) => setExtras({ designation: r.data.designation ?? '', joined_on: r.data.joined_on ?? '', accessible: r.data.accessible_user_ids ?? [] })).catch(() => {})
    staffExtrasApi.documents(id).then((r) => setDocs(r.data.data ?? [])).catch(() => {})
    staffExtrasApi.personal(id).then((r) => setPersonal(personalFromApi(r.data))).catch(() => {})
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

  const blankForm = () => ({ name: '', email: '', password: '', password_confirmation: '', role_id: roles[0]?.id?.toString() || '', department: '', max_leads: '50' })

  // each time the drawer opens: a blank form for a new member, or the person's own details
  useEffect(() => {
    if (!open) return
    if (editUser) {
      loadExtras(editUser.id)
      setForm({ name: editUser.name, email: editUser.email, password: '', password_confirmation: '', role_id: editUser.role?.id?.toString() || '', department: editUser.department || '', max_leads: editUser.max_leads?.toString() || '50' })
    } else {
      setExtras({ designation: '', joined_on: '', accessible: [] }); setDocs([]); setPersonal(blankPersonal())
      setForm(blankForm())
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, editUser?.id])

  // the default role for a new member arrives with the roles list
  useEffect(() => {
    if (open && !editUser && roles.length) setForm((f) => (f.role_id ? f : { ...f, role_id: roles[0].id.toString() }))
  }, [open, editUser, roles])

  const handleSave = async () => {
    setSaving(true)
    try {
      if (editUser) {
        const payload: any = { name: form.name, role_id: +form.role_id, department: form.department, max_leads: maxLeadsValue }
        if (form.password) { payload.password = form.password; payload.password_confirmation = form.password_confirmation }
        await staffApi.update(editUser.id, payload)
        await staffExtrasApi.update(editUser.id, { designation: extras.designation || null, joined_on: extras.joined_on || null, accessible_user_ids: extras.accessible })
        await staffExtrasApi.updatePersonal(editUser.id, personal)
        toast.success('Staff updated.')
      } else {
        const { data } = await staffApi.create({ ...form, role_id: +form.role_id, max_leads: maxLeadsValue })
        const newId = data?.staff?.id ?? data?.data?.id
        if (newId) await staffExtrasApi.update(newId, { designation: extras.designation || null, joined_on: extras.joined_on || null, accessible_user_ids: extras.accessible }).catch(() => {})
        if (newId) await staffExtrasApi.updatePersonal(newId, personal).catch(() => {})
        toast.success('Staff created — set their access next.')
        onSaved({ created: true, id: newId })
        return
      }
      onSaved({ created: false, id: editUser.id })
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }))
  const roleOptions = [{ value: '', label: '— Select role —' }, ...roles.map((r) => ({ value: r.id, label: r.label }))]

  return (
    <>
    <Drawer open={open} onClose={onClose} title={editUser ? 'Edit staff' : 'Add staff'} size="xl"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button onClick={handleSave} loading={saving}>{editUser ? 'Save changes' : 'Create staff'}</Button>
        </>
      }
    >
      <div className="grid grid-cols-2 gap-4">
        <Input label="Full name" value={form.name} onChange={(e) => set('name', e.target.value)} required />
        <Input label="Email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} disabled={!!editUser} required={!editUser} />
        <PasswordInput label={editUser ? 'New password (optional)' : 'Password'} autoComplete="new-password" value={form.password} onChange={(e) => set('password', e.target.value)} required={!editUser} />
        <PasswordInput label="Confirm password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} required={!editUser} />
        <Select label="Role" value={form.role_id} onChange={(e) => set('role_id', e.target.value)} options={roleOptions} />
        <Input label="Department" placeholder="Sales, Support..." value={form.department} onChange={(e) => set('department', e.target.value)} />
        {handlesLeads && (
          <Input label="Max leads" type="number" min={1} value={form.max_leads} onChange={(e) => set('max_leads', e.target.value)} />
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

      <div className="mt-5"><StaffPersonalFields value={personal} onChange={setPersonal} /></div>

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
    </Drawer>
    </>
  )
}
