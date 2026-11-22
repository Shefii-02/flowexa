import { useEffect, useMemo, useState } from 'react'
import toast from 'react-hot-toast'
import api from '@/api/client'
import { Button, Spinner } from '@/components/ui'
import { getError } from '@/utils'

interface Layout { tabs: Record<string, boolean>; profile_items: Record<string, boolean>; hidden_modules: string[]; hidden_sections: string[] }
type Audience = 'staff' | 'team_head'
interface Cfg {
  staff: Layout; team_head: Layout
  tab_labels: Record<string, string>; profile_item_labels: Record<string, string>
  catalogue: { id: string; title: string; group: string }[]
  web_sections: { id: string; label: string }[]
}

function Switch({ on, onChange, label }: { on: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <label className="flex items-center justify-between gap-3 py-2.5 border-b border-gray-100 last:border-0 cursor-pointer">
      <span className="text-sm text-gray-800">{label}</span>
      <input type="checkbox" checked={on} onChange={e => onChange(e.target.checked)} className="h-4 w-4 accent-indigo-600" />
    </label>
  )
}

/**
 * Company admin: choose what each kind of user sees in the mobile app — bottom tabs, Profile menu items and Explore tiles — separately for
 * staff and for team heads. A team head is also staff, so they get the two layouts combined (shown if either shows it).
 */
export default function MobileAppTab() {
  const [cfg, setCfg] = useState<Cfg | null>(null)
  const [saving, setSaving] = useState(false)
  const [who, setWho] = useState<Audience>('staff')

  useEffect(() => {
    api.get('/settings/mobile-app').then(r => setCfg(r.data)).catch(e => toast.error(getError(e)))
  }, [])

  const groups = useMemo(() => {
    const m = new Map<string, Cfg['catalogue']>()
    cfg?.catalogue.forEach(c => m.set(c.group, [...(m.get(c.group) ?? []), c]))
    return [...m.entries()]
  }, [cfg])

  if (!cfg) return <div className="flex justify-center py-16"><Spinner size="lg" /></div>

  const cur = cfg[who]
  const patch = (p: Partial<Layout>) => setCfg({ ...cfg, [who]: { ...cur, ...p } })
  const setTab = (k: string, v: boolean) => patch({ tabs: { ...cur.tabs, [k]: v } })
  const setItem = (k: string, v: boolean) => patch({ profile_items: { ...cur.profile_items, [k]: v } })
  const setSection = (id: string, shown: boolean) =>
    patch({ hidden_sections: shown ? (cur.hidden_sections ?? []).filter(h => h !== id) : [...(cur.hidden_sections ?? []), id] })
  const setHidden = (id: string, shown: boolean) =>
    patch({ hidden_modules: shown ? cur.hidden_modules.filter(h => h !== id) : [...cur.hidden_modules, id] })

  const save = async () => {
    setSaving(true)
    try {
      const { data } = await api.put('/settings/mobile-app', { staff: cfg.staff, team_head: cfg.team_head })
      setCfg({ ...cfg, ...data })
      toast.success('Saved. Staff see the change the next time they open the app.')
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  return (
    <div className="space-y-6" data-testid="mobile-app-tab">
      <div className="card p-5">
        <div className="inline-flex rounded-lg border border-gray-200 p-0.5 mb-2" role="tablist">
          {([['staff', 'Staff'], ['team_head', 'Team heads']] as const).map(([id, label]) => (
            <button key={id} role="tab" aria-selected={who === id} onClick={() => setWho(id)}
              className={`px-4 py-1.5 text-sm rounded-md ${who === id ? 'bg-indigo-600 text-white' : 'text-gray-600'}`}>{label}</button>
          ))}
        </div>
        <p className="text-xs text-gray-500">
          {who === 'staff'
            ? 'What your staff see. Applies to everyone who is not a team head.'
            : 'Extra screens for team heads (owner, admin, team lead, HR). A team head is also staff, so they see these AND everything staff see.'}
        </p>
      </div>
      <div className="card p-5">
        <h2 className="text-sm font-semibold text-gray-900">Web menu sections</h2>
        <p className="text-xs text-gray-500 mb-2">Untick a section to hide it from {who === 'staff' ? 'your staff' : 'team heads'} on the web. Only sections the platform allows are listed. Their access is not changed — only the menu.</p>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8">
          {cfg.web_sections.map(s => <Switch key={s.id} label={s.label} on={!(cur.hidden_sections ?? []).includes(s.id)} onChange={v => setSection(s.id, v)} />)}
        </div>
      </div>

      <div className="card p-5">
        <h2 className="text-sm font-semibold text-gray-900">Bottom tabs</h2>
        <p className="text-xs text-gray-500 mb-2">Home and Profile are always shown.</p>
        {Object.entries(cfg.tab_labels).map(([k, label]) => <Switch key={k} label={label} on={cur.tabs[k] !== false} onChange={v => setTab(k, v)} />)}
      </div>

      <div className="card p-5">
        <h2 className="text-sm font-semibold text-gray-900">Profile menu</h2>
        <p className="text-xs text-gray-500 mb-2">Log out is always available.</p>
        {Object.entries(cfg.profile_item_labels).map(([k, label]) => <Switch key={k} label={label} on={cur.profile_items[k] !== false} onChange={v => setItem(k, v)} />)}
      </div>

      <div className="card p-5">
        <h2 className="text-sm font-semibold text-gray-900">Explore tiles</h2>
        <p className="text-xs text-gray-500 mb-3">Untick a tile to hide it from your staff's Explore screen. Web is not affected.</p>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8">
          {groups.map(([group, items]) => (
            <div key={group} className="mb-4">
              <div className="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{group}</div>
              {items.map(c => <Switch key={c.id} label={c.title} on={!cur.hidden_modules.includes(c.id)} onChange={v => setHidden(c.id, v)} />)}
            </div>
          ))}
        </div>
      </div>

      <Button onClick={save} loading={saving}>Save changes</Button>
    </div>
  )
}
