import { useEffect, useState } from 'react'
import SideDrawer from './SideDrawer'
import type { StaffOption } from './StaffPicker'

// Tap Assign on a lead → the staff list opens here. Whoever has the lead now is marked "Current"; tap anyone else to
// assign (or switch) the lead to them.
export default function AssignStaffDrawer({ open, staff, currentId, currentName, busyId, onAssign, onClose }: {
  open: boolean
  staff: StaffOption[]
  currentId?: number | string | null
  currentName?: string | null
  busyId?: string | number | null
  onAssign: (id: number | string) => void
  onClose: () => void
}) {
  const [q, setQ] = useState('')
  useEffect(() => { if (!open) setQ('') }, [open])

  const list = staff
    .filter((s) => s.name.toLowerCase().includes(q.trim().toLowerCase()) || (s.department ?? '').toLowerCase().includes(q.trim().toLowerCase()))
    // current assignee first, then alphabetical
    .sort((a, b) => (String(b.id) === String(currentId) ? 1 : 0) - (String(a.id) === String(currentId) ? 1 : 0) || a.name.localeCompare(b.name))

  return (
    <SideDrawer open={open} onClose={onClose} title={currentName ? 'Switch staff' : 'Assign staff'}
      subtitle={currentName ? `Currently with ${currentName}` : 'This lead is not assigned yet'}>
      <div className="px-5 py-3 border-b border-gray-100 sticky top-0 bg-white">
        <input className="input w-full" placeholder="Search by name or department…" value={q} onChange={(e) => setQ(e.target.value)} autoFocus />
      </div>
      {list.length === 0 ? (
        <p className="p-5 text-sm text-gray-400">{staff.length === 0 ? 'No staff members found.' : `No staff match “${q}”.`}</p>
      ) : list.map((s) => {
        const isCurrent = String(s.id) === String(currentId)
        return (
          <div key={s.id} className={`px-5 py-3 flex items-center gap-3 border-b border-gray-50 ${isCurrent ? 'bg-green-50' : ''}`}>
            <span className="w-9 h-9 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-sm font-semibold flex-shrink-0">
              {s.name.trim().charAt(0).toUpperCase() || '?'}
            </span>
            <span className="flex-1 min-w-0">
              <span className="block text-sm font-medium text-gray-900 truncate">{s.name}</span>
              {s.department && <span className="block text-xs text-gray-400 truncate">{s.department}</span>}
            </span>
            {isCurrent ? (
              <span className="text-[11px] px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-semibold">Current</span>
            ) : (
              <button onClick={() => onAssign(s.id)} disabled={busyId != null}
                className="text-xs px-3 py-1.5 rounded-lg border border-brand-300 text-brand-700 hover:bg-brand-50 disabled:opacity-50">
                {String(busyId) === String(s.id) ? 'Assigning…' : currentName ? 'Switch to' : 'Assign'}
              </button>
            )}
          </div>
        )
      })}
    </SideDrawer>
  )
}
