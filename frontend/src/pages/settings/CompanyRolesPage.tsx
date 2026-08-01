// src/pages/settings/CompanyRolesPage.tsx
import { useEffect, useState, useCallback } from 'react'

import { Button, Input, Modal, ConfirmModal, Badge, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { roleApi } from '@/api'

const ALL_PERMISSIONS = [
  { group:'Contacts',   perms:[['contacts.view','View contacts'],['contacts.create','Create contacts'],['contacts.edit','Edit contacts'],['contacts.delete','Delete contacts'],['contacts.import','Import CSV'],['contacts.export','Export CSV']] },
  { group:'Leads',      perms:[['leads.view_own','View own leads'],['leads.view_all','View all leads'],['leads.create','Create leads'],['leads.edit','Edit leads'],['leads.assign','Assign leads'],['leads.delete','Delete leads']] },
  { group:'Campaigns',  perms:[['campaigns.view','View campaigns'],['campaigns.create','Create campaigns'],['campaigns.launch','Launch campaigns'],['campaigns.delete','Delete campaigns']] },
  { group:'Templates',  perms:[['templates.view','View templates'],['templates.create','Create & submit to Meta'],['templates.delete','Delete templates']] },
  { group:'Flow',       perms:[['flow.view','View flow builder'],['flow.edit','Edit flow nodes'],['flow.activate','Activate flows']] },
  { group:'Analytics',  perms:[['analytics.view_own','View own analytics'],['analytics.view_all','View all analytics']] },
  { group:'Settings',   perms:[['settings.view','View settings'],['settings.manage','Manage WA credentials'],['settings.staff','Manage staff']] },
  { group:'Wallet',     perms:[['wallet.view','View wallet'],['wallet.topup','Topup wallet']] },
]

export default function CompanyRolesPage() {
  const [roles,     setRoles]     = useState<any[]>([])
  const [loading,   setLoading]   = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [editRole,  setEditRole]  = useState<any>(null)
  const [delRole,   setDelRole]   = useState<any>(null)
  const [saving,    setSaving]    = useState(false)
  const [form,      setForm]      = useState({ label:'', permissions:[] as string[] })

  const load = useCallback(() => {
    setLoading(true)
    roleApi.companyRoles()
      .then(r => setRoles(r.data.roles || []))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { load() }, [load])

  const openCreate = () => { setEditRole(null); setForm({ label:'', permissions:[] }); setShowModal(true) }
  const openEdit   = (r:any) => { setEditRole(r); setForm({ label:r.label, permissions:JSON.parse(r.permissions||'[]') }); setShowModal(true) }

  const togglePerm = (p:string) =>
    setForm(f => ({
      ...f,
      permissions: f.permissions.includes(p)
        ? f.permissions.filter(x => x !== p)
        : [...f.permissions, p],
    }))

  const toggleGroup = (perms: string[][]) => {
    const keys = perms.map(p => p[0])
    const allChecked = keys.every(k => form.permissions.includes(k))
    setForm(f => ({
      ...f,
      permissions: allChecked
        ? f.permissions.filter(p => !keys.includes(p))
        : [...new Set([...f.permissions, ...keys])],
    }))
  }

  const handleSave = async () => {
    if (!form.label.trim()) { toast.error('Role name required'); return }
    if (form.permissions.length === 0) { toast.error('Select at least one permission'); return }
    setSaving(true)
    try {
      if (editRole) {
        await roleApi.updateCompanyRole(editRole.id, form)
        toast.success('Role updated.')
      } else {
        await roleApi.createCompanyRole(form)
        toast.success('Role created.')
      }
      setShowModal(false); load()
    } catch(e) { toast.error(getError(e)) }
    finally    { setSaving(false) }
  }

  const handleDelete = async () => {
    try {
      await roleApi.deleteCompanyRole(delRole.id)
      toast.success('Role deleted.')
      setDelRole(null); load()
    } catch(e) { toast.error(getError(e)) }
  }

  const systemRoles = roles.filter(r => r.is_system)
  const customRoles = roles.filter(r => !r.is_system)

  return (
    <div className="space-y-5 max-w-4xl">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">Roles & Permissions</h1>
          <p className="page-sub">Manage staff access levels for your company</p>
        </div>
        <Button onClick={openCreate}>+ Custom role</Button>
      </div>

      {/* System roles */}
      <div>
        <p className="text-sm font-semibold text-gray-500 mb-3">
          System roles <span className="text-xs font-normal text-gray-400">— auto-created, cannot be deleted</span>
        </p>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {systemRoles.map(r => {
            const perms = JSON.parse(r.permissions || '[]') as string[]
            const hasAll = perms.includes('*')
            return (
              <div key={r.id} className="card p-4 border border-gray-200">
                <div className="flex items-center justify-between mb-3">
                  <div className="flex items-center gap-2">
                    <Badge variant="blue">{r.label}</Badge>
                    <Badge variant="gray">System</Badge>
                  </div>
                  <button onClick={() => openEdit(r)} className="text-xs text-blue-600 hover:underline">Edit permissions</button>
                </div>
                {hasAll ? (
                  <p className="text-xs text-brand-600 font-semibold">✅ Full access — all permissions</p>
                ) : (
                  <div className="flex flex-wrap gap-1">
                    {perms.slice(0, 6).map((p:string) => (
                      <span key={p} className="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded">{p}</span>
                    ))}
                    {perms.length > 6 && (
                      <span className="text-xs text-gray-400">+{perms.length - 6} more</span>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      </div>

      {/* Custom roles */}
      <div>
        <p className="text-sm font-semibold text-gray-500 mb-3">
          Custom roles <span className="text-xs font-normal text-gray-400">— created by your team</span>
        </p>
        {loading ? (
          <div className="card p-6 text-center text-gray-400 text-sm">Loading...</div>
        ) : customRoles.length === 0 ? (
          <EmptyState icon="🛡️" title="No custom roles" desc="Create custom roles with specific permissions for your team"
            action={<Button onClick={openCreate}>Create custom role</Button>} />
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {customRoles.map(r => {
              const perms = JSON.parse(r.permissions || '[]') as string[]
              return (
                <div key={r.id} className="card p-4 border border-brand-100">
                  <div className="flex items-center justify-between mb-3">
                    <Badge variant="purple">{r.label}</Badge>
                    <div className="flex gap-2">
                      <button onClick={() => openEdit(r)}  className="text-xs text-blue-600 hover:underline">Edit</button>
                      <button onClick={() => setDelRole(r)} className="text-xs text-red-500 hover:underline">Delete</button>
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-1">
                    {perms.slice(0, 8).map((p:string) => (
                      <span key={p} className="text-xs bg-brand-50 text-brand-600 px-1.5 py-0.5 rounded">{p}</span>
                    ))}
                    {perms.length > 8 && <span className="text-xs text-gray-400">+{perms.length - 8} more</span>}
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </div>

      {/* Create/Edit modal */}
      <Modal
        open={showModal}
        onClose={() => setShowModal(false)}
        title={editRole ? `Edit role — ${editRole.label}` : 'Create custom role'}
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowModal(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving}>
              {editRole ? 'Save changes' : 'Create role'}
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Input
            label="Role name *"
            placeholder="e.g. Senior Counsellor"
            value={form.label}
            onChange={e => setForm(f => ({...f, label: e.target.value}))}
          />

          <div>
            <div className="flex items-center justify-between mb-3">
              <label className="label mb-0">Permissions *</label>
              <div className="flex gap-3 text-xs">
                <button onClick={() => setForm(f => ({...f, permissions: ALL_PERMISSIONS.flatMap(g => g.perms.map(p => p[0]))}))}
                  className="text-brand-600 hover:underline">Select all</button>
                <button onClick={() => setForm(f => ({...f, permissions:[]}))}
                  className="text-gray-400 hover:underline">Clear all</button>
              </div>
            </div>

            <div className="space-y-4 max-h-[400px] overflow-y-auto pr-1">
              {ALL_PERMISSIONS.map(group => {
                const keys = group.perms.map(p => p[0])
                const allChecked = keys.every(k => form.permissions.includes(k))
                const someChecked = keys.some(k => form.permissions.includes(k))
                return (
                  <div key={group.group} className="border border-gray-200 rounded-xl overflow-hidden">
                    <div
                      className="flex items-center gap-3 px-4 py-3 bg-gray-50 cursor-pointer hover:bg-gray-100"
                      onClick={() => toggleGroup(group.perms)}
                    >
                      <input
                        type="checkbox"
                        checked={allChecked}
                        ref={el => { if (el) el.indeterminate = someChecked && !allChecked }}
                        onChange={() => toggleGroup(group.perms)}
                        onClick={e => e.stopPropagation()}
                        className="rounded"
                      />
                      <span className="text-sm font-semibold text-gray-700">{group.group}</span>
                      <span className="text-xs text-gray-400 ml-auto">
                        {keys.filter(k => form.permissions.includes(k)).length}/{keys.length}
                      </span>
                    </div>
                    <div className="grid grid-cols-2 gap-0 divide-y divide-x divide-gray-100">
                      {group.perms.map(([key, label]) => (
                        <label key={key}
                          className="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-brand-50 transition-colors">
                          <input
                            type="checkbox"
                            checked={form.permissions.includes(key)}
                            onChange={() => togglePerm(key)}
                            className="rounded text-brand-500"
                          />
                          <div>
                            <p className="text-xs font-medium text-gray-700">{label}</p>
                            <p className="text-xs text-gray-400 font-mono">{key}</p>
                          </div>
                        </label>
                      ))}
                    </div>
                  </div>
                )
              })}
            </div>
          </div>

          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
            <strong>{form.permissions.length}</strong> permission{form.permissions.length !== 1 ? 's' : ''} selected.
            Assign this role to staff members from the Staff management page.
          </div>
        </div>
      </Modal>

      <ConfirmModal
        open={!!delRole}
        title="Delete role?"
        message={`Delete "${delRole?.label}"? Staff members with this role will lose access. Assign them a new role before deleting.`}
        onConfirm={handleDelete}
        onCancel={() => setDelRole(null)}
        confirmLabel="Delete role"
        confirmVariant="danger"
      />
    </div>
  )
}
