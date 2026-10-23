// src/pages/staff/StaffAccessPage.tsx
// Per-staff access: prebuilt access sections (one main + any number of sub sections, layered on top of the
// staff member's role) and which channels they may use — the web portal and/or the mobile app.
import { useEffect, useState, useCallback, useMemo } from 'react'
import { staffAccessApi, staffAccountAccessApi } from '@/api'
import { Button, EmptyState, Spinner } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

type Section = { key: string; label: string; icon: string; color: string; description: string; permissions: string[] }
type StaffRow = { id: number; name: string; email: string; role?: { label: string } }

export default function StaffAccessPage() {
  const [loading, setLoading] = useState(true)
  const [staff, setStaff] = useState<StaffRow[]>([])
  const [sections, setSections] = useState<Section[]>([])
  const [search, setSearch] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)
  const [saving, setSaving] = useState(false)
  const [portal, setPortal] = useState(true)
  const [mobile, setMobile] = useState(true)
  const [main, setMain] = useState<string | null>(null)
  const [subs, setSubs] = useState<string[]>([])

  useEffect(() => {
    Promise.all([staffAccountAccessApi.options(), staffAccessApi.sections()])
      .then(([o, s]) => { setStaff(o.data.staff ?? []); setSections(s.data.sections ?? []) })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [])

  const load = useCallback((id: number) => {
    setBusy(true)
    staffAccessApi.show(id)
      .then((r) => { setPortal(r.data.portal_access); setMobile(r.data.mobile_access); setMain(r.data.main ?? null); setSubs(r.data.subs ?? []) })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setBusy(false))
  }, [])
  useEffect(() => { if (selectedId) load(selectedId) }, [selectedId, load])

  const filtered = useMemo(
    () => staff.filter((s) => !search || `${s.name} ${s.email}`.toLowerCase().includes(search.toLowerCase())),
    [staff, search],
  )
  const selected = staff.find((s) => s.id === selectedId) ?? null

  const toggleSub = (key: string) => setSubs((p) => (p.includes(key) ? p.filter((k) => k !== key) : [...p, key]))
  const pickMain = (key: string) => {
    setMain((cur) => (cur === key ? null : key))
    setSubs((p) => p.filter((k) => k !== key))
  }

  const save = async () => {
    if (!selectedId) return
    setSaving(true)
    try {
      await staffAccessApi.update(selectedId, { portal_access: portal, mobile_access: mobile, main, subs })
      toast.success('Access saved.')
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  if (loading) return <div className="flex justify-center py-24"><Spinner size="lg" /></div>

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Staff Access</h1>
        <p className="page-sub">Give each staff member a main access section and any sub sections, and choose whether they can use the web portal and the mobile app. Sections add to what their role already allows.</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-5">
        <div className="card">
          <div className="card-header"><input className="input" placeholder="Search staff..." value={search} onChange={(e) => setSearch(e.target.value)} /></div>
          <div className="max-h-[70vh] overflow-y-auto">
            {filtered.length === 0 ? <EmptyState icon="👤" title="No staff found" /> : filtered.map((s) => (
              <button key={s.id} onClick={() => setSelectedId(s.id)}
                className={`w-full text-left px-4 py-3 border-b border-gray-100 hover:bg-gray-50 ${selectedId === s.id ? 'bg-brand-50' : ''}`}>
                <p className="text-sm font-medium text-gray-900">{s.name}</p>
                <p className="text-xs text-gray-400">{s.email} {s.role ? `· ${s.role.label}` : ''}</p>
              </button>
            ))}
          </div>
        </div>

        <div className="space-y-5">
          {!selected ? (
            <div className="card"><div className="card-body"><EmptyState icon="🔐" title="Select a staff member" desc="Pick someone from the list to manage their access." /></div></div>
          ) : busy ? (
            <div className="flex justify-center py-16"><Spinner /></div>
          ) : (
            <>
              <div className="card">
                <div className="card-header"><h3 className="card-title">Where {selected.name} can sign in</h3></div>
                <div className="card-body space-y-2">
                  <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={portal} onChange={(e) => setPortal(e.target.checked)} /> 🖥️ Web portal</label>
                  <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" checked={mobile} onChange={(e) => setMobile(e.target.checked)} /> 📱 Mobile app</label>
                  <p className="text-xs text-gray-400">Owners are never locked out.</p>
                </div>
              </div>

              <div className="card">
                <div className="card-header"><h3 className="card-title">Access sections</h3><span className="text-xs text-gray-400">Choose one main, tick any extra sub sections</span></div>
                <div className="card-body grid grid-cols-1 md:grid-cols-2 gap-3">
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
              </div>

              <div className="flex justify-end"><Button loading={saving} onClick={save}>Save access</Button></div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
