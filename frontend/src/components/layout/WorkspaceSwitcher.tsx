import { useWorkspace, WORKSPACE_LABELS } from '@/lib/workspace'

/** Topbar dropdown: switch between the sections you work in (Company Admin, Sales Team, HR Team …). Drives the sidebar and Home. */
export default function WorkspaceSwitcher() {
  const { active, list, choose } = useWorkspace()
  if (!active || list.length === 0) return null
  if (list.length === 1) return <span className="text-xs px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 font-medium">{WORKSPACE_LABELS[active]}</span>
  return (
    <select value={active} onChange={(e) => choose(e.target.value)} aria-label="Switch workspace"
      className="text-sm font-medium text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-lg px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-200">
      {list.map((k) => <option key={k} value={k}>{WORKSPACE_LABELS[k]}</option>)}
    </select>
  )
}
