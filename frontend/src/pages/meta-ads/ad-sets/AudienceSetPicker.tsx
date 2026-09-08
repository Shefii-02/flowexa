// Picker shown in the ad-set builder: your saved audience sets AND the system templates, in one
// place. A saved set can be reused in a single click; anything can be opened as an editable copy.
import { useEffect, useState } from 'react'
import { Modal, Badge, Input, Button } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { metaAdsApi } from '../api/meta-ads'
import { emptyAudienceDraft, toDraft, type AudienceSet, type AudienceSetDraft, type AudienceTemplate, type IdName } from '../audiences/types'

export type AudienceChoice =
  | { kind: 'set'; id: number; name: string }
  | { kind: 'draft'; name: string; draft: AudienceSetDraft }

function templateToDraft(t: AudienceTemplate): AudienceSetDraft {
  const base = emptyAudienceDraft()
  const tj = (t.targeting_json ?? {}) as Record<string, unknown>
  const asPairs = (v: unknown): IdName[] =>
    Array.isArray(v) ? v.map(x => ({ id: String((x as IdName).id ?? ''), name: String((x as IdName).name ?? '') })).filter(p => p.id) : []
  return {
    ...base,
    name: t.name,
    description: t.description ?? '',
    age_min: t.age_min ?? base.age_min,
    age_max: t.age_max ?? base.age_max,
    genders: t.genders === 'male' ? 'male' : t.genders === 'female' ? 'female' : 'all',
    interests: asPairs(t.interests) .length ? asPairs(t.interests) : asPairs((tj.flexible_spec as { interests?: unknown }[] | undefined)?.[0]?.interests),
    behaviors: asPairs(t.behaviors).length ? asPairs(t.behaviors) : asPairs((tj.flexible_spec as { behaviors?: unknown }[] | undefined)?.[0]?.behaviors),
    geo_locations: (tj.geo_locations as AudienceSetDraft['geo_locations']) ?? base.geo_locations,
  }
}

export function AudienceSetPicker({ onChoose }: { onChoose: (c: AudienceChoice) => void }) {
  const [open, setOpen] = useState(false)
  const [tab, setTab] = useState<'saved' | 'templates'>('saved')
  const [sets, setSets] = useState<AudienceSet[]>([])
  const [templates, setTemplates] = useState<AudienceTemplate[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!open) return
    setLoading(true)
    metaAdsApi.audienceSets(true)
      .then(r => {
        setSets(r.data.audience_sets ?? [])
        setTemplates(r.data.templates ?? [])
        setTab((r.data.audience_sets ?? []).length ? 'saved' : 'templates')
      })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [open])

  const q = search.toLowerCase()
  const filteredSets = sets.filter(s => !q || s.name.toLowerCase().includes(q))
  const filteredTpls = templates.filter(t => !q || t.name.toLowerCase().includes(q) || (t.industry ?? '').toLowerCase().includes(q))

  const choose = (c: AudienceChoice) => { onChoose(c); setOpen(false) }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}
        className="w-full border border-dashed border-brand-300 rounded-xl p-4 text-center hover:bg-brand-50 transition-colors">
        <p className="text-sm font-medium text-brand-600">🎯 Pick an audience</p>
        <p className="text-xs text-gray-400 mt-1">Reuse a saved audience in one click, or start from a template</p>
      </button>

      <Modal open={open} onClose={() => setOpen(false)} title="Choose an audience" size="xl">
        <div className="flex gap-2 mb-3">
          {(['saved', 'templates'] as const).map(t => (
            <button key={t} onClick={() => setTab(t)}
              className={`text-sm px-3 py-1.5 rounded-lg ${tab === t ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'}`}>
              {t === 'saved' ? `Saved audiences${sets.length ? ` (${sets.length})` : ''}` : 'Templates'}
            </button>
          ))}
          <Input placeholder="Search…" value={search} onChange={e => setSearch(e.target.value)} className="flex-1" />
        </div>

        {loading ? (
          <p className="text-sm text-gray-400 py-8 text-center">Loading…</p>
        ) : tab === 'saved' ? (
          filteredSets.length === 0 ? (
            <p className="text-sm text-gray-400 py-8 text-center">No saved audiences. Build one from the Audience Sets page or start from a template.</p>
          ) : (
            <div className="grid sm:grid-cols-2 gap-3 max-h-[440px] overflow-y-auto">
              {filteredSets.map(s => (
                <div key={s.id} className="border border-gray-200 rounded-xl p-4">
                  <div className="flex items-start justify-between">
                    <p className="font-medium text-gray-900 text-sm">{s.name}</p>
                    {s.is_favorite && <span className="text-amber-400">★</span>}
                  </div>
                  <div className="flex flex-wrap gap-2 text-xs text-gray-400 mt-1">
                    <span>👤 {s.age_min}–{s.age_max}</span>
                    <span>⚥ {s.genders === 'all' ? 'All' : s.genders === 'male' ? 'Men' : 'Women'}</span>
                    {s.use_count > 0 && <span>♻ {s.use_count}×</span>}
                    {s.reach_max ? <span>📊 {fmt.number(s.reach_min ?? 0)}–{fmt.number(s.reach_max)}</span> : null}
                  </div>
                  <div className="flex gap-2 mt-3">
                    <Button size="sm" onClick={() => choose({ kind: 'set', id: s.id, name: s.name })}>Use</Button>
                    <Button size="sm" variant="secondary"
                      onClick={() => choose({ kind: 'draft', name: s.name, draft: { ...toDraft(s), name: `${s.name} (custom)` } })}>
                      Customise
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )
        ) : (
          <div className="grid sm:grid-cols-2 gap-3 max-h-[440px] overflow-y-auto">
            {filteredTpls.map(t => (
              <div key={t.id} className="border border-gray-200 rounded-xl p-4">
                <div className="flex items-start justify-between mb-1">
                  <p className="font-medium text-gray-900 text-sm">{t.name}</p>
                  {t.industry && <Badge variant="blue">{t.industry}</Badge>}
                </div>
                <p className="text-xs text-gray-500 mb-2 line-clamp-2">{t.description}</p>
                <div className="flex gap-2 text-xs text-gray-400 flex-wrap">
                  <span>👤 {t.age_min}–{t.age_max}</span>
                  <span>💰 ₹{fmt.number(t.suggested_daily_budget)}/day</span>
                </div>
                <div className="flex gap-2 mt-3">
                  <Button size="sm" onClick={() => choose({ kind: 'draft', name: t.name, draft: templateToDraft(t) })}>Use</Button>
                  <Button size="sm" variant="secondary"
                    onClick={() => choose({ kind: 'draft', name: t.name, draft: { ...templateToDraft(t), name: `${t.name} (custom)` } })}>
                    Customise
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Modal>
    </>
  )
}
