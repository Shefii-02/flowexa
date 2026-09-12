// Company-wide feed of lead activity — every stage change, note, assignment, accept/decline,
// timeout-and-reassign, transfer, and AI hand-off, across every lead, newest first.
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { leadApi } from '@/api'
import { Badge, EmptyState, Pagination, Spinner, StatCard } from '@/components/ui'
import { fmt, formatPhone, getError, stageConfig } from '@/utils'
import toast from 'react-hot-toast'

const EVENT_LABEL: Record<string, string> = {
  note_added: 'Note added',
  stage_changed: 'Stage changed',
  assigned: 'Assigned',
  reassigned_bulk: 'Switched (bulk)',
  lead_assigned: 'Routed to staff',
  lead_assignment_accepted: 'Accepted',
  lead_assignment_declined: 'Declined',
  lead_assignment_timeout: 'Timed out',
  lead_assignment_transferred: 'Transferred',
  lead_assignment_completed: 'Completed',
  lead_ai_handoff: 'AI hand-off',
  lead_sla_breached: 'SLA breached',
}

const EVENT_ICON: Record<string, string> = {
  note_added: '📝', stage_changed: '🔀', assigned: '👤', reassigned_bulk: '⇄',
  lead_assigned: '📨', lead_assignment_accepted: '✅', lead_assignment_declined: '❌',
  lead_assignment_timeout: '⏱️', lead_assignment_transferred: '🔁', lead_assignment_completed: '🏁',
  lead_ai_handoff: '🤖', lead_sla_breached: '⚠️',
}

interface LogRow {
  id: number
  event: string
  payload: Record<string, any>
  user: string | null
  created_at: string
  lead: { id: number; stage: string; contact: { name: string | null; phone: string } | null; assigned_to: string | null } | null
}

function describe(row: LogRow): string {
  const p = row.payload ?? {}
  switch (row.event) {
    case 'note_added':      return String(p.content ?? '')
    case 'stage_changed':   return `${stageConfig[p.from as keyof typeof stageConfig]?.label ?? p.from} → ${stageConfig[p.to as keyof typeof stageConfig]?.label ?? p.to}`
    case 'assigned':        return p.to ? 'Assigned to a staff member' : 'Unassigned'
    case 'reassigned_bulk': return `From #${p.from ?? '—'} to #${p.to ?? '—'}`
    case 'lead_assigned':             return `${p.staff_name ?? 'a staff member'}`
    case 'lead_assignment_accepted':  return `${p.staff_name ?? 'Staff'} accepted`
    case 'lead_assignment_declined':  return `${p.staff_name ?? 'Staff'} declined`
    case 'lead_assignment_timeout':   return `${p.staff_name ?? 'Staff'} — no response in ${p.timeout_seconds ?? '?'}s`
    case 'lead_assignment_transferred': return `${p.from_staff_name ?? '—'} → ${p.to_staff_name ?? '—'}${p.reason ? ` (${p.reason})` : ''}`
    case 'lead_assignment_completed': return 'Marked as completed/converted'
    case 'lead_ai_handoff':           return p.reason ? String(p.reason) : 'Handed to the AI agent'
    case 'lead_sla_breached':         return `Reply SLA (${p.sla_minutes ?? '?'} min) breached`
    default:                return '—'
  }
}

export default function LeadLogsPage() {
  const [rows, setRows] = useState<LogRow[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [event, setEvent] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    setLoading(true)
    leadApi.logs({ page, per_page: 30, event: event || undefined, from: from || undefined, to: to || undefined })
      .then((r) => { setRows(r.data.data ?? []); setTotal(r.data.total ?? 0) })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [page, event, from, to])

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">Lead Logs</h1>
        <p className="page-sub">Every stage change, note, assignment, accept/decline, timeout and hand-off — across every lead</p>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard label="Events" value={fmt.number(total)} icon="📜" />
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <select className="select max-w-[220px]" value={event} onChange={(e) => { setEvent(e.target.value); setPage(1) }}>
          <option value="">All activity</option>
          {Object.entries(EVENT_LABEL).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
        </select>
        <label className="text-xs text-gray-500">From
          <input type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1) }}
            className="block mt-1 text-sm border border-gray-200 rounded-lg px-2 py-1.5" />
        </label>
        <label className="text-xs text-gray-500">To
          <input type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1) }}
            className="block mt-1 text-sm border border-gray-200 rounded-lg px-2 py-1.5" />
        </label>
        {(event || from || to) && (
          <button className="text-xs text-gray-400 hover:underline mb-1.5" onClick={() => { setEvent(''); setFrom(''); setTo('') }}>
            Clear filters
          </button>
        )}
      </div>

      <div className="card">
        {loading ? (
          <div className="flex justify-center py-12"><Spinner size="lg" /></div>
        ) : rows.length === 0 ? (
          <EmptyState icon="📜" title="No activity yet" desc="Lead activity — assignments, notes, stage changes — will show up here as it happens." />
        ) : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead><tr><th>When</th><th>Lead</th><th>Activity</th><th>Details</th><th>By</th></tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id}>
                      <td className="text-xs text-gray-400 whitespace-nowrap">{fmt.relative(r.created_at)}</td>
                      <td>
                        {r.lead ? (
                          <Link to={`/leads/${r.lead.id}`} className="text-brand-600 hover:underline">
                            {r.lead.contact?.name || formatPhone(r.lead.contact?.phone || '')}
                          </Link>
                        ) : <span className="text-gray-300">—</span>}
                        {r.lead && <Badge variant="gray" className="ml-2">{stageConfig[r.lead.stage as keyof typeof stageConfig]?.label ?? r.lead.stage}</Badge>}
                      </td>
                      <td className="whitespace-nowrap"><span className="mr-1.5">{EVENT_ICON[r.event] ?? '•'}</span>{EVENT_LABEL[r.event] ?? r.event.replace(/_/g, ' ')}</td>
                      <td className="text-sm text-gray-600 max-w-[360px] truncate" title={describe(r)}>{describe(r)}</td>
                      <td className="text-xs text-gray-400 whitespace-nowrap">{r.user ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} lastPage={Math.ceil(total / 30)} total={total} perPage={30} onChange={setPage} />
          </>
        )}
      </div>
    </div>
  )
}
