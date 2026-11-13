// Per-staff account restrictions, shown inside Staff Access: limit a staff member to specific WA Chat sessions,
// WA Cloud numbers, Instagram or Meta Ads accounts. Nothing checked for a type = unrestricted for that type;
// a role that already grants "view all" for a type overrides any restriction here.
import { useEffect, useState, useCallback } from 'react'
import { staffAccountAccessApi } from '@/api'
import { Button, Spinner } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

export type AccountRow = { id: number; label: string; sub?: string }

const TYPE_META: Record<string, { title: string; icon: string }> = {
  wa_session:        { title: 'WA Chat Sessions',   icon: '💬' },
  phone_number:      { title: 'WA Cloud Numbers',   icon: '☁️' },
  instagram_account: { title: 'Instagram Accounts', icon: '📸' },
  meta_ads_account:  { title: 'Meta Ads Accounts',  icon: '📢' },
}

export default function StaffAccountAccessCards({ staffId, staffName, roleLabel, accounts }: {
  staffId: number; staffName: string; roleLabel?: string; accounts: Record<string, AccountRow[]>
}) {
  const [access, setAccess] = useState<Record<string, number[]>>({})
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState<string | null>(null)

  const load = useCallback(() => {
    setLoading(true)
    staffAccountAccessApi.show(staffId)
      .then((r) => setAccess(r.data.access ?? {}))
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [staffId])
  useEffect(() => { load() }, [load])

  const toggle = (type: string, id: number) => setAccess((prev) => {
    const cur = prev[type] ?? []
    return { ...prev, [type]: cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id] }
  })

  const saveType = async (type: string) => {
    setSaving(type)
    try {
      await staffAccountAccessApi.update(staffId, { account_type: type, account_ids: access[type] ?? [] })
      toast.success(`${TYPE_META[type]?.title ?? type} access saved.`)
    } catch (e) { toast.error(getError(e)) } finally { setSaving(null) }
  }

  if (loading) return <div className="flex justify-center py-10"><Spinner /></div>
  return (
    <>
      {Object.entries(TYPE_META).map(([type, meta]) => {
        const list = accounts[type] ?? []
        const checked = access[type] ?? []
        return (
          <div className="card" key={type}>
            <div className="card-header">
              <h3 className="card-title">{meta.icon} {meta.title}</h3>
              <span className="text-xs text-gray-400">{checked.length === 0 ? 'Unrestricted (sees all)' : `Restricted to ${checked.length} of ${list.length}`}</span>
            </div>
            <div className="card-body">
              {list.length === 0 ? (
                <p className="text-xs text-gray-400">No {meta.title.toLowerCase()} connected yet for this company.</p>
              ) : (
                <div className="max-h-56 space-y-1.5 overflow-y-auto">
                  {list.map((a) => (
                    <label key={a.id} className="flex items-center gap-2 py-1 text-sm text-gray-700">
                      <input type="checkbox" checked={checked.includes(a.id)} onChange={() => toggle(type, a.id)} />
                      <span>{a.label}</span>
                      {a.sub && <span className="text-xs text-gray-400">({a.sub})</span>}
                    </label>
                  ))}
                </div>
              )}
              <div className="mt-3 flex justify-end">
                <Button size="sm" variant="secondary" loading={saving === type} disabled={list.length === 0} onClick={() => saveType(type)}>Save {meta.title}</Button>
              </div>
            </div>
          </div>
        )
      })}
      {roleLabel && (
        <p className="px-1 text-xs text-gray-400">
          Note: if {staffName}'s role ({roleLabel}) already grants "view all" for a section, that role permission wins regardless of what's set here — remove it from the role first if you want this restriction to take effect.
        </p>
      )}
    </>
  )
}
