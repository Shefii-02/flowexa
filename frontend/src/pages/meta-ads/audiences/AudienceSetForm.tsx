// The editable audience builder — used for "create new", "customize a copy", and inline in the
// ad-set flow. Talks to Meta's targeting catalog through the backend for interest/behaviour search.
import { useEffect, useRef, useState } from 'react'
import { Input, Badge, Button } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { metaAdsApi } from '../api/meta-ads'
import type { AudienceSetDraft, IdName } from './types'

const PLATFORMS = [
  { key: 'facebook', label: 'Facebook' },
  { key: 'instagram', label: 'Instagram' },
  { key: 'audience_network', label: 'Audience Network' },
  { key: 'messenger', label: 'Messenger' },
]

// Debounced typeahead against /meta-ads/audience-sets/targeting-search, rendering chips for the
// selected items. `type` is the Meta search type (adinterest | adTargetingCategory | adgeolocation).
function ChipSearch({
  label, hint, accountId, type, value, onChange,
}: {
  label: string
  hint?: string
  accountId: number | null
  type: string
  value: IdName[]
  onChange: (v: IdName[]) => void
}) {
  const [q, setQ] = useState('')
  const [results, setResults] = useState<IdName[]>([])
  const [loading, setLoading] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (timer.current) clearTimeout(timer.current)
    if (!accountId || q.trim().length < 2) { setResults([]); return }
    timer.current = setTimeout(async () => {
      setLoading(true)
      try {
        const r = await metaAdsApi.targetingSearch(accountId, q.trim(), type)
        setResults((r.data.results ?? []).map((x: Record<string, unknown>) => ({
          id: String(x.id ?? x.key ?? ''),
          name: String(x.name ?? ''),
          path: Array.isArray(x.path) ? (x.path as string[]) : undefined,
          audience_size_lower_bound: x.audience_size_lower_bound as number | undefined,
          audience_size_upper_bound: x.audience_size_upper_bound as number | undefined,
        })))
      } catch { setResults([]) }
      finally { setLoading(false) }
    }, 350)
    return () => { if (timer.current) clearTimeout(timer.current) }
  }, [q, accountId, type])

  const add = (item: IdName) => {
    if (!value.some(v => v.id === item.id)) onChange([...value, item])
    setQ(''); setResults([])
  }

  return (
    <div>
      <label className="label">{label}</label>
      {hint && <p className="text-xs text-gray-400 mb-1">{hint}</p>}
      <div className="flex flex-wrap gap-1.5 mb-2">
        {value.map(v => (
          <span key={v.id} className="inline-flex items-center gap-1 bg-brand-50 text-brand-700 text-xs rounded-full px-2 py-1">
            {v.name}
            <button type="button" onClick={() => onChange(value.filter(x => x.id !== v.id))} className="hover:text-brand-900">×</button>
          </span>
        ))}
        {value.length === 0 && <span className="text-xs text-gray-400">None selected</span>}
      </div>
      <div className="relative">
        <Input
          placeholder={accountId ? `Search ${label.toLowerCase()}…` : 'Connect an ad account first'}
          value={q}
          disabled={!accountId}
          onChange={e => setQ(e.target.value)}
        />
        {(loading || results.length > 0) && (
          <div className="absolute z-20 left-0 right-0 mt-1 max-h-56 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg">
            {loading && <div className="px-3 py-2 text-xs text-gray-400">Searching…</div>}
            {results.map(r => (
              <button
                key={r.id}
                type="button"
                onClick={() => add(r)}
                className="w-full text-left px-3 py-2 text-sm hover:bg-brand-50 flex items-center justify-between"
              >
                <span>
                  {r.name}
                  {r.path && r.path.length > 1 && <span className="text-gray-400 text-xs"> · {r.path.slice(0, -1).join(' › ')}</span>}
                </span>
                {r.audience_size_upper_bound ? (
                  <span className="text-xs text-gray-400">{fmt.number(r.audience_size_lower_bound ?? 0)}–{fmt.number(r.audience_size_upper_bound)}</span>
                ) : null}
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

export function AudienceSetForm({
  value, onChange, accountId, showName = true,
}: {
  value: AudienceSetDraft
  onChange: (v: AudienceSetDraft) => void
  accountId: number | null
  showName?: boolean
}) {
  const set = <K extends keyof AudienceSetDraft>(k: K, v: AudienceSetDraft[K]) => onChange({ ...value, [k]: v })
  const [reach, setReach] = useState<{ min: number; max: number; ready: boolean } | null>(null)
  const [estimating, setEstimating] = useState(false)

  const estimate = async () => {
    if (!accountId) { toast.error('Connect an ad account to estimate reach.'); return }
    setEstimating(true)
    try {
      const r = await metaAdsApi.estimateAudienceReach({ account_id: accountId, audience: value })
      const e = r.data.estimate
      setReach({ min: e.users_lower_bound, max: e.users_upper_bound, ready: e.ready })
    } catch (e) { toast.error(getError(e)) }
    finally { setEstimating(false) }
  }

  const togglePlatform = (key: string) => {
    const cur = value.placements.publisher_platforms ?? []
    const next = cur.includes(key) ? cur.filter(p => p !== key) : [...cur, key]
    set('placements', { ...value.placements, publisher_platforms: next })
  }

  return (
    <div className="space-y-4">
      {showName && (
        <Input
          label="Audience name *"
          placeholder="e.g. Kochi gym — women 22-40"
          value={value.name}
          onChange={e => set('name', e.target.value)}
        />
      )}
      <Input
        label="Notes (optional)"
        placeholder="What this audience is for"
        value={value.description ?? ''}
        onChange={e => set('description', e.target.value)}
      />

      <div className="grid grid-cols-3 gap-3">
        <Input label="Age min" type="number" min={13} max={65} value={value.age_min}
          onChange={e => set('age_min', +e.target.value)} />
        <Input label="Age max" type="number" min={13} max={65} value={value.age_max}
          onChange={e => set('age_max', +e.target.value)} />
        <div>
          <label className="label">Gender</label>
          <select className="select" value={value.genders} onChange={e => set('genders', e.target.value as AudienceSetDraft['genders'])}>
            <option value="all">All</option>
            <option value="male">Men</option>
            <option value="female">Women</option>
          </select>
        </div>
      </div>

      <div>
        <label className="label">Countries</label>
        <p className="text-xs text-gray-400 mb-1">2-letter codes, comma separated (e.g. IN, AE)</p>
        <Input
          value={(value.geo_locations.countries ?? []).join(', ')}
          onChange={e => set('geo_locations', {
            ...value.geo_locations,
            countries: e.target.value.split(',').map(s => s.trim().toUpperCase()).filter(Boolean),
          })}
        />
      </div>

      <ChipSearch label="Cities" hint="Optional — narrows delivery to specific cities"
        accountId={accountId} type="adgeolocation"
        value={(value.geo_locations.cities ?? []).map(c => ({ id: c.key, name: c.name }))}
        onChange={v => set('geo_locations', { ...value.geo_locations, cities: v.map(x => ({ key: x.id, name: x.name })) })} />

      <ChipSearch label="Interests" hint="People whose activity signals these interests"
        accountId={accountId} type="adinterest" value={value.interests} onChange={v => set('interests', v)} />

      <ChipSearch label="Behaviours" hint="Purchase behaviour, device, travel, etc."
        accountId={accountId} type="adTargetingCategory" value={value.behaviors} onChange={v => set('behaviors', v)} />

      <ChipSearch label="Exclude interests" hint="People to keep out of this audience"
        accountId={accountId} type="adinterest"
        value={value.exclusions.interests ?? []}
        onChange={v => set('exclusions', { ...value.exclusions, interests: v })} />

      <div>
        <label className="label">Placements</label>
        <label className="flex items-center gap-2 text-sm mb-2">
          <input type="checkbox" checked={value.placements.automatic}
            onChange={e => set('placements', { automatic: e.target.checked, publisher_platforms: e.target.checked ? undefined : (value.placements.publisher_platforms ?? ['facebook', 'instagram']) })} />
          Automatic placements (recommended)
        </label>
        {!value.placements.automatic && (
          <div className="flex flex-wrap gap-2">
            {PLATFORMS.map(p => {
              const on = (value.placements.publisher_platforms ?? []).includes(p.key)
              return (
                <button key={p.key} type="button" onClick={() => togglePlatform(p.key)}
                  className={`text-xs rounded-full px-3 py-1 border ${on ? 'bg-brand-600 text-white border-brand-600' : 'border-gray-300 text-gray-600'}`}>
                  {p.label}
                </button>
              )
            })}
          </div>
        )}
      </div>

      <div className="flex items-center gap-3 pt-1 border-t border-gray-100">
        <Button variant="secondary" size="sm" onClick={estimate} loading={estimating}>Estimate reach</Button>
        {reach && (
          <span className="text-sm text-gray-600">
            {reach.ready
              ? <>≈ <b>{fmt.number(reach.min)}</b>–<b>{fmt.number(reach.max)}</b> people</>
              : 'Estimate not ready yet — try again shortly'}
          </span>
        )}
      </div>
    </div>
  )
}
