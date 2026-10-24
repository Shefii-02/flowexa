// src/pages/staff/StaffAccessPage.tsx
// Per-staff access: prebuilt access sections (one main + any number of sub sections, layered on top of the
// staff member's role) and which channels they may use — the web portal and/or the mobile app.
import { useEffect, useState, useCallback, useMemo } from 'react'
import { staffAccessApi, staffAccountAccessApi } from '@/api'
import { Button, EmptyState, Spinner } from '@/components/ui'
import AccessSectionPicker, { sectionAddedPermissions, type AccessSection } from './AccessSectionPicker'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

type Section = AccessSection
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
  const [rolePerms, setRolePerms] = useState<string[]>([])
  const [allPerms, setAllPerms] = useState<string[]>([])

  useEffect(() => {
    Promise.all([staffAccountAccessApi.options(), staffAccessApi.sections()])
      .then(([o, s]) => { setStaff(o.data.staff ?? []); setSections(s.data.sections ?? []) })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [])

  const load = useCallback((id: number) => {
    setBusy(true)
    staffAccessApi.show(id)
      .then((r) => { setPortal(r.data.portal_access); setMobile(r.data.mobile_access); setMain(r.data.main ?? null); setSubs(r.data.subs ?? []); setRolePerms(r.data.role_permissions ?? []); setAllPerms(r.data.all_permissions ?? []) })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setBusy(false))
  }, [])
  useEffect(() => { if (selectedId) load(selectedId) }, [selectedId, load])

  const filtered = useMemo(
    () => staff.filter((s) => !search || `${s.name} ${s.email}`.toLowerCase().includes(search.toLowerCase())),
    [staff, search],
  )
  const selected = staff.find((s) => s.id === selectedId) ?? null

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
                <div className="card-body">
                  <AccessSectionPicker sections={sections} main={main} subs={subs} onChange={(n) => { setMain(n.main); setSubs(n.subs) }} />
                </div>
              </div>

              {(() => {
                const keys = [...(main ? [main] : []), ...subs]
                const { all, added } = sectionAddedPermissions(sections, keys, rolePerms, allPerms)
                return (
                  <div className="card">
                    <div className="card-header"><h3 className="card-title">Role &amp; permissions</h3>
                      <span className="text-xs text-gray-400">{selected.role?.label ?? 'No role'} + chosen sections</span></div>
                    <div className="card-body space-y-3">
                      <p className="text-sm text-gray-700">
                        Role gives <b>{rolePerms.length}</b> permission{rolePerms.length === 1 ? '' : 's'};
                        the chosen sections add <b>{all ? 'every remaining' : added.length}</b>{all ? '' : ' more'}.
                      </p>
                      {added.length > 0 && (
                        <div className="flex flex-wrap gap-1.5 max-h-40 overflow-y-auto">
                          {added.map((p) => <span key={p} className="text-[11px] px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200">+ {p}</span>)}
                        </div>
                      )}
                      <p className="text-xs text-gray-400">Change the role's own permissions under Roles.</p>
                    </div>
                  </div>
                )
              })()}

              <div className="flex justify-end"><Button loading={saving} onClick={save}>Save access</Button></div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
