// Verify (approve) converted and closed leads — for people with the "leads.verify" permission.
// Filter by outcome, staff member and one start/end calendar, then approve many at once.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { leadVerificationApi, staffApi, heldLeadApi } from '@/api'
import { usePermission } from '@/store'
import { Badge, Button, EmptyState, Pagination, Spinner, StatCard } from '@/components/ui'
import DateRangePicker from '@/components/ui/DateRangePicker'
import StaffPicker from './components/StaffPicker'
import { fmt, getError } from '@/utils'

type Row = {
  id: number; contact: string; phone: string; outcome: 'converted' | 'closed'; closed_at: string | null; staff: string | null
  category: string | null; source: string | null; sale_value: string | null; cost: string | null; verified_at: string | null; verified_by: string | null
}
type Summary = { pending: number; verified: number; converted: number; closed: number }
const PER = 25
const money = (v: string | number | null) => v == null || v === '' ? '—' : `₹${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(Number(v))}`

export default function LeadVerificationPage() {
  const allowed = usePermission('leads.verify')
  const [outcome, setOutcome] = useState<'all' | 'converted' | 'closed'>('all')
  const [status, setStatus] = useState<'pending' | 'verified' | 'all'>('pending')
  const [staff, setStaff] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<Row[]>([])
  const [total, setTotal] = useState(0)
  const [summary, setSummary] = useState<Summary | null>(null)
  const canReview = usePermission('leads.manage')
  const [waiting, setWaiting] = useState(0)   // new contacts waiting for a check (the other kind of verification)
  useEffect(() => { if (canReview) heldLeadApi.list({ status: 'held', reason: 'needs_review', per_page: 5 }).then(r => setWaiting(r.data.summary?.held ?? 0)).catch(() => {}) }, [canReview])
  const [loading, setLoading] = useState(true)
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [busy, setBusy] = useState(false)
  const [staffList, setStaffList] = useState<any[]>([])

  useEffect(() => { staffApi.performance().then(r => setStaffList(r.data.performance ?? [])).catch(() => {}) }, [])

  const filters = useCallback(() => ({ outcome, status, staff_id: staff || undefined, from: from || undefined, to: to || undefined, search: search || undefined }), [outcome, status, staff, from, to, search])

  const load = useCallback(() => {
    setLoading(true)
    leadVerificationApi.list({ ...filters(), page, per_page: PER })
      .then(r => { setRows(r.data.data ?? []); setTotal(r.data.total ?? 0); setSummary(r.data.summary ?? null) })
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [filters, page])

  useEffect(() => { if (allowed) load() }, [allowed, load])
  useEffect(() => { setPage(1); setPicked(new Set()) }, [outcome, status, staff, from, to, search])

  if (!allowed) return <div className="p-8"><EmptyState icon="🔒" title="No access" desc="You need the “Verify converted & closed leads” permission. Ask an admin to add it to your role." /></div>

  const toggle = (id: number) => setPicked(s => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const pageIds = rows.filter(r => !r.verified_at).map(r => r.id)
  const allOnPage = pageIds.length > 0 && pageIds.every(id => picked.has(id))

  const run = async (kind: 'approve' | 'revoke', body: Record<string, unknown>) => {
    setBusy(true)
    try {
      const { data } = await (kind === 'approve' ? leadVerificationApi.approve(body) : leadVerificationApi.revoke(body))
      toast.success(data.message); setPicked(new Set()); load()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Verify leads</h1>
        <p className="page-sub">Check converted and closed leads, then approve them — one by one or many at once</p>
      </div>
      {waiting > 0 && (
        <Link to="/crm/leads/held?reason=needs_review" className="flex items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800 hover:bg-indigo-100">
          <span className="text-xl">🛂</span><span className="flex-1"><b>{waiting}</b> new contact{waiting === 1 ? ' is' : 's are'} waiting for a check before becoming leads</span><span className="font-medium">Open review →</span>
        </Link>
      )}

      {summary && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <StatCard label="Waiting for approval" value={fmt.number(summary.pending)} icon="⏳" />
          <StatCard label="Verified" value={fmt.number(summary.verified)} icon="✅" />
          <StatCard label="Converted" value={fmt.number(summary.converted)} icon="🏆" />
          <StatCard label="Closed (lost)" value={fmt.number(summary.closed)} icon="📕" />
        </div>
      )}

      <div className="card p-4 flex flex-wrap items-end gap-3">
        <div className="flex border border-gray-200 rounded-lg overflow-hidden text-sm">
          {(['all', 'converted', 'closed'] as const).map(o => <button key={o} onClick={() => setOutcome(o)} className={`px-3 py-1.5 ${outcome === o ? 'bg-brand-600 text-white' : 'bg-white text-gray-600'}`}>{o === 'all' ? 'Both' : o === 'converted' ? 'Converted' : 'Closed'}</button>)}
        </div>
        <div className="flex border border-gray-200 rounded-lg overflow-hidden text-sm">
          {(['pending', 'verified', 'all'] as const).map(s => <button key={s} onClick={() => setStatus(s)} className={`px-3 py-1.5 ${status === s ? 'bg-brand-600 text-white' : 'bg-white text-gray-600'}`}>{s === 'pending' ? 'Waiting' : s === 'verified' ? 'Verified' : 'All'}</button>)}
        </div>
        <div className="w-52"><p className="text-xs text-gray-500 mb-1">Staff</p><StaffPicker staff={staffList} value={staff} onChange={setStaff} placeholder="All staff" /></div>
        <DateRangePicker label="Converted / closed between" from={from} to={to} onChange={(f, t) => { setFrom(f); setTo(t) }} />
        <input className="input !w-48" placeholder="Search name / phone" value={search} onChange={e => setSearch(e.target.value)} />
        {(staff || from || to || search) && <button className="text-xs text-gray-400 hover:underline mb-2" onClick={() => { setStaff(''); setFrom(''); setTo(''); setSearch('') }}>Clear filters</button>}
      </div>

      {(picked.size > 0 || (status !== 'verified' && summary && summary.pending > 0)) && (
        <div className="flex flex-wrap items-center gap-3 bg-brand-50 border border-brand-200 rounded-xl px-4 py-2.5">
          <span className="text-sm font-medium text-brand-800">{picked.size} selected</span>
          <Button size="sm" onClick={() => run('approve', { lead_ids: [...picked] })} loading={busy} disabled={picked.size === 0}>✔ Approve selected</Button>
          {summary && summary.pending > 0 && status !== 'verified' && (
            <Button size="sm" variant="secondary" loading={busy}
              onClick={() => { if (confirm(`Approve all ${summary.pending} waiting lead(s) that match these filters?`)) run('approve', { all_matching: true, ...filters() }) }}>
              Approve all {fmt.number(summary.pending)} matching
            </Button>
          )}
          {picked.size > 0 && <button className="text-xs text-gray-400 hover:underline ml-auto" onClick={() => setPicked(new Set())}>Clear selection</button>}
        </div>
      )}

      <div className="card">
        {loading ? <div className="flex justify-center py-12"><Spinner size="lg" /></div> : rows.length === 0 ? (
          <EmptyState icon="🧾" title="Nothing to verify" desc={summary && summary.verified > 0 ? `No ${status === 'pending' ? 'waiting' : ''} converted or closed leads match these filters — ${summary.verified} are already verified (switch the status filter to see them).` : 'This page lists leads that reached Converted (Enrolled) or Closed (Lost). None have reached those stages yet — they appear here as soon as staff close one.'} />
        ) : (
          <>
            <div className="table-wrapper"><table className="table">
              <thead><tr>
                <th className="w-8"><input type="checkbox" checked={allOnPage} disabled={pageIds.length === 0} onChange={() => setPicked(allOnPage ? new Set() : new Set(pageIds))} /></th>
                <th>Lead</th><th>Outcome</th><th>Date</th><th>Staff</th><th>Category / source</th><th className="text-right">Sale value</th><th className="text-right">Cost</th><th>Status</th><th></th>
              </tr></thead>
              <tbody>{rows.map(r => (
                <tr key={r.id} className={picked.has(r.id) ? 'bg-brand-50/40' : ''}>
                  <td><input type="checkbox" checked={picked.has(r.id)} disabled={!!r.verified_at} onChange={() => toggle(r.id)} /></td>
                  <td><Link to={`/crm/leads/${r.id}`} className="font-medium text-brand-700 hover:underline">{r.contact}</Link><p className="text-xs text-gray-400 font-mono">{r.phone}</p></td>
                  <td><Badge variant={r.outcome === 'converted' ? 'green' : 'red'}>{r.outcome === 'converted' ? 'Converted' : 'Closed'}</Badge></td>
                  <td className="text-xs text-gray-600 whitespace-nowrap">{r.closed_at ? new Date(r.closed_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'}</td>
                  <td className="text-sm">{r.staff ?? <span className="text-gray-300">—</span>}</td>
                  <td className="text-xs text-gray-600">{r.category ?? '—'}<span className="block text-gray-400">{r.source ?? ''}</span></td>
                  <td className="text-right text-sm">{money(r.sale_value)}</td>
                  <td className="text-right text-sm text-gray-500">{money(r.cost)}</td>
                  <td className="text-xs">{r.verified_at ? <span className="text-green-700">✔ {r.verified_by ?? 'Verified'}<span className="block text-gray-400">{fmt.relative(r.verified_at)}</span></span> : <span className="text-amber-600">Waiting</span>}</td>
                  <td className="text-right whitespace-nowrap">
                    {r.verified_at
                      ? <button className="text-xs text-gray-400 hover:underline" onClick={() => run('revoke', { lead_ids: [r.id] })}>Undo</button>
                      : <button className="text-xs text-brand-700 hover:underline" onClick={() => run('approve', { lead_ids: [r.id] })}>Approve</button>}
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
