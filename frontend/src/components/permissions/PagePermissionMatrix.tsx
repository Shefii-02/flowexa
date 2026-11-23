import { useMemo, useState } from 'react'
import { PAGE_SECTIONS } from '@/lib/pagePermissions'

/**
 * Every inner page of the app, section by section, with the permission that opens it. Ticking a page grants that permission — to a role
 * (Roles editor) or to one person (Staff Access → Additional access). Pages whose permission already comes from elsewhere are ticked and locked.
 */
export default function PagePermissionMatrix({ granted, locked = {}, labelOf, onToggle, readOnly }: {
  granted: Set<string>                      // permission keys currently ticked (the role's / the person's extra)
  locked?: Record<string, string>           // permission key → where it already comes from ("role", "section")
  labelOf: (key: string) => string          // human label of a permission key
  onToggle: (keys: string[], on: boolean) => void
  readOnly?: boolean
}) {
  const [q, setQ] = useState('')
  const sections = useMemo(() => PAGE_SECTIONS.map(s => ({
    ...s, pages: s.pages.filter(pg => !q || `${s.section} ${pg.label} ${pg.perms.join(' ')}`.toLowerCase().includes(q.toLowerCase())),
  })).filter(s => s.pages.length > 0), [q])

  const on = (perms: string[]) => perms.length > 0 && perms.some(k => granted.has(k) || locked[k])

  return (
    <div className="space-y-3" data-testid="page-permission-matrix">
      <input className="input" placeholder="Search pages…" value={q} onChange={e => setQ(e.target.value)} />
      <div className="max-h-[50vh] overflow-y-auto pr-1 space-y-4">
        {sections.map(s => {
          const gated = s.pages.filter(pg => pg.perms.length > 0)
          const allKeys = [...new Set(gated.map(pg => pg.perms[0]))]
          const allOn = allKeys.length > 0 && allKeys.every(k => granted.has(k) || locked[k])
          return (
            <div key={s.section}>
              <label className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
                {!readOnly && allKeys.length > 0 && <input type="checkbox" checked={allOn} onChange={e => onToggle(allKeys.filter(k => !locked[k]), e.target.checked)} />}
                {s.section}
              </label>
              {s.pages.map(pg => {
                const free = pg.perms.length === 0
                const from = pg.perms.map(k => locked[k]).find(Boolean)
                return (
                  <label key={pg.path} className={`flex items-center gap-2 py-1 pl-5 text-sm ${free || from ? 'text-gray-400' : 'text-gray-800'}`}>
                    <input type="checkbox" disabled={readOnly || free || !!from} checked={free || on(pg.perms)} onChange={e => onToggle([pg.perms[0]], e.target.checked)} />
                    <span className="flex-1">{pg.label}</span>
                    {free
                      ? <span className="text-[10px] px-1.5 py-0.5 rounded bg-gray-100">everyone</span>
                      : <span className="text-[10px] text-gray-400 truncate max-w-[40%]" title={pg.perms.join(', ')}>{labelOf(pg.perms[0])}{from ? ` · from ${from}` : ''}</span>}
                  </label>
                )
              })}
            </div>
          )
        })}
        {sections.length === 0 && <p className="text-sm text-gray-400">No page matches.</p>}
      </div>
    </div>
  )
}
