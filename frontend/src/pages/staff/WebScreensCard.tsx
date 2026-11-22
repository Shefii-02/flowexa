export interface WebSection { id: string; label: string }

/** Per-staff WEB menu, managed on its own (the app has its own card): follow the company layout (value = null) or pick the sections for this person. */
export default function WebScreensCard({ name, value, sections, onChange }: {
  name: string; value: string[] | null; sections: WebSection[]; onChange: (v: string[] | null) => void
}) {
  const custom = value !== null
  return (
    <div className="card" data-testid="web-screens-card">
      <div className="card-header"><h3 className="card-title">🖥️ Web screens</h3><span className="text-xs text-gray-400">What {name} sees on the web</span></div>
      <div className="card-body space-y-3">
        <div className="space-y-1.5 text-sm text-gray-700">
          <label className="flex items-center gap-2"><input type="radio" checked={!custom} onChange={() => onChange(null)} /> Follow the company layout</label>
          <label className="flex items-center gap-2"><input type="radio" checked={custom} onChange={() => !custom && onChange(sections.map(s => s.id))} /> Choose menu sections for {name}</label>
        </div>
        {value && (
          <>
            <p className="text-xs text-gray-500">These can only narrow the company layout — a section the company or platform has hidden stays hidden.</p>
            <div className="flex flex-wrap gap-x-5 gap-y-1">
              {sections.map(s => (
                <label key={s.id} className="flex items-center gap-1.5 text-sm">
                  <input type="checkbox" checked={value.includes(s.id)} onChange={e => onChange(e.target.checked ? [...value, s.id] : value.filter(x => x !== s.id))} /> {s.label}
                </label>
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  )
}
