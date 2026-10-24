// Shared main/sub access-section picker — used by Staff Access and the Add/Edit staff form.
export type AccessSection = { key: string; label: string; icon: string; color: string; description: string; permissions: string[] }

export default function AccessSectionPicker({ sections, main, subs, onChange, compact }: {
  sections: AccessSection[]
  main: string | null
  subs: string[]
  onChange: (next: { main: string | null; subs: string[] }) => void
  compact?: boolean
}) {
  const pickMain = (key: string) =>
    onChange({ main: main === key ? null : key, subs: subs.filter((k) => k !== key) })
  const toggleSub = (key: string) =>
    onChange({ main, subs: subs.includes(key) ? subs.filter((k) => k !== key) : [...subs, key] })

  return (
    <div className={`grid grid-cols-1 ${compact ? '' : 'md:grid-cols-2'} gap-3`}>
      {sections.map((s) => {
        const isMain = main === s.key, isSub = subs.includes(s.key)
        return (
          <div key={s.key} className={`border rounded-xl p-3 ${isMain ? 'border-brand-500 bg-brand-50' : isSub ? 'border-gray-400' : 'border-gray-200'}`}>
            <div className="flex items-center gap-2">
              <span>{s.icon}</span><span className="font-semibold text-sm text-gray-900">{s.label}</span>
              {isMain && <span className="text-[10px] px-1.5 py-0.5 rounded bg-brand-500 text-white">MAIN</span>}
            </div>
            <p className="text-xs text-gray-500 mt-1">{s.description}</p>
            <div className="flex gap-4 mt-2 text-xs text-gray-700">
              <label className="flex items-center gap-1"><input type="radio" checked={isMain} onChange={() => pickMain(s.key)} onClick={() => isMain && pickMain(s.key)} /> Main</label>
              <label className="flex items-center gap-1"><input type="checkbox" disabled={isMain} checked={isSub} onChange={() => toggleSub(s.key)} /> Sub</label>
            </div>
          </div>
        )
      })}
    </div>
  )
}

/** Permissions a set of sections adds on top of the role's own ('*' = everything the role doesn't already have). */
export function sectionAddedPermissions(sections: AccessSection[], keys: string[], rolePerms: string[], allKeys: string[]): { all: boolean; added: string[] } {
  const chosen = sections.filter((s) => keys.includes(s.key))
  if (chosen.some((s) => s.permissions.includes('*'))) return { all: true, added: allKeys.filter((p) => !rolePerms.includes(p)) }
  const added = [...new Set(chosen.flatMap((s) => s.permissions))].filter((p) => !rolePerms.includes(p))
  return { all: false, added }
}
