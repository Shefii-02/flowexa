// "Who can receive these leads?" — all staff / attendance-online staff / selected staff (multi-select) / selected staff who are clocked in /
// selected staff without any attendance check.
// Shared by the company-wide Routing section and by every category / campaign routing rule.
import { useState } from 'react'
import { useModule } from '@/store'

export type StaffScope = 'all' | 'online' | 'selected' | 'selected_online' | 'selected_any'
export type StaffOpt = { id: number; name: string; department?: string | null }

export const SCOPE_LABEL: Record<StaffScope, string> = {
  all: 'All staff',
  online: 'Attendance-online staff only',
  selected: 'Selected staff only (must be available)',
  selected_online: 'Selected staff, only while clocked in',
  selected_any: 'Selected staff — without checking attendance',
}
const SCOPE_HINT: Record<StaffScope, string> = {
  all: 'Every staff member can receive the lead — online / offline and attendance are NOT checked (a person at their lead limit is still skipped).',
  online: 'Only employees who are clocked in right now (HR attendance, not on a break).',
  selected: 'Only the employees you pick below, and only if they are available (online or away, not at their lead limit).',
  selected_online: 'Only the employees you pick below, and only while they are clocked in.',
  selected_any: 'Only the employees you pick below. Whether they are online or clocked in is NOT checked — the lead goes to whoever has the fewest leads today (a person at their lead limit is still skipped).',
}
export const needsAttendance = (s: StaffScope) => s === 'online' || s === 'selected_online'
export const needsStaff = (s: StaffScope) => s === 'selected' || s === 'selected_online' || s === 'selected_any'

/** one-line summary for lists: "All staff", "Attendance-online staff", or the chosen names */
export const scopeSummary = (scope: StaffScope, names: string[]) =>
  scope === 'all' ? 'All staff' : scope === 'online' ? 'Attendance-online staff'
    : `${names.length} selected${scope === 'selected_online' ? ' · only while clocked in' : scope === 'selected_any' ? ' · attendance not checked' : ''}`

export default function StaffScopePicker({ scope, staffIds, staff, onChange }: {
  scope: StaffScope
  staffIds: number[]
  staff: StaffOpt[]
  onChange: (scope: StaffScope, staffIds: number[]) => void
}) {
  const [q, setQ] = useState('')
  const hr = useModule('hr')   // attendance-based choices need the HR section
  const shown = staff.filter(s => s.name.toLowerCase().includes(q.toLowerCase()))
  const allShownSelected = shown.length > 0 && shown.every(s => staffIds.includes(s.id))
  const toggle = (id: number) => onChange(scope, staffIds.includes(id) ? staffIds.filter(i => i !== id) : [...staffIds, id])
  const setScope = (s: StaffScope) => onChange(s, needsStaff(s) ? staffIds : [])

  return (
    <div className="space-y-2">
      <label className="block text-sm text-gray-600">Who can receive these leads?
        <select className="mt-1 w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" value={scope} onChange={e => setScope(e.target.value as StaffScope)}>
          {(Object.keys(SCOPE_LABEL) as StaffScope[]).map(k => <option key={k} value={k} disabled={!hr && needsAttendance(k) && k !== scope}>{SCOPE_LABEL[k]}{!hr && needsAttendance(k) ? ' — needs the HR section' : ''}</option>)}
        </select>
      </label>
      <p className="text-xs text-gray-400">{SCOPE_HINT[scope]}</p>
      {needsAttendance(scope) && hr && (
        <p className="text-xs text-amber-600">Needs HR attendance: staff must clock in to be offered leads. If nobody is clocked in, the lead goes to the AI agent.</p>
      )}
      {!hr && (
        <p className={`text-xs ${needsAttendance(scope) ? 'text-amber-600' : 'text-gray-400'}`}>
          {needsAttendance(scope)
            ? 'The HR section is not available on your plan, so attendance cannot be checked — leads are assigned round robin across all staff (or the selected staff) instead.'
            : 'Attendance-based options need the HR section, which is not on your plan.'}
        </p>
      )}

      {needsStaff(scope) && (
        <div>
          <div className="flex items-center justify-between gap-2 mb-1">
            <span className="text-sm text-gray-600">Employees ({staffIds.length} selected)</span>
            <div className="flex items-center gap-2">
              <button type="button" className="text-xs text-indigo-600 hover:underline"
                onClick={() => onChange(scope, allShownSelected ? staffIds.filter(i => !shown.some(s => s.id === i)) : Array.from(new Set([...staffIds, ...shown.map(s => s.id)])))}>
                {allShownSelected ? 'Unselect all' : q ? 'Select all shown' : 'Select all'}
              </button>
              {staffIds.length > 0 && <button type="button" className="text-xs text-gray-400 hover:underline" onClick={() => onChange(scope, [])}>Clear</button>}
              <input className="border border-gray-300 rounded-lg px-2 py-1 text-xs" placeholder="Search…" value={q} onChange={e => setQ(e.target.value)} />
            </div>
          </div>
          <div className="max-h-48 overflow-y-auto border border-gray-200 rounded-lg divide-y divide-gray-100">
            {shown.map(s => (
              <label key={s.id} className="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-gray-50">
                <input type="checkbox" checked={staffIds.includes(s.id)} onChange={() => toggle(s.id)} />
                {s.name}{s.department && <span className="text-xs text-gray-400">· {s.department}</span>}
              </label>
            ))}
            {shown.length === 0 && <p className="px-3 py-3 text-sm text-gray-400">No employees match.</p>}
          </div>
          {staffIds.length === 0 && <p className="mt-1 text-xs text-red-500">Pick at least one employee.</p>}
        </div>
      )}
    </div>
  )
}
