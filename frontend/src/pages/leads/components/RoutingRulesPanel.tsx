// "Category & campaign routing" — send matching leads only to chosen employees (round robin or weighted),
// e.g. leads for the "New course" category → these 3 counsellors, or leads from a campaign / period → this team.
import { useCallback, useEffect, useState } from 'react'
import { leadAssignmentApi, leadCategoryApi, campaignApi, staffApi } from '@/api'
import { Button, Modal, Spinner } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

type Routing = {
  id?: number; name: string; match_type: 'category' | 'campaign'; category: string | null; campaign_id: number | null
  starts_at: string | null; ends_at: string | null; staff_ids: number[]; strategy: '' | 'algorithm' | 'round_robin' | null
  is_active: boolean; priority: number; staff_names?: string[]; campaign_name?: string | null
}

const blank = (): Routing => ({ name: '', match_type: 'category', category: '', campaign_id: null, starts_at: '', ends_at: '', staff_ids: [], strategy: '', is_active: true, priority: 100 })
const toLocal = (d: string | null) => d ? d.slice(0, 16).replace(' ', 'T') : ''
const input = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm'
const METHOD = { algorithm: 'Weighted algorithm', round_robin: 'Round robin' } as const

export default function RoutingRulesPanel() {
  const [rules, setRules] = useState<Routing[]>([])
  const [loading, setLoading] = useState(true)
  const [edit, setEdit] = useState<Routing | null>(null)
  const [saving, setSaving] = useState(false)
  const [categories, setCategories] = useState<string[]>([])
  const [campaigns, setCampaigns] = useState<{ id: number; name: string }[]>([])
  const [staff, setStaff] = useState<{ id: number; name: string; department?: string | null }[]>([])
  const [q, setQ] = useState('')

  const load = useCallback(async () => {
    try { setRules((await leadAssignmentApi.routingList()).data.data ?? []) }
    catch (e) { toast.error(getError(e)) } finally { setLoading(false) }
  }, [])

  useEffect(() => {
    load()
    leadCategoryApi.list({ names_only: 1 }).then(r => setCategories(r.data.categories ?? [])).catch(() => {})
    campaignApi.list({ per_page: 100 }).then(r => setCampaigns(r.data.data ?? r.data.campaigns ?? [])).catch(() => {})
    staffApi.performance().then(r => setStaff(r.data.performance ?? [])).catch(() => {})
  }, [load])

  const set = (k: keyof Routing, v: unknown) => setEdit(e => e && ({ ...e, [k]: v }))
  const toggleStaff = (id: number) => setEdit(e => e && ({ ...e, staff_ids: e.staff_ids.includes(id) ? e.staff_ids.filter(i => i !== id) : [...e.staff_ids, id] }))

  const save = async () => {
    if (!edit) return
    setSaving(true)
    try {
      await leadAssignmentApi.routingSave({
        ...edit, strategy: edit.strategy || null,
        category: edit.match_type === 'category' ? edit.category : null,
        campaign_id: edit.match_type === 'campaign' ? (edit.campaign_id || null) : null,
        starts_at: edit.starts_at || null, ends_at: edit.ends_at || null,
      }, edit.id)
      toast.success('Routing rule saved'); setEdit(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  const remove = async (r: Routing) => {
    if (!r.id || !confirm(`Delete "${r.name}"?`)) return
    try { await leadAssignmentApi.routingDelete(r.id); load() } catch (e) { toast.error(getError(e)) }
  }
  const toggleActive = async (r: Routing) => {
    try { await leadAssignmentApi.routingSave({ ...r, is_active: !r.is_active }, r.id); load() } catch (e) { toast.error(getError(e)) }
  }

  const describe = (r: Routing) => r.match_type === 'category'
    ? `Category: ${r.category}`
    : `Campaign: ${r.campaign_id ? (r.campaign_name ?? `#${r.campaign_id}`) : 'any campaign'}`
  const period = (r: Routing) => (r.starts_at || r.ends_at)
    ? `${r.starts_at ? new Date(r.starts_at).toLocaleDateString() : 'any time'} → ${r.ends_at ? new Date(r.ends_at).toLocaleDateString() : 'no end'}` : 'Always on'

  return (
    <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h2 className="font-semibold text-gray-800">Category &amp; campaign routing</h2>
          <p className="text-sm text-gray-500">Send leads of a category, or from a campaign / period, only to the employees you pick. Leads that match no rule use the normal routing. First matching rule (lowest priority number) wins. These rules are applied each time auto-assign runs — instantly or on its cron schedule (set in Routing).</p>
        </div>
        <Button onClick={() => setEdit(blank())}>+ Add routing rule</Button>
      </div>

      {loading ? <div className="flex justify-center py-8"><Spinner /></div> : rules.length === 0 ? (
        <p className="text-sm text-gray-400 py-6 text-center">No routing rules yet. Example: category “New course” → 3 selected counsellors, round robin.</p>
      ) : (
        <div className="divide-y divide-gray-100 border border-gray-200 rounded-lg">
          {rules.map(r => (
            <div key={r.id} className={`p-4 flex items-start justify-between gap-3 flex-wrap ${r.is_active ? '' : 'opacity-50'}`}>
              <div className="space-y-1 min-w-0">
                <p className="font-medium text-gray-900">{r.name} <span className="ml-1 text-xs text-gray-400">priority {r.priority}</span></p>
                <p className="text-sm text-gray-600">{describe(r)} · {period(r)} · {r.strategy ? METHOD[r.strategy as 'algorithm' | 'round_robin'] : 'default method'}</p>
                <div className="flex flex-wrap gap-1">{(r.staff_names ?? []).map(n => <span key={n} className="rounded-full bg-brand-50 text-brand-700 px-2 py-0.5 text-xs">{n}</span>)}</div>
              </div>
              <div className="flex gap-2 shrink-0">
                <Button size="sm" variant="secondary" onClick={() => toggleActive(r)}>{r.is_active ? 'Pause' : 'Enable'}</Button>
                <Button size="sm" variant="secondary" onClick={() => setEdit({ ...r, strategy: r.strategy ?? '', starts_at: toLocal(r.starts_at), ends_at: toLocal(r.ends_at) })}>Edit</Button>
                <Button size="sm" variant="danger" onClick={() => remove(r)}>Delete</Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? 'Edit routing rule' : 'New routing rule'} size="md">
        {edit && (
          <div className="space-y-3">
            <label className="block text-sm text-gray-600">Rule name
              <input className={`mt-1 ${input}`} value={edit.name} onChange={e => set('name', e.target.value)} placeholder="e.g. New course leads" />
            </label>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm text-gray-600">Route leads by
                <select className={`mt-1 ${input}`} value={edit.match_type} onChange={e => set('match_type', e.target.value)}>
                  <option value="category">Lead category</option>
                  <option value="campaign">Campaign / period</option>
                </select>
              </label>
              {edit.match_type === 'category' ? (
                <label className="block text-sm text-gray-600">Category
                  <select className={`mt-1 ${input}`} value={edit.category ?? ''} onChange={e => set('category', e.target.value)}>
                    <option value="">Select category…</option>
                    {categories.map(c => <option key={c} value={c}>{c}</option>)}
                  </select>
                </label>
              ) : (
                <label className="block text-sm text-gray-600">Campaign
                  <select className={`mt-1 ${input}`} value={edit.campaign_id ?? ''} onChange={e => set('campaign_id', e.target.value ? Number(e.target.value) : null)}>
                    <option value="">Any campaign (use the period)</option>
                    {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </select>
                </label>
              )}
            </div>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-sm text-gray-600">Active from (optional)
                <input type="datetime-local" className={`mt-1 ${input}`} value={edit.starts_at ?? ''} onChange={e => set('starts_at', e.target.value)} />
              </label>
              <label className="block text-sm text-gray-600">Active until (optional)
                <input type="datetime-local" className={`mt-1 ${input}`} value={edit.ends_at ?? ''} onChange={e => set('ends_at', e.target.value)} />
              </label>
            </div>
            <label className="block text-sm text-gray-600">Method
              <select className={`mt-1 ${input}`} value={edit.strategy ?? ''} onChange={e => set('strategy', e.target.value)}>
                <option value="">Use the default method</option>
                <option value="round_robin">Round robin among the selected staff</option>
                <option value="algorithm">Weighted algorithm among the selected staff</option>
              </select>
            </label>
            <div>
              <div className="flex items-center justify-between mb-1">
                <span className="text-sm text-gray-600">Employees ({edit.staff_ids.length} selected)</span>
                <input className="border border-gray-300 rounded-lg px-2 py-1 text-xs" placeholder="Search…" value={q} onChange={e => setQ(e.target.value)} />
              </div>
              <div className="max-h-48 overflow-y-auto border border-gray-200 rounded-lg divide-y divide-gray-100">
                {staff.filter(s => s.name.toLowerCase().includes(q.toLowerCase())).map(s => (
                  <label key={s.id} className="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-gray-50">
                    <input type="checkbox" checked={edit.staff_ids.includes(s.id)} onChange={() => toggleStaff(s.id)} />
                    {s.name}{s.department && <span className="text-xs text-gray-400">· {s.department}</span>}
                  </label>
                ))}
              </div>
            </div>
            <label className="block text-sm text-gray-600">Priority (lower runs first)
              <input type="number" min={1} className={`mt-1 ${input}`} value={edit.priority} onChange={e => set('priority', Number(e.target.value))} />
            </label>
            <div className="flex justify-end gap-2 pt-2">
              <Button variant="secondary" onClick={() => setEdit(null)}>Cancel</Button>
              <Button onClick={save} loading={saving}
                disabled={!edit.name || edit.staff_ids.length === 0 || (edit.match_type === 'category' && !edit.category) || (edit.match_type === 'campaign' && !edit.campaign_id && !edit.starts_at && !edit.ends_at)}>Save rule</Button>
            </div>
          </div>
        )}
      </Modal>
    </section>
  )
}
