import { useMemo } from 'react'

export interface MobileScreens { tabs: Record<string, boolean>; profile_items: Record<string, boolean>; pages: string[] }
export interface MobileScreensMeta {
  tab_labels: Record<string, string>; profile_item_labels: Record<string, string>
  catalogue: { id: string; title: string; group: string }[]
}

/** The default for "pick screens": everything on, so ticking "Choose screens" starts from what they see today. */
export const allScreens = (m: MobileScreensMeta): MobileScreens => ({
  tabs: Object.fromEntries(Object.keys(m.tab_labels).map(k => [k, true])),
  profile_items: Object.fromEntries(Object.keys(m.profile_item_labels).map(k => [k, true])),
  pages: m.catalogue.map(c => c.id),
})

/** Per-staff mobile screens: follow the company layout (value = null) or pick the tabs, Profile items and pages for this person alone. */
export default function MobileScreensCard({ name, value, meta, onChange }: {
  name: string; value: MobileScreens | null; meta: MobileScreensMeta; onChange: (v: MobileScreens | null) => void
}) {
  const groups = useMemo(() => {
    const m = new Map<string, MobileScreensMeta['catalogue']>()
    meta.catalogue.forEach(c => m.set(c.group, [...(m.get(c.group) ?? []), c]))
    return [...m.entries()]
  }, [meta.catalogue])

  const custom = value !== null
  const set = (patch: Partial<MobileScreens>) => value && onChange({ ...value, ...patch })
  const togglePage = (id: string, on: boolean) => value && set({ pages: on ? [...value.pages, id] : value.pages.filter(p => p !== id) })
  const toggleGroup = (ids: string[], on: boolean) =>
    value && set({ pages: on ? [...new Set([...value.pages, ...ids])] : value.pages.filter(p => !ids.includes(p)) })

  return (
    <div className="card" data-testid="mobile-screens-card">
      <div className="card-header"><h3 className="card-title">📱 Mobile screens</h3><span className="text-xs text-gray-400">What {name} sees in the app</span></div>
      <div className="card-body space-y-4">
        <div className="space-y-1.5 text-sm text-gray-700">
          <label className="flex items-center gap-2"><input type="radio" checked={!custom} onChange={() => onChange(null)} /> Follow the company layout</label>
          <label className="flex items-center gap-2"><input type="radio" checked={custom} onChange={() => !custom && onChange(allScreens(meta))} /> Choose screens for {name}</label>
        </div>

        {value && (
          <>
            <p className="text-xs text-gray-500">These can only narrow the company layout — a screen the company has hidden stays hidden.</p>
            <div>
              <div className="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Bottom tabs</div>
              <div className="flex flex-wrap gap-x-5 gap-y-1">
                {Object.entries(meta.tab_labels).map(([k, label]) => (
                  <label key={k} className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={value.tabs[k] !== false} onChange={e => set({ tabs: { ...value.tabs, [k]: e.target.checked } })} /> {label}</label>
                ))}
              </div>
            </div>
            <div>
              <div className="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Profile menu</div>
              <div className="flex flex-wrap gap-x-5 gap-y-1">
                {Object.entries(meta.profile_item_labels).map(([k, label]) => (
                  <label key={k} className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={value.profile_items[k] !== false} onChange={e => set({ profile_items: { ...value.profile_items, [k]: e.target.checked } })} /> {label}</label>
                ))}
              </div>
            </div>
            <div>
              <div className="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Pages (Explore)</div>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6">
                {groups.map(([group, items]) => {
                  const ids = items.map(i => i.id)
                  const allOn = ids.every(id => value.pages.includes(id))
                  return (
                    <div key={group} className="mb-3">
                      <label className="flex items-center gap-1.5 text-sm font-medium text-gray-800">
                        <input type="checkbox" checked={allOn} onChange={e => toggleGroup(ids, e.target.checked)} /> {group}
                      </label>
                      <div className="pl-5">
                        {items.map(c => (
                          <label key={c.id} className="flex items-center gap-1.5 text-sm text-gray-600 py-0.5">
                            <input type="checkbox" checked={value.pages.includes(c.id)} onChange={e => togglePage(c.id, e.target.checked)} /> {c.title}
                          </label>
                        ))}
                      </div>
                    </div>
                  )
                })}
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  )
}
