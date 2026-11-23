import { useMemo, useState } from 'react'
import PagePermissionMatrix from '@/components/permissions/PagePermissionMatrix'

export interface PermDef { key: string; label: string; group: string; type: string }

/**
 * Individual additional access: tick any permission to grant it to THIS person only, on top of their role and access sections. Permissions they
 * already get from the role or a section are shown ticked and locked, with where they come from, so nothing is granted twice by accident.
 */
export default function ExtraPermissionsCard({ name, catalogue, fromRole, fromSections, value, onChange }: {
  name: string; catalogue: PermDef[]; fromRole: string[]; fromSections: string[]; value: string[]; onChange: (v: string[]) => void
}) {
  const [q, setQ] = useState('')
  const [view, setView] = useState<'page' | 'group'>('page')
  const groups = useMemo(() => {
    const m = new Map<string, PermDef[]>()
    catalogue.filter(p => !q || `${p.label} ${p.key} ${p.group}`.toLowerCase().includes(q.toLowerCase()))
      .forEach(p => m.set(p.group, [...(m.get(p.group) ?? []), p]))
    return [...m.entries()]
  }, [catalogue, q])
  const role = new Set(fromRole), sec = new Set(fromSections), extra = new Set(value)
  const toggle = (k: string, on: boolean) => onChange(on ? [...value, k] : value.filter(x => x !== k))

  return (
    <div className="card" data-testid="extra-permissions-card">
      <div className="card-header"><h3 className="card-title">➕ Additional access</h3><span className="text-xs text-gray-400">{value.length} extra for {name}</span></div>
      <div className="card-body space-y-3">
        <p className="text-xs text-gray-500">Permissions given to {name} alone, in addition to the role and sections above. You can only grant what you hold yourself.</p>
        <div className="flex gap-1" role="tablist">
          {([['page', 'By page'], ['group', 'By permission']] as const).map(([id, label]) => (
            <button key={id} type="button" role="tab" aria-selected={view === id} onClick={() => setView(id)}
              className={`px-3 py-1 text-xs rounded-full border ${view === id ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-200 text-gray-600'}`}>{label}</button>
          ))}
        </div>
        {view === 'page' && (
          <PagePermissionMatrix
            granted={extra}
            locked={Object.fromEntries([...fromSections.map(k => [k, 'section']), ...fromRole.map(k => [k, 'role'])])}
            labelOf={k => catalogue.find(p => p.key === k)?.label ?? k}
            onToggle={(keys, on) => onChange(on ? [...new Set([...value, ...keys])] : value.filter(x => !keys.includes(x)))}
          />
        )}
        {view === 'group' && <input className="input" placeholder="Search permissions…" value={q} onChange={e => setQ(e.target.value)} />}
        <div className={`max-h-96 overflow-y-auto space-y-4 pr-1 ${view === 'group' ? '' : 'hidden'}`}>
          {groups.map(([group, perms]) => (
            <div key={group}>
              <div className="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{group}</div>
              {perms.map(p => {
                const inherited = role.has(p.key) ? 'role' : sec.has(p.key) ? 'section' : null
                return (
                  <label key={p.key} className={`flex items-center gap-2 py-1 text-sm ${inherited ? 'text-gray-400' : 'text-gray-800'}`}>
                    <input type="checkbox" disabled={!!inherited} checked={!!inherited || extra.has(p.key)} onChange={e => toggle(p.key, e.target.checked)} />
                    <span className="flex-1">{p.label}</span>
                    {inherited && <span className="text-[10px] px-1.5 py-0.5 rounded bg-gray-100">from {inherited}</span>}
                    {!inherited && extra.has(p.key) && <span className="text-[10px] px-1.5 py-0.5 rounded bg-green-50 text-green-700 border border-green-200">extra</span>}
                  </label>
                )
              })}
            </div>
          ))}
          {groups.length === 0 && <p className="text-sm text-gray-400">No permission matches.</p>}
        </div>
      </div>
    </div>
  )
}
