// Held leads — contacts captured while their source was switched off. Staff move them into Leads (one or many) or discard them.
import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import { heldLeadApi, leadChannelApi, staffApi } from '@/api'
import { usePermission } from '@/store'
import { Badge, Button, EmptyState, Pagination, Spinner, StatCard } from '@/components/ui'
import { fmt, getError, phoneLabel } from '@/utils'
import type { SourceRow } from './components/LeadSourcesPanel'

type Row = {
  id: number; source: string; status: 'held' | 'moved' | 'discarded'; hits: number
  contact: { id: number; name: string | null; phone: string; phone_is_real: boolean }
  origin_label: string | null; category: string | null; notes: string | null; held_reason?: 'source_off' | 'account_off' | 'campaign_off' | 'needs_review'; topic?: string | null; topic_confidence?: number | null; sample_text?: string | null; account_type?: string | null; account_id?: number | null
  first_captured_at: string | null; last_captured_at: string | null; lead_id: number | null; result_note: string | null; handled_by: string | null; handled_at: string | null
}
const PER = 25
const TOPIC_LABEL: Record<string, string> = { lead_enquiry: 'Lead enquiry', technical: 'Technical / support', billing: 'Billing', complaint: 'Complaint', existing_customer: 'Existing customer', other: 'Other conversation' }

export default function HeldLeadsPage() {
  const allowed = usePermission('leads.manage')
  const [params, setParams] = useSearchParams()
  const [status, setStatus] = useState<'held' | 'moved' | 'discarded'>('held')
  const [source, setSource] = useState(params.get('source') ?? '')
  const [account, setAccount] = useState(params.get('account') ?? '')   // "type:id" — one WA session / number / page / widget / ad account
  const [search, setSearch] = useState('')
  const [reason, setReason] = useState(params.get('reason') ?? '')   // needs_review = waiting for a team head's check
  const [assignTo, setAssignTo] = useState('')
  const [staff, setStaff] = useState<{ id: number; name: string }[]>([])
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<Row[]>([])
  const [total, setTotal] = useState(0)
  const [summary, setSummary] = useState({ held: 0, moved: 0, discarded: 0 })
  const [sources, setSources] = useState<SourceRow[]>([])
  const [loading, setLoading] = useState(true)
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [busy, setBusy] = useState(false)

  useEffect(() => { staffApi.list({ per_page: 200 }).then(r => setStaff((r.data.data ?? r.data.staff ?? []).filter((x: any) => x.is_active !== false).map((x: any) => ({ id: x.id, name: x.name })))).catch(() => {}) }, [])
  useEffect(() => { leadChannelApi.list().then(r => setSources(r.data.data ?? [])).catch(() => {}) }, [])
  const filters = useCallback(() => ({ status, source: source || undefined, account: account || undefined, search: search || undefined, reason: reason || undefined }), [status, source, account, search, reason])
  const load = useCallback(() => {
    setLoading(true)
    heldLeadApi.list({ ...filters(), page, per_page: PER })
      .then(r => { setRows(r.data.data ?? []); setTotal(r.data.total ?? 0); setSummary(r.data.summary) })
      .catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [filters, page])
  useEffect(() => { if (allowed) load() }, [allowed, load])
  useEffect(() => { setPage(1); setPicked(new Set()) }, [status, source, account, search, reason])
  useEffect(() => { const n: Record<string, string> = {}; if (source) n.source = source; if (account) n.account = account; if (reason) n.reason = reason; setParams(n, { replace: true }) }, [source, account, reason]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!allowed) return <div className="p-8"><EmptyState icon="🔒" title="No access" desc="You need permission to manage leads to see held leads." /></div>

  const label = (s: string) => sources.find(x => x.source === s)?.label ?? s
  const icon = (s: string) => sources.find(x => x.source === s)?.icon ?? '•'
  const held = status === 'held'
  const ids = rows.filter(r => r.status === 'held').map(r => r.id)
  const allOnPage = ids.length > 0 && ids.every(i => picked.has(i))
  const toggle = (id: number) => setPicked(s => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })

  const run = async (kind: 'move' | 'discard', body: Record<string, unknown>) => {
    setBusy(true)
    try { const { data } = await (kind === 'move' ? heldLeadApi.move({ ...body, assign_to: assignTo ? Number(assignTo) : undefined }) : heldLeadApi.discard(body)); toast.success(data.message); setPicked(new Set()); load() }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const matching = { all_matching: true, source: source || undefined, account: account || undefined, search: search || undefined, reason: reason || undefined }

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Review &amp; held leads</h1>
        <p className="page-sub">New contacts that are waiting for a check, or were captured while their source was switched off. Look at what they wrote, then move the real ones into Leads with one click — or discard the rest</p>
      </div>
      <div className="grid grid-cols-3 gap-3">
        <StatCard label="Waiting" value={fmt.number(summary.held)} icon="⏸" />
        <StatCard label="Moved to leads" value={fmt.number(summary.moved)} icon="✅" />
        <StatCard label="Discarded" value={fmt.number(summary.discarded)} icon="🗑️" />
      </div>

      <div className="card p-4 flex flex-wrap items-center gap-3">
        <div className="flex border border-gray-200 rounded-lg overflow-hidden text-sm">
          {(['held', 'moved', 'discarded'] as const).map(s => <button key={s} onClick={() => setStatus(s)} className={`px-3 py-1.5 ${status === s ? 'bg-brand-600 text-white' : 'bg-white text-gray-600'}`}>{s === 'held' ? 'Waiting' : s === 'moved' ? 'Moved' : 'Discarded'}</button>)}
        </div>
        {account && <button className="rounded-full bg-brand-50 px-3 py-1 text-xs text-brand-700" onClick={() => setAccount('')}>One account only ✕</button>}
        <select className="select !w-auto text-sm" value={source} onChange={e => { setSource(e.target.value); setAccount('') }}>
          <option value="">All sources</option>
          {sources.map(s => <option key={s.source} value={s.source}>{s.icon} {s.label}</option>)}
        </select>
        <select className="select !w-auto text-sm" value={reason} onChange={e => setReason(e.target.value)}>
          <option value="">Everything waiting</option>
          <option value="needs_review">🛂 Needs a check</option>
          <option value="source_off">⏸ Source switched off</option>
        </select>
        <input className="input !w-56" placeholder="Search name / number" value={search} onChange={e => setSearch(e.target.value)} />
        <Link to="/crm/leads/assignment-rules?section=sources" className="ml-auto text-xs text-brand-600 hover:underline">⚙ Switch sources on / off</Link>
      </div>

      {held && (picked.size > 0 || summary.held > 0) && (
        <div className="flex flex-wrap items-center gap-3 bg-brand-50 border border-brand-200 rounded-xl px-4 py-2.5">
          <span className="text-sm font-medium text-brand-800">{picked.size} selected</span>
          <select className="select !w-auto !py-1 text-sm" value={assignTo} onChange={e => setAssignTo(e.target.value)} title="Who gets the lead (blank = the normal auto-assignment)">
            <option value="">Assign: automatic</option>
            {staff.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          <Button size="sm" onClick={() => run('move', { ids: [...picked] })} loading={busy} disabled={picked.size === 0}>→ Move to Leads</Button>
          <Button size="sm" variant="secondary" disabled={picked.size === 0 || busy} onClick={() => { if (confirm(`Discard ${picked.size} held lead(s)? No lead will be created.`)) run('discard', { ids: [...picked] }) }}>Discard</Button>
          {total > 0 && <Button size="sm" variant="secondary" disabled={busy} onClick={() => { if (confirm(`Move all ${total} waiting contact(s) that match these filters into Leads?`)) run('move', matching) }}>Move all {fmt.number(total)} matching</Button>}
          {picked.size > 0 && <button className="text-xs text-gray-400 hover:underline ml-auto" onClick={() => setPicked(new Set())}>Clear selection</button>}
        </div>
      )}

      <div className="card">
        {loading ? <div className="flex justify-center py-12"><Spinner size="lg" /></div> : rows.length === 0 ? (
          <EmptyState icon="⏸" title={held ? 'Nothing is waiting' : 'Nothing here'} desc={held ? 'When a lead source is switched off, new contacts from it wait here.' : 'No entries match.'} />
        ) : (
          <>
            <div className="table-wrapper"><table className="table">
              <thead><tr>
                {held && <th className="w-8"><input type="checkbox" checked={allOnPage} onChange={() => setPicked(allOnPage ? new Set() : new Set(ids))} /></th>}
                <th>Contact</th><th>Source</th><th>First seen</th><th>Last message</th><th className="text-right">Times</th><th>{held ? 'Notes' : 'Result'}</th><th></th>
              </tr></thead>
              <tbody>{rows.map(r => (
                <tr key={r.id} className={picked.has(r.id) ? 'bg-brand-50/40' : ''}>
                  {held && <td><input type="checkbox" checked={picked.has(r.id)} onChange={() => toggle(r.id)} /></td>}
                  <td><p className="font-medium text-gray-900">{r.contact.name && r.contact.name !== r.contact.phone ? r.contact.name : '—'}</p><p className="text-xs text-gray-400 font-mono">{phoneLabel(r.contact)}</p></td>
                  <td className="text-sm whitespace-nowrap">{icon(r.source)} {label(r.source)}{r.origin_label && <span className="block text-xs text-gray-400">{r.origin_label}</span>}{r.held_reason === 'needs_review' && <span className="block text-[10px] text-indigo-600">waiting for a check</span>}{r.held_reason === 'account_off' && <span className="block text-[10px] text-amber-600">held: this account is off</span>}{r.held_reason === 'campaign_off' && <span className="block text-[10px] text-amber-600">held: this campaign is off</span>}</td>
                  <td className="text-xs text-gray-500 whitespace-nowrap">{r.first_captured_at ? fmt.datetime(r.first_captured_at) : '—'}</td>
                  <td className="text-xs text-gray-500 whitespace-nowrap">{r.last_captured_at ? fmt.relative(r.last_captured_at) : '—'}</td>
                  <td className="text-right text-sm">{r.hits}</td>
                  <td className="text-xs text-gray-600 max-w-[260px]">
                    {held ? <>
                      {r.topic && <span className={`inline-block rounded-full px-2 py-0.5 text-[10px] font-medium mb-1 ${r.topic === 'lead_enquiry' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-800'}`}>{TOPIC_LABEL[r.topic] ?? r.topic}{r.topic_confidence != null ? ` · ${Math.round(r.topic_confidence * 100)}%` : ''}</span>}
                      <span className="line-clamp-2 block">{r.sample_text ? (/^[\p{Extended_Pictographic}(]/u.test(r.sample_text) ? r.sample_text : `“${r.sample_text}”`) : r.notes ?? '—'}</span></> : <>
                      <Badge variant={r.status === 'moved' ? 'green' : 'gray'}>{r.status}</Badge>
                      <span className="block text-gray-400">{r.result_note ?? ''}{r.handled_by ? ` · ${r.handled_by}` : ''}</span></>}
                  </td>
                  <td className="whitespace-nowrap text-right">
                    {held ? <>
                      <button className="text-xs text-brand-700 hover:underline mr-3" onClick={() => run('move', { ids: [r.id] })}>Move to Leads</button>
                      <button className="text-xs text-gray-400 hover:underline" onClick={() => run('discard', { ids: [r.id] })}>Discard</button></>
                      : r.lead_id ? <Link className="text-xs text-brand-700 hover:underline" to={`/crm/leads/${r.lead_id}`}>Open lead →</Link> : null}
                  </td>
                </tr>))}
              </tbody>
            </table></div>
            <Pagination page={page} lastPage={Math.max(1, Math.ceil(total / PER))} total={total} perPage={PER} onChange={setPage} />
          </>
        )}
      </div>
    </div>
  )
}
