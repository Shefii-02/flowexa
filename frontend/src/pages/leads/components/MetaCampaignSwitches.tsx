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

  useEffect(() => { void load(1, false) }, [load])

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

      <div className="mt-2">
        {loading && items.length === 0 ? <div className="flex justify-center py-3"><Spinner /></div>
          : items.length === 0 ? <p className="px-1 py-3 text-center text-xs text-gray-400">{debounced || state !== 'all' ? 'No campaigns match.' : 'No campaigns yet.'}</p>
          : items.map(c => (
            <div key={c.id} className="flex flex-wrap items-center gap-3 px-1 py-1.5">
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
