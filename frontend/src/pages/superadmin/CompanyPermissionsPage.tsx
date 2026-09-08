import { useEffect, useMemo, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { superadminApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

type Perm = { key: string; label: string; type: string }
type Catalogue = Record<string, Perm[]>
type RoleRow = { id: number; name: string; label: string; is_system: boolean; protected: boolean; permissions: string[]; user_count: number }

export default function CompanyPermissionsPage() {
  const { id } = useParams()
  const companyId = Number(id)
  const [company, setCompany] = useState<{ id: number; name: string } | null>(null)
  const [catalogue, setCatalogue] = useState<Catalogue>({})
  const [roles, setRoles] = useState<RoleRow[]>([])
  const [activeRole, setActiveRole] = useState<number | null>(null)
  const [edited, setEdited] = useState<Set<string>>(new Set())
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const load = () => {
    setLoading(true)
    superadminApi.companyPermissions(companyId).then(r => {
      setCompany(r.data.company)
      setCatalogue(r.data.catalogue)
      setRoles(r.data.roles)
      const first = r.data.roles.find((x: RoleRow) => !x.protected) ?? r.data.roles[0]
      if (first) { setActiveRole(first.id); setEdited(new Set(first.permissions)) }
    }).catch((e) => toast.error(getError(e))).finally(() => setLoading(false))
  }
  useEffect(load, [companyId])

  const role = roles.find(r => r.id === activeRole) ?? null
  const allKeys = useMemo(() => Object.values(catalogue).flat().map(p => p.key), [catalogue])

  const selectRole = (r: RoleRow) => { setActiveRole(r.id); setEdited(new Set(r.permissions)) }
  const toggle = (key: string) => setEdited(prev => {
    const n = new Set(prev); n.has(key) ? n.delete(key) : n.add(key); return n
  })
  const toggleGroup = (perms: Perm[]) => setEdited(prev => {
    const n = new Set(prev)
    const allOn = perms.every(p => n.has(p.key))
    perms.forEach(p => allOn ? n.delete(p.key) : n.add(p.key))
    return n
  })

  const save = async () => {
    if (!role) return
    setSaving(true)
    try {
      await superadminApi.updateCompanyRolePermissions(companyId, role.id, [...edited])
      setRoles(rs => rs.map(r => r.id === role.id ? { ...r, permissions: [...edited] } : r))
      toast.success('Permissions saved')
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const resync = async () => {
    if (!confirm('Re-apply the default permission set to this company\'s system roles?')) return
    try { await superadminApi.resyncCompanyPermissions(companyId); toast.success('Defaults re-applied'); load() }
    catch (e) { toast.error(getError(e)) }
  }

  if (loading) return <div className="p-6 text-gray-400">Loading…</div>

  const dirty = role && (edited.size !== role.permissions.length || [...edited].some(k => !role.permissions.includes(k)))

  return (
    <div className="space-y-5">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <Link to="/superadmin/companies" className="text-xs text-indigo-600 hover:underline">← Companies</Link>
          <h1 className="page-title">{company?.name} · Permissions</h1>
          <p className="page-sub">Manage what each role in this company can access</p>
        </div>
        <button onClick={resync} className="text-sm border border-gray-200 rounded-lg px-3 py-1.5">Reset to defaults</button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-4 gap-5">
        {/* Roles */}
        <div className="card p-3 h-fit">
          <p className="text-xs font-semibold text-gray-400 uppercase px-2 py-1">Roles</p>
          {roles.map(r => (
            <button key={r.id} onClick={() => selectRole(r)}
              className={`w-full text-left px-3 py-2.5 rounded-lg text-sm ${activeRole === r.id ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-700 hover:bg-gray-50'}`}>
              <div className="flex items-center justify-between">
                <span>{r.label || r.name}</span>
                {r.protected && <span className="text-xs text-gray-300">🔒</span>}
              </div>
              <p className="text-[11px] text-gray-400">{r.protected ? 'full access' : `${r.permissions.length} perms`} · {r.user_count} users</p>
            </button>
          ))}
        </div>

        {/* Permission grid */}
        <div className="lg:col-span-3 space-y-4">
          {!role ? (
            <div className="card p-12 text-center text-gray-400">Select a role.</div>
          ) : role.protected ? (
            <div className="card p-8 text-center text-gray-500">🔒 The <b>{role.label || role.name}</b> role always has full access and can't be limited.</div>
          ) : (
            <>
              <div className="flex items-center justify-between sticky top-0 bg-white/90 backdrop-blur py-2 z-10">
                <p className="text-sm text-gray-500">{edited.size} / {allKeys.length} permissions enabled for <b>{role.label || role.name}</b></p>
                <div className="flex gap-2">
                  <button onClick={() => setEdited(new Set(allKeys))} className="text-xs text-gray-500 hover:underline">All</button>
                  <button onClick={() => setEdited(new Set())} className="text-xs text-gray-500 hover:underline">None</button>
                  <button onClick={save} disabled={saving || !dirty}
                    className="text-sm bg-indigo-600 text-white rounded-lg px-4 py-1.5 disabled:opacity-40">{saving ? 'Saving…' : 'Save'}</button>
                </div>
              </div>

              {Object.entries(catalogue).map(([group, perms]) => {
                const on = perms.filter(p => edited.has(p.key)).length
                return (
                  <div key={group} className="card p-4">
                    <div className="flex items-center justify-between mb-2">
                      <h3 className="text-sm font-semibold text-gray-700">{group} <span className="text-xs font-normal text-gray-400">({on}/{perms.length})</span></h3>
                      <button onClick={() => toggleGroup(perms)} className="text-xs text-indigo-600 hover:underline">
                        {perms.every(p => edited.has(p.key)) ? 'Clear' : 'Select all'}
                      </button>
                    </div>
                    <div className="grid sm:grid-cols-2 gap-1.5">
                      {perms.map(p => (
                        <label key={p.key} className="flex items-center gap-2 text-sm py-1 cursor-pointer">
                          <input type="checkbox" checked={edited.has(p.key)} onChange={() => toggle(p.key)} />
                          <span className="text-gray-700">{p.label}</span>
                          <code className="text-[10px] text-gray-300">{p.key}</code>
                        </label>
                      ))}
                    </div>
                  </div>
                )
              })}
            </>
          )}
        </div>
      </div>
    </div>
  )
}
