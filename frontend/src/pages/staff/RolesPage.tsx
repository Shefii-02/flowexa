import { useEffect, useState, useCallback } from 'react'
import { roleApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import type { Role, PermissionGroup } from '@/types'

// ── helpers ───────────────────────────────────────────────────────────────────

const PRESET_COLORS = [
  '#6366f1', '#8b5cf6', '#ec4899', '#ef4444',
  '#f97316', '#eab308', '#22c55e', '#14b8a6',
  '#3b82f6', '#64748b',
]

function badgeStyle(color: string) {
  return { backgroundColor: color + '22', color, border: `1px solid ${color}55` }
}

// ── types ─────────────────────────────────────────────────────────────────────

interface FormState {
  name: string
  description: string
  color: string
  permission_ids: Set<number>
}

const emptyForm = (): FormState => ({
  name: '',
  description: '',
  color: '#6366f1',
  permission_ids: new Set(),
})

// ── sub-components ────────────────────────────────────────────────────────────

const RoleCard = ({
  role,
  onEdit,
  onDelete,
  onReset,
  resetting,
}: {
  role: Role
  onEdit: (r: Role) => void
  onDelete: (r: Role) => void
  onReset: (r: Role) => void
  resetting: boolean
}) => (
  <div className="bg-white border border-gray-200 rounded-xl p-4 flex flex-col gap-3 hover:shadow-sm transition-shadow">
    <div className="flex items-start justify-between gap-2">
      <div className="flex items-center gap-2 min-w-0">
        <span
          className="w-3 h-3 rounded-full flex-shrink-0"
          style={{ backgroundColor: role.color }}
        />
        <span className="font-semibold text-gray-900 truncate">{role.label || role.name}</span>
        {role.is_system && (
          <span className="text-[10px] font-medium px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 flex-shrink-0">
            System
          </span>
        )}
      </div>
      <div className="flex gap-1 flex-shrink-0">
        <button
          onClick={() => onEdit(role)}
          className="p-1.5 rounded hover:bg-gray-100 text-gray-500 hover:text-gray-700 text-xs"
          title="Edit role"
        >
          ✏️
        </button>
        {role.is_system && (
          <button
            onClick={() => onReset(role)}
            disabled={resetting}
            className="p-1.5 rounded hover:bg-blue-50 text-gray-400 hover:text-blue-500 text-xs disabled:opacity-40"
            title="Reset permissions to defaults"
          >
            {resetting ? '⏳' : '↺'}
          </button>
        )}
        {!role.is_system && (
          <button
            onClick={() => onDelete(role)}
            className="p-1.5 rounded hover:bg-red-50 text-gray-400 hover:text-red-500 text-xs"
            title="Delete role"
          >
            🗑️
          </button>
        )}
      </div>
    </div>

    {role.description && (
      <p className="text-xs text-gray-500 leading-relaxed">{role.description}</p>
    )}

    <div className="flex items-center justify-between text-xs text-gray-400 pt-1 border-t border-gray-50">
      <span>{role.users_count ?? 0} member{role.users_count !== 1 ? 's' : ''}</span>
      <span>{role.permissions.length} permission{role.permissions.length !== 1 ? 's' : ''}</span>
    </div>
  </div>
)

// ── PermissionMatrix inside the modal ─────────────────────────────────────────

const PermissionMatrix = ({
  groups,
  selected,
  onChange,
  readOnly,
}: {
  groups: PermissionGroup[]
  selected: Set<number>
  onChange: (next: Set<number>) => void
  readOnly: boolean
}) => {
  const toggle = (id: number, type: 'viewer' | 'manage', allInGroup: PermissionGroup['permissions']) => {
    if (readOnly) return
    const next = new Set(selected)
    const viewerId = allInGroup.find((p) => p.type === 'viewer')?.id
    const manageId = allInGroup.find((p) => p.type === 'manage')?.id

    if (type === 'manage') {
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
        if (viewerId) next.add(viewerId) // manage → viewer auto-on
      }
    } else {
      if (next.has(id)) {
        next.delete(id)
        if (manageId) next.delete(manageId) // viewer off → manage off
      } else {
        next.add(id)
      }
    }
    onChange(next)
  }

  return (
    <div className="space-y-1 max-h-[50vh] overflow-y-auto pr-1">
      {groups.map((g) => {
        const viewer = g.permissions.find((p) => p.type === 'viewer')
        const manage = g.permissions.find((p) => p.type === 'manage')
        return (
          <div
            key={g.group}
            className="flex items-center justify-between py-1.5 px-2 rounded hover:bg-gray-50"
          >
            <span className="text-sm text-gray-700 flex-1">{g.group}</span>
            <div className="flex gap-4">
              {/* Viewer */}
              <label className="flex items-center gap-1.5 cursor-pointer">
                <input
                  type="checkbox"
                  className="w-3.5 h-3.5 rounded accent-indigo-500"
                  checked={viewer ? selected.has(viewer.id) : false}
                  disabled={readOnly || !viewer}
                  onChange={() => viewer && toggle(viewer.id, 'viewer', g.permissions)}
                />
                <span className="text-xs text-gray-500">View</span>
              </label>
              {/* Manage */}
              <label className="flex items-center gap-1.5 cursor-pointer">
                <input
                  type="checkbox"
                  className="w-3.5 h-3.5 rounded accent-indigo-500"
                  checked={manage ? selected.has(manage.id) : false}
                  disabled={readOnly || !manage}
                  onChange={() => manage && toggle(manage.id, 'manage', g.permissions)}
                />
                <span className="text-xs text-gray-500">Manage</span>
              </label>
            </div>
          </div>
        )
      })}
    </div>
  )
}

// ── RoleModal ─────────────────────────────────────────────────────────────────

const RoleModal = ({
  editing,
  groups,
  roles,
  onClose,
  onSaved,
}: {
  editing: Role | null
  groups: PermissionGroup[]
  roles: Role[]
  onClose: () => void
  onSaved: () => void
}) => {
  const isNew = editing === null
  const isSystem = editing?.is_system ?? false

  const [form, setForm] = useState<FormState>(() => {
    if (!editing) return emptyForm()
    return {
      name: editing.name,
      description: editing.description ?? '',
      color: editing.color ?? '#6366f1',
      permission_ids: new Set(editing.permission_ids ?? []),
    }
  })
  const [saving, setSaving] = useState(false)
  const [copyFrom, setCopyFrom] = useState<string>('')

  const patch = (partial: Partial<Omit<FormState, 'permission_ids'>>) =>
    setForm((f) => ({ ...f, ...partial }))

  const handleCopyFrom = (roleId: string) => {
    if (!roleId) return
    const src = roles.find((r) => String(r.id) === roleId)
    if (src) {
      setForm((f) => ({ ...f, permission_ids: new Set(src.permission_ids ?? []) }))
    }
    setCopyFrom(roleId)
  }

  const handleSubmit = async () => {
    if (!isSystem && !form.name.trim()) {
      toast.error('Name is required')
      return
    }
    setSaving(true)
    try {
      const payload: Record<string, unknown> = {
        description: form.description || null,
        color: form.color,
      }
      if (!isSystem) {
        payload.name = form.name.trim()
        payload.permission_ids = Array.from(form.permission_ids)
      } else {
        // system roles: only description + color
      }

      if (isNew) {
        await roleApi.create(payload)
        toast.success('Role created')
      } else {
        await roleApi.update(editing!.id, payload)
        toast.success('Role updated')
      }
      onSaved()
      onClose()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg flex flex-col max-h-[90vh]">
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <h2 className="font-semibold text-gray-900">
            {isNew ? 'Create role' : `Edit — ${editing?.label || editing?.name}`}
          </h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-lg leading-none">
            ×
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-5 py-4 space-y-4">
          {/* Name */}
          {!isSystem && (
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">Role name *</label>
              <input
                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
                value={form.name}
                onChange={(e) => patch({ name: e.target.value })}
                placeholder="e.g. Sales Lead"
              />
            </div>
          )}

          {/* Description */}
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">Description</label>
            <input
              className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
              value={form.description}
              onChange={(e) => patch({ description: e.target.value })}
              placeholder="Optional description"
            />
          </div>

          {/* Color */}
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-2">Color</label>
            <div className="flex gap-2 flex-wrap">
              {PRESET_COLORS.map((c) => (
                <button
                  key={c}
                  className="w-6 h-6 rounded-full border-2 transition-transform hover:scale-110"
                  style={{
                    backgroundColor: c,
                    borderColor: form.color === c ? '#111' : 'transparent',
                  }}
                  onClick={() => patch({ color: c })}
                />
              ))}
              <input
                type="color"
                value={form.color}
                onChange={(e) => patch({ color: e.target.value })}
                className="w-6 h-6 rounded cursor-pointer border-0 p-0"
                title="Custom color"
              />
            </div>
          </div>

          {/* Permissions */}
          {!isSystem && (
            <>
              <div className="flex items-center justify-between">
                <label className="text-xs font-medium text-gray-600">Permissions</label>
                {roles.length > 0 && (
                  <select
                    value={copyFrom}
                    onChange={(e) => handleCopyFrom(e.target.value)}
                    className="text-xs border border-gray-200 rounded px-2 py-1 text-gray-600 focus:outline-none"
                  >
                    <option value="">Copy from role…</option>
                    {roles.map((r) => (
                      <option key={r.id} value={String(r.id)}>
                        {r.label || r.name}
                      </option>
                    ))}
                  </select>
                )}
              </div>

              <div className="border border-gray-100 rounded-lg p-2">
                <div className="flex justify-end gap-6 px-2 pb-1 text-[11px] font-medium text-gray-400 uppercase tracking-wide">
                  <span>View</span>
                  <span>Manage</span>
                </div>
                <PermissionMatrix
                  groups={groups}
                  selected={form.permission_ids}
                  onChange={(next) => setForm((f) => ({ ...f, permission_ids: next }))}
                  readOnly={false}
                />
              </div>
            </>
          )}

          {isSystem && (
            <p className="text-xs text-gray-400 italic">
              System roles have fixed permissions. Only the description and color can be changed.
            </p>
          )}
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-3 px-5 py-3 border-t border-gray-100">
          <button
            onClick={onClose}
            className="px-4 py-2 text-sm rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            onClick={handleSubmit}
            disabled={saving}
            className="px-4 py-2 text-sm rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50"
          >
            {saving ? 'Saving…' : isNew ? 'Create role' : 'Save changes'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function RolesPage() {
  const [roles, setRoles] = useState<Role[]>([])
  const [groups, setGroups] = useState<PermissionGroup[]>([])
  const [loading, setLoading] = useState(true)
  const [editTarget, setEditTarget] = useState<Role | null | undefined>(undefined) // undefined = closed
  const [deleting, setDeleting] = useState<Role | null>(null)
  const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null)
  const [resettingId, setResettingId] = useState<number | null>(null)

  const load = useCallback(async () => {
    try {
      const [rolesRes, permsRes] = await Promise.all([
        roleApi.list(),
        roleApi.allPermissions(),
      ])
      setRoles(rolesRes.data.roles ?? [])
      setGroups(permsRes.data.permissions ?? [])
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const handleDelete = async (role: Role) => {
    if (confirmDeleteId !== role.id) {
      setConfirmDeleteId(role.id)
      return
    }
    setDeleting(role)
    try {
      await roleApi.delete(role.id)
      toast.success(`"${role.label || role.name}" deleted`)
      await load()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setDeleting(null)
      setConfirmDeleteId(null)
    }
  }

  const handleReset = async (role: Role) => {
    setResettingId(role.id)
    try {
      await roleApi.resetPermissions(role.id)
      toast.success(`"${role.label || role.name}" permissions reset to defaults`)
      await load()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setResettingId(null)
    }
  }

  const systemRoles = roles.filter((r) => r.is_system)
  const companyRoles = roles.filter((r) => !r.is_system)

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-8">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Roles & permissions</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Define what each role can view and manage.
          </p>
        </div>
        <button
          onClick={() => setEditTarget(null)}
          className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700"
        >
          + New role
        </button>
      </div>

      {loading ? (
        <div className="text-sm text-gray-400 py-12 text-center">Loading…</div>
      ) : (
        <>
          {/* Company roles */}
          <section>
            <h2 className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">
              Custom roles
            </h2>
            {companyRoles.length === 0 ? (
              <div className="text-sm text-gray-400 border border-dashed border-gray-200 rounded-xl p-8 text-center">
                No custom roles yet. Create one to get started.
              </div>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {companyRoles.map((r) => (
                  <RoleCard
                    key={r.id}
                    role={r}
                    onEdit={setEditTarget}
                    onDelete={(role) => handleDelete(role)}
                    onReset={handleReset}
                    resetting={resettingId === r.id}
                  />
                ))}
              </div>
            )}
          </section>

          {/* System roles */}
          {systemRoles.length > 0 && (
            <section>
              <h2 className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">
                System roles
              </h2>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {systemRoles.map((r) => (
                  <RoleCard
                    key={r.id}
                    role={r}
                    onEdit={setEditTarget}
                    onDelete={(role) => handleDelete(role)}
                    onReset={handleReset}
                    resetting={resettingId === r.id}
                  />
                ))}
              </div>
            </section>
          )}

          {/* Permission matrix overview table */}
          {groups.length > 0 && (
            <section>
              <h2 className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">
                Permission overview
              </h2>
              <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-gray-100">
                        <th className="text-left px-4 py-2.5 text-xs text-gray-400 font-medium w-48">
                          Permission group
                        </th>
                        {roles.map((r) => (
                          <th
                            key={r.id}
                            className="px-3 py-2.5 text-xs font-medium text-center"
                            style={badgeStyle(r.color)}
                          >
                            {r.label || r.name}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {groups.map((g, gi) => (
                        <tr
                          key={g.group}
                          className={gi % 2 === 0 ? 'bg-white' : 'bg-gray-50'}
                        >
                          <td className="px-4 py-2 text-gray-700 font-medium text-xs">{g.group}</td>
                          {roles.map((r) => {
                            const hasView = g.permissions.some(
                              (p) => p.type === 'viewer' && r.permissions.includes(p.key)
                            )
                            const hasMgmt = g.permissions.some(
                              (p) => p.type === 'manage' && r.permissions.includes(p.key)
                            )
                            return (
                              <td key={r.id} className="px-3 py-2 text-center">
                                {hasMgmt ? (
                                  <span className="text-green-600 text-xs font-medium">Manage</span>
                                ) : hasView ? (
                                  <span className="text-blue-500 text-xs">View</span>
                                ) : (
                                  <span className="text-gray-200 text-xs">—</span>
                                )}
                              </td>
                            )
                          })}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </section>
          )}
        </>
      )}

      {/* Delete confirmation banner */}
      {confirmDeleteId !== null && (
        <div className="fixed bottom-6 left-1/2 -translate-x-1/2 bg-red-600 text-white text-sm rounded-xl px-5 py-3 shadow-lg flex items-center gap-4 z-40">
          <span>
            Delete "{roles.find((r) => r.id === confirmDeleteId)?.label}"? This cannot be undone.
          </span>
          <button
            disabled={!!deleting}
            onClick={() => {
              const r = roles.find((r) => r.id === confirmDeleteId)
              if (r) handleDelete(r)
            }}
            className="px-3 py-1 bg-white text-red-600 rounded-lg font-medium text-xs hover:bg-red-50 disabled:opacity-50"
          >
            {deleting ? 'Deleting…' : 'Confirm delete'}
          </button>
          <button
            onClick={() => setConfirmDeleteId(null)}
            className="text-red-200 hover:text-white"
          >
            ×
          </button>
        </div>
      )}

      {/* Create / Edit modal */}
      {editTarget !== undefined && (
        <RoleModal
          editing={editTarget}
          groups={groups}
          roles={roles}
          onClose={() => setEditTarget(undefined)}
          onSaved={load}
        />
      )}
    </div>
  )
}
