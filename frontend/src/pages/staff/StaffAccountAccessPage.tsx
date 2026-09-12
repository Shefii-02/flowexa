// src/pages/staff/StaffAccountAccessPage.tsx
// Restrict a staff member to specific connected accounts — WA Chat sessions, WA
// Cloud numbers, Instagram accounts — instead of the company-wide default. A
// staff member with nothing checked for a type stays unrestricted for that type;
// a role that already grants "view all" for a type overrides any restriction here.
import { useEffect, useState, useCallback, useMemo } from 'react'
import { staffAccountAccessApi } from '@/api'
import { Button, EmptyState, Spinner } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

type AccountRow = { id: number; label: string; sub?: string }
type StaffRow = { id: number; name: string; email: string; role?: { id: number; name: string; label: string } }

const TYPE_META: Record<string, { title: string; icon: string; viewAllPermission: string }> = {
  wa_session:         { title: 'WA Chat Sessions',    icon: '💬', viewAllPermission: 'wa_chat.sessions.view_all' },
  phone_number:       { title: 'WA Cloud Numbers',    icon: '☁️', viewAllPermission: 'inbox.view_all' },
  instagram_account:  { title: 'Instagram Accounts',  icon: '📸', viewAllPermission: 'instagram.view_all' },
  meta_ads_account:   { title: 'Meta Ads Accounts',   icon: '📢', viewAllPermission: 'meta_ads.view_all' },
}

export default function StaffAccountAccessPage() {
  const [loading, setLoading] = useState(true)
  const [staff, setStaff] = useState<StaffRow[]>([])
  const [accounts, setAccounts] = useState<Record<string, AccountRow[]>>({})
  const [search, setSearch] = useState('')

  const [selectedStaffId, setSelectedStaffId] = useState<number | null>(null)
  const [access, setAccess] = useState<Record<string, number[]>>({})
  const [accessLoading, setAccessLoading] = useState(false)
  const [saving, setSaving] = useState<string | null>(null)

  useEffect(() => {
    staffAccountAccessApi.options()
      .then((r) => { setStaff(r.data.staff ?? []); setAccounts(r.data.accounts ?? {}) })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [])

  const loadAccess = useCallback((userId: number) => {
    setAccessLoading(true)
    staffAccountAccessApi.show(userId)
      .then((r) => setAccess(r.data.access ?? {}))
      .catch((e) => toast.error(getError(e)))
      .finally(() => setAccessLoading(false))
  }, [])

  useEffect(() => { if (selectedStaffId) loadAccess(selectedStaffId) }, [selectedStaffId, loadAccess])

  const filteredStaff = useMemo(
    () => staff.filter((s) => !search || s.name.toLowerCase().includes(search.toLowerCase()) || s.email.toLowerCase().includes(search.toLowerCase())),
    [staff, search]
  )
  const selectedStaff = staff.find((s) => s.id === selectedStaffId) ?? null

  const toggleAccount = (type: string, accountId: number) => {
    setAccess((prev) => {
      const current = prev[type] ?? []
      const next = current.includes(accountId) ? current.filter((id) => id !== accountId) : [...current, accountId]
      return { ...prev, [type]: next }
    })
  }

  const saveType = async (type: string) => {
    if (!selectedStaffId) return
    setSaving(type)
    try {
      await staffAccountAccessApi.update(selectedStaffId, { account_type: type, account_ids: access[type] ?? [] })
      toast.success(`${TYPE_META[type]?.title ?? type} access saved.`)
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setSaving(null)
    }
  }

  if (loading) return <div className="flex justify-center py-24"><Spinner size="lg" /></div>

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Staff Account Access</h1>
        <p className="page-sub">Restrict a staff member to specific WA Chat sessions, WA Cloud numbers or Instagram accounts. Leave everything unchecked for a type to keep full access to it.</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-5">
        {/* Staff list */}
        <div className="card">
          <div className="card-header"><input className="input" placeholder="Search staff..." value={search} onChange={(e) => setSearch(e.target.value)} /></div>
          <div className="max-h-[70vh] overflow-y-auto">
            {filteredStaff.length === 0 ? (
              <EmptyState icon="👤" title="No staff found" />
            ) : filteredStaff.map((s) => (
              <button
                key={s.id}
                onClick={() => setSelectedStaffId(s.id)}
                className={`w-full text-left px-4 py-3 border-b border-gray-100 hover:bg-gray-50 transition-colors ${selectedStaffId === s.id ? 'bg-brand-50' : ''}`}
              >
                <p className="text-sm font-medium text-gray-900">{s.name}</p>
                <p className="text-xs text-gray-400">{s.email} {s.role ? `· ${s.role.label}` : ''}</p>
              </button>
            ))}
          </div>
        </div>

        {/* Access editor */}
        <div className="space-y-5">
          {!selectedStaff ? (
            <div className="card"><div className="card-body"><EmptyState icon="🔐" title="Select a staff member" desc="Pick someone from the list to manage their account access." /></div></div>
          ) : accessLoading ? (
            <div className="flex justify-center py-16"><Spinner /></div>
          ) : (
            Object.entries(TYPE_META).map(([type, meta]) => {
              const list = accounts[type] ?? []
              const checked = access[type] ?? []
              return (
                <div className="card" key={type}>
                  <div className="card-header">
                    <h3 className="card-title">{meta.icon} {meta.title}</h3>
                    <span className="text-xs text-gray-400">
                      {checked.length === 0 ? 'Unrestricted (sees all)' : `Restricted to ${checked.length} of ${list.length}`}
                    </span>
                  </div>
                  <div className="card-body">
                    {list.length === 0 ? (
                      <p className="text-xs text-gray-400">No {meta.title.toLowerCase()} connected yet for this company.</p>
                    ) : (
                      <div className="space-y-1.5 max-h-56 overflow-y-auto">
                        {list.map((a) => (
                          <label key={a.id} className="flex items-center gap-2 text-sm text-gray-700 py-1">
                            <input type="checkbox" checked={checked.includes(a.id)} onChange={() => toggleAccount(type, a.id)} />
                            <span>{a.label}</span>
                            {a.sub && <span className="text-xs text-gray-400">({a.sub})</span>}
                          </label>
                        ))}
                      </div>
                    )}
                    <div className="flex justify-end mt-3">
                      <Button size="sm" variant="secondary" loading={saving === type} disabled={list.length === 0} onClick={() => saveType(type)}>
                        Save {meta.title}
                      </Button>
                    </div>
                  </div>
                </div>
              )
            })
          )}
          {selectedStaff?.role && (
            <p className="text-xs text-gray-400 px-1">
              Note: if {selectedStaff.name}'s role ({selectedStaff.role.label}) already grants "view all" for a section, that role permission wins regardless of what's set here — remove it from the role first if you want this restriction to take effect.
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
