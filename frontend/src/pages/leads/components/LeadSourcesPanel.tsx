// Assignment Rules → Lead sources: switch each incoming lead channel on or off. A channel that is OFF does not create leads —
// what arrives is kept in Held leads until staff move it into Leads. Only channels the plan includes can be switched.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { leadChannelApi } from '@/api'
import { Spinner } from '@/components/ui'
import { getError } from '@/utils'
import MetaCampaignSwitches from './MetaCampaignSwitches'

export type SourceAccount = { type: string; id: number; label: string; sub: string | null; enabled: boolean; held: number; campaign_total?: number; campaign_off?: number; campaign_held?: number }
export type SourceRow = {
  source: string; label: string; icon: string; feature: string; available: boolean
  via: 'plan' | 'complimentary' | 'addon' | null; complimentary_until: string | null; enabled: boolean; held: number
  accounts?: SourceAccount[]
}

export default function LeadSourcesPanel() {
  const [rows, setRows] = useState<SourceRow[] | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [open, setOpen] = useState<Record<string, boolean>>({})
  const load = useCallback(() => { leadChannelApi.list().then(r => setRows(r.data.data ?? [])).catch(e => toast.error(getError(e))) }, [])
  useEffect(() => { load() }, [load])

  const toggle = async (r: SourceRow) => {
    setBusy(r.source)
    try {
      const res = await leadChannelApi.set(r.source, !r.enabled)
      setRows(res.data.data); toast.success(res.data.message)
    } catch (e) { toast.error(getError(e)) } finally { setBusy(null) }
  }

  const toggleAccount = async (r: SourceRow, a: SourceAccount) => {
    const key = `${r.source}:${a.id}`
    setBusy(key)
    try {
      const res = await leadChannelApi.setAccount(r.source, a.id, !a.enabled)
      setRows(res.data.data); toast.success(res.data.message)
    } catch (e) { toast.error(getError(e)) } finally { setBusy(null) }
  }

  if (!rows) return <div className="flex justify-center py-10"><Spinner /></div>
  const totalHeld = rows.reduce((n, r) => n + r.held, 0)

  return (
    <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
      <div>
        <h2 className="font-semibold text-gray-800">Lead sources</h2>
        <p className="text-sm text-gray-500">Choose which incoming channels — and which individual numbers, sessions or accounts inside them — turn new contacts into leads. When a source is <b>off</b>, people who write in are kept in <Link to="/crm/leads/held" className="text-brand-600 hover:underline">Held leads</Link> and nothing is created or assigned until staff move them into Leads — one by one or in bulk.</p>
      </div>
      {totalHeld > 0 && (
        <Link to="/crm/leads/held" className="block rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 hover:bg-amber-100">
          ⏸ {totalHeld} contact{totalHeld > 1 ? 's are' : ' is'} waiting in Held leads — review and move them →
        </Link>
      )}
      <div className="divide-y divide-gray-100 rounded-lg border border-gray-200">
        {rows.map(r => (
          <div key={r.source} className={r.available ? '' : 'bg-gray-50'}>
          <div className="flex flex-wrap items-center gap-3 px-4 py-3">
            <span className="text-xl w-7 text-center">{r.icon}</span>
            <div className="min-w-0 flex-1">
              <p className={`text-sm font-medium ${r.available ? 'text-gray-900' : 'text-gray-400'}`}>{r.label}</p>
              <p className="text-xs text-gray-400">
                {!r.available ? <>🔒 Needs <b>{r.feature}</b> — not in your plan{r.complimentary_until ? '' : ' (or its free period has ended)'}.</>
                  : r.via === 'complimentary' ? <>🎁 Free <b>{r.feature}</b> period — valid until {new Date(r.complimentary_until!).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}</>
                  : r.via === 'addon' ? <>➕ Included through an add-on</>
                  : <>✓ Included in your plan</>}
                {r.available && !r.enabled && <span className="ml-2 text-amber-600">· OFF — new contacts are held</span>}
              </p>
            </div>
            {r.held > 0 && <Link to={`/crm/leads/held?source=${r.source}`} className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 hover:bg-amber-200">{r.held} held</Link>}
            <button type="button" role="switch" aria-checked={r.enabled} disabled={!r.available || busy === r.source} onClick={() => toggle(r)}
              title={!r.available ? 'Not available on your plan' : r.enabled ? 'Turn off — hold new leads' : 'Turn on — create leads'}
              className={`relative h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 ${r.enabled && r.available ? 'bg-green-500' : 'bg-gray-300'}`}>
              <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${r.enabled && r.available ? 'left-[22px]' : 'left-0.5'}`} />
            </button>
          </div>
          {(r.accounts?.length ?? 0) > 0 && (
            <div className="px-4 pb-3">
              <button type="button" onClick={() => setOpen(o => ({ ...o, [r.source]: !o[r.source] }))} className="text-xs text-brand-600 hover:underline">
                {open[r.source] ? '▾' : '▸'} {r.accounts!.length} account{r.accounts!.length > 1 ? 's' : ''}
                {r.accounts!.some(a => !a.enabled) && <span className="ml-2 text-amber-600">· {r.accounts!.filter(a => !a.enabled).length} off</span>}
              </button>
              {open[r.source] && (
                <div className="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
                  {r.accounts!.map(a => {
                    const live = r.available && r.enabled
                    return (
                      <div key={a.id}>
                      <div className="flex flex-wrap items-center gap-3 px-3 py-2">
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm text-gray-800">{a.label}</p>
                          <p className="text-[11px] text-gray-400">
                            {a.sub ?? ''}{!r.enabled && ' · the whole source is off'}{r.enabled && !a.enabled && <span className="text-amber-600"> · OFF — new contacts are held</span>}
                          </p>
                        </div>
                        {a.held > 0 && <Link to={`/crm/leads/held?source=${r.source}&account=${a.type}:${a.id}`} className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 hover:bg-amber-200">{a.held} held</Link>}
                        <button type="button" role="switch" aria-checked={a.enabled} disabled={!r.available || busy === `${r.source}:${a.id}`} onClick={() => toggleAccount(r, a)}
                          title={!r.available ? 'Not available on your plan' : a.enabled ? 'Turn off — hold new leads from this account' : 'Turn on — create leads from this account'}
                          className={`relative h-5 w-9 shrink-0 rounded-full transition-colors disabled:opacity-40 ${a.enabled && live ? 'bg-green-500' : a.enabled ? 'bg-green-300' : 'bg-gray-300'}`}>
                          <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all ${a.enabled ? 'left-[18px]' : 'left-0.5'}`} />
                        </button>
                      </div>
                      {/* Meta Ads: the campaigns of this ad account — searchable and paged, loaded when the account is opened */}
                      {(a.campaign_total ?? 0) > 0 && (
                        <div>
                          <button type="button" onClick={() => setOpen(o => ({ ...o, [`${r.source}:${a.id}`]: !o[`${r.source}:${a.id}`] }))} className="mx-3 mb-1 text-[11px] text-brand-600 hover:underline">
                            {open[`${r.source}:${a.id}`] ? '▾' : '▸'} {a.campaign_total} campaign{a.campaign_total === 1 ? '' : 's'}
                            {(a.campaign_off ?? 0) > 0 && <span className="ml-1 text-amber-600">· {a.campaign_off} off</span>}
                            {(a.campaign_held ?? 0) > 0 && <span className="ml-1 text-amber-600">· {a.campaign_held} held</span>}
                          </button>
                          {open[`${r.source}:${a.id}`] && (
                            <MetaCampaignSwitches source={r.source} accountId={a.id} accountOn={a.enabled && r.enabled} available={r.available}
                              offCount={a.campaign_off ?? 0} heldCount={a.campaign_held ?? 0} onChanged={rows => setRows(rows as SourceRow[])} />
                          )}
                        </div>
                      )}
                      </div>
                    )
                  })}
                </div>
              )}
            </div>
          )}
          </div>
        ))}
      </div>
      <p className="text-xs text-gray-400">Manual leads, CSV imports, referrals and walk-ins are always created. A contact who already has an open lead is never held — their message just continues that lead. Switching a source back on does not release what is already held.</p>
    </section>
  )
}
