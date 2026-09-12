// Search-as-you-type staff picker — used everywhere a lead gets switched to someone else.
// `excludeId` hides one person from the results (typically whoever the leads are moving FROM).
import { useEffect, useRef, useState } from 'react'

export interface StaffOption { id: number | string; name: string; department?: string | null }

export default function StaffPicker({ staff, excludeId, value, onChange, placeholder = 'Search employee…' }: {
  staff: StaffOption[]
  excludeId?: string | number | null
  value: string
  onChange: (id: string) => void
  placeholder?: string
}) {
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const boxRef = useRef<HTMLDivElement>(null)

  const selected = staff.find((s) => String(s.id) === String(value))

  useEffect(() => {
    const onClick = (e: MouseEvent) => { if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [])

  const matches = staff
    .filter((s) => excludeId == null || String(s.id) !== String(excludeId))
    .filter((s) => s.name.toLowerCase().includes(query.toLowerCase()))
    .slice(0, 8)

  if (selected) {
    return (
      <div className="flex items-center justify-between border border-gray-200 rounded-lg px-3 py-2">
        <div>
          <p className="text-sm font-medium text-gray-900">{selected.name}</p>
          {selected.department && <p className="text-xs text-gray-400">{selected.department}</p>}
        </div>
        <button onClick={() => { onChange(''); setQuery('') }} className="text-xs text-gray-400 hover:underline">Change</button>
      </div>
    )
  }

  return (
    <div ref={boxRef} className="relative">
      <input
        className="input w-full"
        placeholder={placeholder}
        value={query}
        onFocus={() => setOpen(true)}
        onChange={(e) => { setQuery(e.target.value); setOpen(true) }}
      />
      {open && matches.length > 0 && (
        <div className="absolute z-10 mt-1 w-full border border-gray-200 bg-white rounded-lg shadow-lg divide-y divide-gray-100 max-h-48 overflow-y-auto">
          {matches.map((s) => (
            <button key={s.id} type="button"
              onClick={() => { onChange(String(s.id)); setQuery(''); setOpen(false) }}
              className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50">
              <span className="font-medium text-gray-900">{s.name}</span>
              {s.department && <span className="text-xs text-gray-400 ml-1.5">{s.department}</span>}
            </button>
          ))}
        </div>
      )}
      {open && query && matches.length === 0 && (
        <div className="absolute z-10 mt-1 w-full border border-gray-200 bg-white rounded-lg shadow-lg px-3 py-2 text-xs text-gray-400">
          No employee matches "{query}"
        </div>
      )}
    </div>
  )
}
