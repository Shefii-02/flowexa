// src/pages/superadmin/PermissionsEditorPage.tsx
import { useEffect, useState } from 'react'

import { Button, Spinner, Badge } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { permissionsApi } from '@/api'

export default function PermissionsEditorPage() {
  const [roles,   setRoles]   = useState<any[]>([])
  const [allPerms,setAllPerms]= useState<Record<string, string[]>>({})
  const [selected,setSelected]= useState<any>(null)
  const [edited,  setEdited]  = useState<string[]>([])
  const [saving,  setSaving]  = useState(false)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    permissionsApi.list().then(r => {
      setRoles(r.data.roles)
      setAllPerms(r.data.all_permissions)
    }).finally(() => setLoading(false))
  }, [])

  const selectRole = (role: any) => { setSelected(role); setEdited([...role.permissions]) }

  const toggle = (perm: string) => {
    setEdited(prev => prev.includes(perm) ? prev.filter(p => p !== perm) : [...prev, perm])
  }

  const handleSave = async () => {
    if (!selected) return
    setSaving(true)
    try {
      const { data } = await permissionsApi.update(selected.id, edited)
      toast.success('Permissions updated.')
      setRoles(prev => prev.map(r => r.id === selected.id ? { ...r, permissions: edited } : r))
      setSelected({ ...selected, permissions: edited })
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  if (loading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>

  const protectedRoles = ['superadmin', 'owner']

  return (
    <div className="space-y-5">
      <div><h1 className="page-title">Role Permissions</h1><p className="page-sub">Manage what each role can access</p></div>
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {/* Role list */}
        <div className="card p-3 h-fit">
          <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide px-2 py-2">Roles</p>
          <div className="space-y-1">
            {roles.map(role => (
              <button key={role.id} onClick={() => selectRole(role)}
                className={`w-full text-left px-3 py-2.5 rounded-lg text-sm transition-colors ${selected?.id === role.id ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-700 hover:bg-gray-50'}`}>
                <div className="flex items-center justify-between">
                  <span>{role.label}</span>
                  {protectedRoles.includes(role.name) && <span className="text-xs text-gray-300">🔒</span>}
                </div>
                <p className="text-xs text-gray-400">{role.permissions?.length || 0} permissions</p>
              </button>
            ))}
          </div>
        </div>

        {/* Permissions editor */}
        <div className="lg:col-span-2">
          {!selected ? (
            <div className="card p-12 text-center text-gray-400">Select a role to edit permissions</div>
          ) : (
            <div className="card">
              <div className="card-header">
                <div>
                  <h3 className="card-title">{selected.label}</h3>
                  <p className="text-xs text-gray-400 mt-0.5">{edited.length} permissions enabled</p>
                </div>
                {!protectedRoles.includes(selected.name) && (
                  <Button onClick={handleSave} loading={saving} size="sm">Save permissions</Button>
                )}
              </div>
              {protectedRoles.includes(selected.name) ? (
                <div className="p-5 text-sm text-gray-500 bg-gray-50">
                  🔒 {selected.label} has all permissions and cannot be modified.
                </div>
              ) : (
                <div className="card-body space-y-4">
                  {Object.entries(allPerms).map(([group, perms]) => (
                    <div key={group}>
                      <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide capitalize mb-2">{group}</p>
                      <div className="grid grid-cols-2 gap-2">
                        {perms.map(perm => (
                          <label key={perm} className="flex items-center gap-2 cursor-pointer hover:bg-gray-50 p-2 rounded-lg">
                            <input type="checkbox" checked={edited.includes(perm)} onChange={() => toggle(perm)}
                              className="w-4 h-4 text-brand-500 rounded border-gray-300" />
                            <span className="text-xs font-mono text-gray-700">{perm}</span>
                          </label>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
