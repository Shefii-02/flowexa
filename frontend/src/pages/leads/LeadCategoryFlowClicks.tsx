// "Flow clicks" for one lead category: customers who reached the flow nodes tagged with it — their details, whether
// they became a lead (created by the click / already one / not yet), who handles the lead, filters by date, status,
// node and handler, convert-to-lead, and bulk assign to staff. Also exports the assign dialog for the whole category.
import { useCallback, useEffect, useMemo, useState } from 'react'
import toast from 'react-hot-toast'
import { leadCategoryApi } from '@/api'
import { getError } from '@/utils'

type Cat = { id: number; name: string; color: string }
type Staff = { id: number; name: string }

const when = (iso?: string | null) =>
  iso ? new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—'
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const daysAgo = (n: number) => { const d = new Date(); d.setDate(d.getDate() - n); return ymd(d) }

type Scope = 'selected' | 'filtered' | 'category'

/** Choose staff and hand leads over. One person gets them all; several share them round-robin. */
export function AssignLeadsModal({ cat, staff, selectedHitIds = [], selectedLeadIds = [], filters = {}, filteredCount = 0, onClose, onDone }: {
  cat: Cat; staff: Staff[]; selectedHitIds?: number[]; selectedLeadIds?: number[]; filters?: Record<string, unknown>; filteredCount?: number
  onClose: () => void; onDone: () => void
}) {
  const [picked, setPicked] = useState<number[]>([])
  const nSelected = selectedLeadIds.length || selectedHitIds.length
  const [scope, setScope] = useState<Scope>(nSelected ? 'selected' : filteredCount ? 'filtered' : 'category')
  const [onlyUnassigned, setOnlyUnassigned] = useState(false)
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    if (!picked.length) return toast.error('Pick at least one staff member.')
    setBusy(true)
    try {
      const body: Record<string, unknown> = { user_ids: picked, only_unassigned: onlyUnassigned }
      if (scope === 'selected') { if (selectedLeadIds.length) body.lead_ids = selectedLeadIds; else body.hit_ids = selectedHitIds }
      else if (scope === 'filtered') Object.assign(body, filters, { scope: 'filtered' })
      else body.scope = 'category'
      const { data } = await leadCategoryApi.assignLeads(cat.id, body)
      toast.success(data.message); onDone(); onClose()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  return (
    <div className="fixed inset-0 z-[90] flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div className="flex max-h-[88vh] w-full max-w-md flex-col rounded-2xl bg-white shadow-2xl" onClick={e => e.stopPropagation()}>
        <div className="border-b border-gray-100 px-5 py-4">
          <h3 className="font-semibold text-gray-900">Assign leads to staff</h3>
          <p className="mt-0.5 text-xs text-gray-400">Category “{cat.name}”. Closed leads (enrolled / lost) are left alone.</p>
        </div>
        <div className="space-y-4 overflow-auto px-5 py-4">
          <div>
            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Which leads</p>
            {([
              ['selected', `The ${nSelected} ${selectedLeadIds.length ? 'lead' : 'customer'}(s) I ticked`, nSelected > 0],
              ['filtered', `Everyone in the current list (${filteredCount} click${filteredCount === 1 ? '' : 's'}, with the filters applied)`, filteredCount > 0],
              ['category', `Every open lead in “${cat.name}”`, true],
            ] as const).map(([v, label, ok]) => (
              <label key={v} className={`mb-1 flex items-start gap-2 rounded-lg border p-2 text-sm ${!ok ? 'cursor-not-allowed opacity-40' : 'cursor-pointer'} ${scope === v ? 'border-brand-400 bg-brand-50' : 'border-gray-200'}`}>
                <input type="radio" className="mt-1" disabled={!ok} checked={scope === v} onChange={() => setScope(v)} /> <span>{label}</span>
              </label>
            ))}
            <label className="mt-2 flex cursor-pointer items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" checked={onlyUnassigned} onChange={e => setOnlyUnassigned(e.target.checked)} /> Only leads nobody is handling yet
            </label>
          </div>
          <div>
            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Assign to</p>
            <div className="max-h-52 overflow-auto rounded-lg border border-gray-200">
              {staff.length === 0 ? <p className="p-3 text-sm text-gray-400">No staff found.</p> : staff.map(u => (
                <label key={u.id} className="flex cursor-pointer items-center gap-2 border-b border-gray-50 px-3 py-2 text-sm last:border-0 hover:bg-gray-50">
                  <input type="checkbox" checked={picked.includes(u.id)} onChange={() => setPicked(p => p.includes(u.id) ? p.filter(x => x !== u.id) : [...p, u.id])} /> {u.name}
                </label>
              ))}
            </div>
            <p className="mt-1.5 text-[11px] text-gray-400">{picked.length > 1 ? `${picked.length} people selected: leads are shared between them in turn.` : 'Pick one person to give them all, or several to share the leads.'}</p>
          </div>
        </div>
        <div className="flex items-center justify-end gap-2 border-t border-gray-100 px-5 py-3">
          <button onClick={onClose} className="rounded-lg border border-gray-200 px-4 py-2 text-sm text-gray-600">Cancel</button>
          <button onClick={submit} disabled={busy || !picked.length} className="rounded-lg bg-brand-500 px-4 py-2 text-sm text-white disabled:opacity-50">{busy ? 'Assigning…' : 'Assign'}</button>
        </div>
      </div>
    </div>
  )
}

/** Assign dialog opened from the detail header / Contacts tab: loads the staff list, then offers the ticked leads (if any) or the whole category. */
export function AssignWholeCategory({ cat, leadIds = [], onClose, onDone }: { cat: Cat; leadIds?: number[]; onClose: () => void; onDone: () => void }) {
  const [staff, setStaff] = useState<Staff[] | null>(null)
  useEffect(() => { leadCategoryApi.flowHits(cat.id, { per_page: 5 }).then(r => setStaff(r.data.staff ?? [])).catch(e => { toast.error(getError(e)); onClose() }) }, [cat.id]) // eslint-disable-line react-hooks/exhaustive-deps
  if (!staff) return null
  return <AssignLeadsModal cat={cat} staff={staff} selectedLeadIds={leadIds} onClose={onClose} onDone={onDone} />
}

export default function LeadCategoryFlowClicks({ cat, onOpenContact, onChanged }: { cat: Cat; onOpenContact: (c: any) => void; onChanged: () => void }) {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [status, setStatus] = useState<'all' | 'created' | 'not_created'>('all')
  const [nodeId, setNodeId] = useState('')
  const [handler, setHandler] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [picked, setPicked] = useState<number[]>([])
  const [assigning, setAssigning] = useState(false)
  const [busy, setBusy] = useState(false)

  useEffect(() => { const t = setTimeout(() => { setQuery(search.trim()); setPage(1) }, 300); return () => clearTimeout(t) }, [search])

  const filters = useMemo(() => ({
    from: from || undefined, to: to || undefined, status, node_id: nodeId || undefined, handler: handler || undefined, search: query || undefined,
  }), [from, to, status, nodeId, handler, query])

  const load = useCallback(() => {
    setLoading(true)
    leadCategoryApi.flowHits(cat.id, { ...filters, page, per_page: 25 })
      .then(r => setData(r.data)).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [cat.id, filters, page])
  useEffect(() => { load() }, [load])
  useEffect(() => { setPage(1); setPicked([]) }, [filters])

  const preset = (days: number | null) => { if (days === null) { setFrom(''); setTo('') } else { setFrom(days === 0 ? ymd(new Date()) : daysAgo(days)); setTo(ymd(new Date())) } }

  const hits: any[] = data?.hits ?? []
  const sum = data?.summary ?? { clicks: 0, customers: 0, lead_created: 0, not_created: 0 }
  const lastPage = data?.last_page ?? 1
  const pickedNotLead = hits.filter(h => picked.includes(h.id) && !h.lead_created).map(h => h.id)
  const allOnPage = hits.length > 0 && hits.every(h => picked.includes(h.id))

  const convert = async (body: Record<string, unknown>) => {
    setBusy(true)
    try { const { data: r } = await leadCategoryApi.convertFlowHits(cat.id, body); toast.success(r.message); setPicked([]); load(); onChanged() }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  const tile = (label: string, value: number, s: 'all' | 'created' | 'not_created' | null, cls: string) => (
    <button type="button" onClick={() => s && setStatus(s)} className={`rounded-xl border px-4 py-2.5 text-left ${s && status === s ? 'border-brand-400 bg-brand-50' : 'border-gray-200 bg-white'} ${s ? 'hover:border-brand-300' : 'cursor-default'}`}>
      <div className={`text-xl font-semibold ${cls}`}>{value}</div><div className="text-[11px] text-gray-500">{label}</div>
    </button>
  )

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      {/* filters */}
      <div className="space-y-3 border-b border-gray-100 px-6 py-3">
        <div className="flex flex-wrap items-end gap-3">
          <div><label className="mb-0.5 block text-[11px] text-gray-400">From</label><input type="date" className="rounded-lg border border-gray-200 px-2 py-1.5 text-sm" value={from} max={to || undefined} onChange={e => setFrom(e.target.value)} /></div>
          <div><label className="mb-0.5 block text-[11px] text-gray-400">To</label><input type="date" className="rounded-lg border border-gray-200 px-2 py-1.5 text-sm" value={to} min={from || undefined} onChange={e => setTo(e.target.value)} /></div>
          <div className="flex gap-1">
            {([['Today', 0], ['7 days', 7], ['30 days', 30], ['All time', null]] as const).map(([l, d]) => (
              <button key={l} type="button" onClick={() => preset(d)} className="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{l}</button>
            ))}
          </div>
          <div><label className="mb-0.5 block text-[11px] text-gray-400">Status</label>
            <select className="rounded-lg border border-gray-200 px-2 py-1.5 text-sm" value={status} onChange={e => setStatus(e.target.value as any)}>
              <option value="all">All</option><option value="created">Lead created</option><option value="not_created">Lead not created</option>
            </select></div>
          <div><label className="mb-0.5 block text-[11px] text-gray-400">Node</label>
            <select className="max-w-[170px] rounded-lg border border-gray-200 px-2 py-1.5 text-sm" value={nodeId} onChange={e => setNodeId(e.target.value)}>
              <option value="">All nodes</option>{(data?.by_node ?? []).map((n: any) => <option key={n.flow_node_id} value={n.flow_node_id}>{n.title}</option>)}
            </select></div>
          <div><label className="mb-0.5 block text-[11px] text-gray-400">Handled by</label>
            <select className="rounded-lg border border-gray-200 px-2 py-1.5 text-sm" value={handler} onChange={e => setHandler(e.target.value)}>
              <option value="">Anyone</option><option value="none">Unassigned</option>{(data?.handlers ?? []).map((u: Staff) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select></div>
          <input className="min-w-[160px] flex-1 rounded-lg border border-gray-200 px-3 py-1.5 text-sm" placeholder="Search name, number or email" value={search} onChange={e => setSearch(e.target.value)} />
        </div>
        <div className="flex flex-wrap gap-2">
          {tile('Clicks', sum.clicks, 'all', 'text-gray-900')}
          {tile('Customers', sum.customers, null, 'text-gray-900')}
          {tile('Lead created', sum.lead_created, 'created', 'text-green-600')}
          {tile('Lead not created', sum.not_created, 'not_created', 'text-amber-600')}
        </div>
      </div>

      {/* by node */}
      {(data?.by_node?.length ?? 0) > 0 && (
        <div className="flex gap-2 overflow-x-auto border-b border-gray-100 px-6 py-2">
          {data.by_node.map((n: any) => (
            <button key={n.flow_node_id} type="button" onClick={() => setNodeId(String(nodeId) === String(n.flow_node_id) ? '' : String(n.flow_node_id))}
              className={`flex-shrink-0 rounded-lg border px-3 py-1.5 text-left text-xs ${String(nodeId) === String(n.flow_node_id) ? 'border-brand-400 bg-brand-50' : 'border-gray-200 bg-white hover:bg-gray-50'}`}>
              <div className="font-medium text-gray-800">{n.title || `Node ${n.flow_node_id}`}</div>
              <div className="text-gray-400">{n.clicks} click{n.clicks == 1 ? '' : 's'} · {n.customers} customer{n.customers == 1 ? '' : 's'}</div>
            </button>
          ))}
        </div>
      )}

      {/* actions */}
      <div className="flex flex-wrap items-center gap-2 border-b border-gray-100 px-6 py-2 text-sm">
        <span className="text-xs text-gray-400">{picked.length} selected</span>
        <button disabled={!picked.length} onClick={() => setAssigning(true)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 disabled:opacity-40">Assign selected to…</button>
        <button disabled={!pickedNotLead.length || busy} onClick={() => convert({ ids: pickedNotLead })} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 disabled:opacity-40">Convert selected to leads{pickedNotLead.length ? ` (${pickedNotLead.length})` : ''}</button>
        <span className="ml-auto flex gap-2">
          <button disabled={busy || sum.not_created === 0} onClick={() => { if (window.confirm('Turn every customer in this list who is not a lead yet into a lead?')) convert({ all_not_created: true, ...filters }) }} className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs text-amber-800 disabled:opacity-40">Convert all not created</button>
          <button onClick={() => setAssigning(true)} className="rounded-lg bg-brand-500 px-3 py-1.5 text-xs text-white">Assign leads…</button>
        </span>
      </div>

      {/* table */}
      <div className="flex-1 overflow-auto">
        {loading && !data ? <p className="p-6 text-sm text-gray-400">Loading…</p> : hits.length === 0 ? (
          <div className="p-10 text-center text-sm text-gray-400">
            {sum.clicks === 0 && !filters.from && !filters.to && status === 'all' && !query ? 'No one has reached a node tagged with this category yet. Clicks are recorded from now on.' : 'No clicks match these filters.'}
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead><tr className="border-b border-gray-100 bg-gray-50/60 text-left text-xs text-gray-400">
              <th className="w-10 px-6 py-2.5"><input type="checkbox" checked={allOnPage} onChange={() => setPicked(allOnPage ? [] : hits.map(h => h.id))} /></th>
              <th className="font-medium">Customer</th><th className="font-medium">Node clicked</th><th className="font-medium">When</th>
              <th className="font-medium">Lead</th><th className="font-medium">Handled by</th><th className="px-4" />
            </tr></thead>
            <tbody className="divide-y divide-gray-50">
              {hits.map(h => (
                <tr key={h.id} className="hover:bg-gray-50/60">
                  <td className="px-6 py-3"><input type="checkbox" checked={picked.includes(h.id)} onChange={() => setPicked(p => p.includes(h.id) ? p.filter(x => x !== h.id) : [...p, h.id])} /></td>
                  <td className="cursor-pointer" onClick={() => onOpenContact({ id: h.contact_id, name: h.name, phone: h.phone })}>
                    <div className="font-medium text-gray-900">{h.name || '—'}</div>
                    <div className="font-mono text-xs text-gray-400">{h.phone}</div>
                    {h.email && <div className="text-xs text-gray-400">{h.email}</div>}
                  </td>
                  <td className="text-gray-700">{h.node_title || '—'}</td>
                  <td className="whitespace-nowrap text-xs text-gray-500">{when(h.clicked_at)}</td>
                  <td>
                    {h.lead_created
                      ? <><span className="rounded-full bg-green-100 px-2 py-0.5 text-[11px] text-green-700">Lead created</span>
                        <div className="mt-0.5 text-[11px] text-gray-400">{h.created_by_click ? 'created by this click' : 'already a lead'}{h.lead_stage ? ` · ${String(h.lead_stage).replace('_', ' ')}` : ''}</div></>
                      : <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-700">Not created</span>}
                  </td>
                  <td className="text-xs">{!h.lead_created ? <span className="text-gray-300">—</span> : h.handled_by ? <span className="text-gray-700">{h.handled_by}</span> : <span className="text-amber-600">Unassigned</span>}</td>
                  <td className="px-4 text-right">
                    {!h.lead_created && <button disabled={busy} onClick={() => convert({ ids: [h.id] })} className="text-xs text-brand-600 hover:underline disabled:opacity-40">Convert to lead</button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {lastPage > 1 && (
        <div className="flex items-center gap-3 border-t border-gray-100 px-6 py-3 text-sm">
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="rounded-lg border border-gray-200 px-3 py-1 disabled:opacity-40">‹ Prev</button>
          <span className="text-gray-500">Page {page} of {lastPage}</span>
          <button disabled={page >= lastPage} onClick={() => setPage(p => p + 1)} className="rounded-lg border border-gray-200 px-3 py-1 disabled:opacity-40">Next ›</button>
        </div>
      )}

      {assigning && <AssignLeadsModal cat={cat} staff={data?.staff ?? []} selectedHitIds={picked} filters={filters} filteredCount={data?.total ?? 0}
        onClose={() => setAssigning(false)} onDone={() => { setPicked([]); load(); onChanged() }} />}
    </div>
  )
}
