// The campaigns of ONE Meta ad account, each with its own on/off switch — loaded on demand, searchable and paged, because an ad
// account can have hundreds of them. A campaign that is off holds its new leads in Held leads until staff move them.
import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { leadChannelApi } from '@/api'
import { Spinner } from '@/components/ui'
import { getError } from '@/utils'

export type SourceCampaign = { id: number; label: string; sub: string | null; enabled: boolean; held: number }
type State = 'all' | 'off' | 'held'
const PER = 20

export default function MetaCampaignSwitches({ source, accountId, accountOn, available, offCount, heldCount, onChanged }: {
  source: string; accountId: number; accountOn: boolean; available: boolean; offCount: number; heldCount: number
  onChanged: (rows: unknown) => void       // the refreshed source list, so the counts above stay right
}) {
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  const [state, setState] = useState<State>('all')
  const [items, setItems] = useState<SourceCampaign[]>([])
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [more, setMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState<number | null>(null)
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [bulkBusy, setBulkBusy] = useState(false)
  const gen = useRef(0)

  useEffect(() => { const t = setTimeout(() => setDebounced(q.trim()), 300); return () => clearTimeout(t) }, [q])

  const load = useCallback(async (pg: number, append: boolean) => {
    const g = ++gen.current                                  // a slow older answer must never overwrite a newer search
    setLoading(true)
    try {
      const r = await leadChannelApi.campaigns({ account_id: accountId, search: debounced || undefined, state, page: pg, per_page: PER })
      if (g !== gen.current) return
      setItems(prev => append ? [...prev, ...r.data.data.filter((c: SourceCampaign) => !prev.some(p => p.id === c.id))] : r.data.data)
      setTotal(r.data.total); setMore(!!r.data.has_more); setPage(pg)
    } catch (e) { if (g === gen.current) toast.error(getError(e)) } finally { if (g === gen.current) setLoading(false) }
  }, [accountId, debounced, state])

  useEffect(() => { setPicked(new Set()); void load(1, false) }, [load])   // a new search / filter starts a new selection

  const toggle = async (c: SourceCampaign) => {
    setBusy(c.id)
    try {
      const res = await leadChannelApi.setCampaign(c.id, !c.enabled)
      const next: SourceCampaign = res.data.campaign
      // in the "off only" view a campaign that was just switched on drops out of the list
      setItems(prev => state === 'off' && next.enabled ? prev.filter(p => p.id !== c.id) : prev.map(p => p.id === c.id ? next : p))
      if (state === 'off' && next.enabled) setTotal(t => Math.max(0, t - 1))
      onChanged(res.data.data); toast.success(res.data.message)
    } catch (e) { toast.error(getError(e)) } finally { setBusy(null) }
  }

  const pageIds = items.map(c => c.id)
  const allOnPage = pageIds.length > 0 && pageIds.every(id => picked.has(id))
  const scope = [debounced && `matching “${debounced}”`, state === 'off' ? 'that are off' : state === 'held' ? 'with held leads' : ''].filter(Boolean).join(' ')

  /** Switch the ticked campaigns, or everything the current search / filter shows — after the user has seen the exact count. */
  const bulk = async (enabled: boolean, allMatching: boolean) => {
    const n = allMatching ? total : picked.size
    if (n === 0) return
    const what = allMatching ? `ALL ${n} campaign${n === 1 ? '' : 's'}${scope ? ' ' + scope : ' of this ad account'}` : `${n} selected campaign${n === 1 ? '' : 's'}`
    if (!window.confirm(`Switch ${what} ${enabled ? 'ON' : 'OFF'}?\n\n${enabled ? 'Their new leads will be created as they arrive.' : 'Their new leads will wait in Held leads until staff move them.'}`)) return
    setBulkBusy(true)
    try {
      const res = await leadChannelApi.bulkCampaigns({ account_id: accountId, enabled, ...(allMatching ? { all_matching: true, search: debounced || undefined, state } : { ids: [...picked] }) })
      toast.success(res.data.message); onChanged(res.data.data)
      setPicked(new Set()); await load(1, false)
    } catch (e) { toast.error(getError(e)) } finally { setBulkBusy(false) }
  }

  const chip = (s: State, label: string, n?: number) => (
    <button key={s} type="button" onClick={() => setState(s)}
      className={`rounded-full px-2.5 py-0.5 text-[11px] font-medium ${state === s ? 'bg-brand-600 text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50'}`}>
      {label}{n ? ` (${n})` : ''}
    </button>
  )

  return (
    <div className="mx-3 mb-2 rounded-lg border border-gray-100 bg-gray-50 p-2">
      <div className="flex flex-wrap items-center gap-2">
        <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search campaigns by name or Meta id…"
          className="min-w-[180px] flex-1 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs outline-none focus:border-brand-400" />
        <div className="flex gap-1">
          {chip('all', 'All')}{chip('off', 'Off only', offCount)}{chip('held', 'Has held leads', heldCount)}
        </div>
      </div>

      {items.length > 0 && (
        <div className="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-white px-2 py-1 ring-1 ring-gray-100">
          <label className="flex items-center gap-1.5 text-[11px] text-gray-500">
            <input type="checkbox" checked={allOnPage} onChange={() => setPicked(allOnPage ? new Set() : new Set(pageIds))} disabled={!available} /> Select this page
          </label>
          {picked.size > 0 ? (
            <>
              <span className="text-[11px] font-medium text-gray-700">{picked.size} selected</span>
              <button type="button" disabled={bulkBusy} onClick={() => void bulk(false, false)} className="rounded bg-gray-700 px-2 py-0.5 text-[11px] font-medium text-white disabled:opacity-50">Switch off</button>
              <button type="button" disabled={bulkBusy} onClick={() => void bulk(true, false)} className="rounded bg-green-600 px-2 py-0.5 text-[11px] font-medium text-white disabled:opacity-50">Switch on</button>
              <button type="button" onClick={() => setPicked(new Set())} className="text-[11px] text-gray-400 hover:underline">Clear</button>
            </>
          ) : (
            <span className="ml-auto flex items-center gap-2 text-[11px] text-gray-400">
              All {total}{scope ? ' ' + scope : ''}:
              <button type="button" disabled={!available || bulkBusy} onClick={() => void bulk(false, true)} className="font-medium text-gray-700 hover:underline disabled:opacity-40">switch off</button>
              <button type="button" disabled={!available || bulkBusy} onClick={() => void bulk(true, true)} className="font-medium text-green-700 hover:underline disabled:opacity-40">switch on</button>
            </span>
          )}
        </div>
      )}

      <div className="mt-2">
        {loading && items.length === 0 ? <div className="flex justify-center py-3"><Spinner /></div>
          : items.length === 0 ? <p className="px-1 py-3 text-center text-xs text-gray-400">{debounced || state !== 'all' ? 'No campaigns match.' : 'No campaigns yet.'}</p>
          : items.map(c => (
            <div key={c.id} className={`flex flex-wrap items-center gap-3 px-1 py-1.5 ${picked.has(c.id) ? 'rounded bg-brand-50/50' : ''}`}>
              <input type="checkbox" aria-label={`Select ${c.label}`} checked={picked.has(c.id)} disabled={!available}
                onChange={() => setPicked(p => { const n = new Set(p); n.has(c.id) ? n.delete(c.id) : n.add(c.id); return n })} />
              <div className="min-w-0 flex-1">
                <p className="truncate text-xs text-gray-800">{c.label}</p>
                <p className="text-[11px] text-gray-400">{c.sub ?? ''}{!accountOn && ' · the whole account is off'}{accountOn && !c.enabled && <span className="text-amber-600"> · OFF — new contacts are held</span>}</p>
              </div>
              {c.held > 0 && <Link to={`/crm/leads/held?source=${source}&account=ad_campaign:${c.id}`} className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 hover:bg-amber-200">{c.held} held</Link>}
              <button type="button" role="switch" aria-checked={c.enabled} disabled={!available || busy === c.id} onClick={() => void toggle(c)}
                title={c.enabled ? 'Turn off — hold new leads from this campaign' : 'Turn on — create leads from this campaign'}
                className={`relative h-4 w-8 shrink-0 rounded-full transition-colors disabled:opacity-40 ${c.enabled ? 'bg-green-500' : 'bg-gray-300'}`}>
                <span className={`absolute top-0.5 h-3 w-3 rounded-full bg-white shadow transition-all ${c.enabled ? 'left-[18px]' : 'left-0.5'}`} />
              </button>
            </div>
          ))}
      </div>

      <div className="flex items-center justify-between px-1 pt-1 text-[11px] text-gray-400">
        <span>{total} campaign{total === 1 ? '' : 's'}{debounced || state !== 'all' ? ' match' : ''}</span>
        {more && <button type="button" disabled={loading} onClick={() => void load(page + 1, true)} className="font-medium text-brand-600 hover:underline disabled:opacity-50">{loading ? 'Loading…' : 'Load more'}</button>}
      </div>
    </div>
  )
}
