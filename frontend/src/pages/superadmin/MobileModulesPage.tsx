import { useEffect, useMemo, useState } from 'react'
import { superadminApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

type Mod = { id: string; title: string; group: string }

export default function MobileModulesPage() {
  const [catalogue, setCatalogue] = useState<Mod[]>([])
  const [saved, setSaved] = useState<string[]>([])
  const [hidden, setHidden] = useState<Set<string>>(new Set())
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    superadminApi.mobileModules().then(r => {
      setCatalogue(r.data.catalogue)
      setSaved(r.data.hidden)
      setHidden(new Set(r.data.hidden))
    }).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [])

  const groups = useMemo(() => {
    const m = new Map<string, Mod[]>()
    catalogue.forEach(c => m.set(c.group, [...(m.get(c.group) ?? []), c]))
    return [...m.entries()]
  }, [catalogue])

  const toggle = (id: string) => setHidden(prev => {
    const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); return n
  })

  const dirty = hidden.size !== saved.length || [...hidden].some(k => !saved.includes(k))

  const save = async () => {
    setSaving(true)
    try {
      const r = await superadminApi.updateMobileModules([...hidden])
      setSaved(r.data.hidden)
      toast.success('Saved — the mobile app picks this up on its next refresh')
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  if (loading) return <div className="p-6 text-gray-400">Loading…</div>

  return (
    <div className="space-y-5">
      <div className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h1 className="page-title">Mobile App Tiles</h1>
          <p className="page-sub">Untick a tile to hide it from the mobile app&apos;s Explore screen. The web app is not affected.</p>
        </div>
        <button onClick={save} disabled={!dirty || saving}
          className="text-sm bg-indigo-600 text-white rounded-lg px-4 py-1.5 disabled:opacity-40">
          {saving ? 'Saving…' : 'Save changes'}
        </button>
      </div>

      {groups.map(([group, mods]) => (
        <div key={group} className="bg-white border border-gray-200 rounded-xl p-4">
          <h2 className="font-semibold text-sm mb-3">{group}</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
            {mods.map(m => (
              <label key={m.id} className="flex items-center gap-2 text-sm cursor-pointer">
                <input type="checkbox" checked={!hidden.has(m.id)} onChange={() => toggle(m.id)} />
                <span className={hidden.has(m.id) ? 'text-gray-400 line-through' : ''}>{m.title}</span>
              </label>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}
