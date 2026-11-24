// Company-wide feed of lead activity — every stage change, note, assignment, accept/decline,
// timeout-and-reassign, transfer, and AI hand-off, across every lead, newest first.
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { leadApi } from '@/api'
import { Badge, EmptyState, Pagination, Spinner, StatCard } from '@/components/ui'
import { fmt, formatPhone, getError, stageConfig } from '@/utils'
import toast from 'react-hot-toast'
import { pid } from '@/lib/publicId'

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
  lead_assignment_restored: 'Restored',
  lead_recalled: 'Recalled', category_changed: 'Category changed',
  call_logged: 'Call logged', message_logged: 'Message logged', meeting_logged: 'Meeting logged', visit_logged: 'Visit logged', other_logged: 'Activity logged',
  lead_verified: 'Verified', lead_verification_revoked: 'Verification removed',
  lead_ai_handoff: 'AI hand-off',
  lead_sla_breached: 'SLA breached',
  lead_review_approved: 'Review approved', lead_review_held: 'Held for review',
}

const EVENT_ICON: Record<string, string> = {
  note_added: '📝', stage_changed: '🔀', assigned: '👤', reassigned_bulk: '⇄',
  lead_assigned: '📨', lead_assignment_accepted: '✅', lead_assignment_declined: '❌',
  lead_assignment_timeout: '⏱️', lead_assignment_transferred: '🔁', lead_assignment_completed: '🏁', lead_assignment_restored: '↩️', lead_recalled: '↩', category_changed: '📂', call_logged: '📞', message_logged: '💬', meeting_logged: '🤝', visit_logged: '🏢', other_logged: '📝', lead_verified: '✅', lead_verification_revoked: '↺',
  lead_ai_handoff: '🤖', lead_sla_breached: '⚠️', lead_review_approved: '🛂', lead_review_held: '⏸',
}

interface LogRow {
  id: number
  event: string
  payload: Record<string, any>
  user: string | null
  created_at: string
  lead: { id: number; stage: string; source?: string | null; category?: string | null; origin?: string | null; contact: { name: string | null; phone: string } | null; assigned_to: string | null } | null
}

const METHOD_LABEL: Record<string, string> = {
  algorithm: 'Weighted algorithm', round_robin: 'Round robin', manual: 'Manual', transfer: 'Manual transfer',
  same_staff: 'Same staff (repeat lead)', restore: 'Restored',
}
const MODE_LABEL: Record<string, string> = { auto: 'auto', manual: 'manual', notification: 'notify → accept' }

function describe(row: LogRow): string {
  const p = row.payload ?? {}
  switch (row.event) {
    case 'note_added':      return String(p.content ?? '')
    case 'stage_changed':   return `${stageConfig[p.from as keyof typeof stageConfig]?.label ?? p.from} → ${stageConfig[p.to as keyof typeof stageConfig]?.label ?? p.to}`
    case 'assigned':        return p.to ? 'Assigned to a staff member' : 'Unassigned'
    case 'reassigned_bulk': return `From #${p.from ?? '—'} to #${p.to ?? '—'}`
    case 'lead_assigned':             return `Assigned to ${p.staff_name ?? 'a staff member'}${p.method ? ` · ${METHOD_LABEL[p.method] ?? p.method}` : ''}${p.mode ? ` · ${MODE_LABEL[p.mode] ?? p.mode}` : ''}`
    case 'lead_assignment_restored':  return p.from === 'transfer' ? `Transfer undone — back to ${p.to_staff_name ?? 'previous staff'}` : `Completion undone — back to ${p.to ?? 'previous status'}`
    case 'lead_assignment_accepted':  return `${p.staff_name ?? 'Staff'} accepted`
    case 'lead_assignment_declined':  return `${p.staff_name ?? 'Staff'} declined`
    case 'lead_assignment_timeout':   return `${p.staff_name ?? 'Staff'} — no response in ${p.timeout_seconds ?? '?'}s`
    case 'lead_assignment_transferred': return `${p.from_staff_name ?? '—'} → ${p.to_staff_name ?? '—'}${p.reason ? ` (${p.reason})` : ''}`
    case 'lead_assignment_completed': return 'Marked as completed/converted'
    case 'lead_recalled':             return p.reason === 'auto_reassign' ? 'Taken back and re-assigned by auto assign' : 'Taken back from the staff member — unassigned'
    case 'call_logged': case 'message_logged': case 'meeting_logged': case 'visit_logged': case 'other_logged':
      return `${p.first ? 'New-lead contact' : 'Follow-up'}${p.outcome ? ` · ${String(p.outcome).replace('_', ' ')}` : ''}${p.notes ? ` — ${p.notes}` : ''}`
    case 'category_changed': { const f = [p.from].flat().filter(Boolean).join(', '); const t = [p.to].flat().filter(Boolean).join(', '); return t ? `${f || 'none'} → ${t}` : `Removed${f ? ` (was ${f})` : ''}` }
    case 'lead_verified':             return 'Converted / closed lead approved'
    case 'lead_verification_revoked': return 'Approval taken back'
    case 'lead_ai_handoff':           return p.reason ? String(p.reason) : 'Handed to the AI agent'
    case 'lead_review_approved':      return `Checked by ${p.by ?? 'a team head'}${p.topic ? ` · seen as ${String(p.topic).replace(/_/g, ' ')}` : ''}${p.assigned_to ? ` · given to ${p.assigned_to}` : ''}`
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
  const navigate = useNavigate()

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
                <thead><tr><th>When</th><th>Lead</th><th>Source</th><th>Category</th><th>Activity</th><th>Details</th><th>By</th><th>Action</th></tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.id} className={r.lead ? 'cursor-pointer hover:bg-gray-50' : ''} onDoubleClick={() => r.lead && navigate(`/crm/leads/${pid(r.lead.id)}`)}>
                      <td className="text-xs whitespace-nowrap">
                        <div className="text-gray-700">{new Date(r.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })}</div>
                        <div className="text-gray-400">{fmt.relative(r.created_at)}</div>
                      </td>
                      <td>
                        {r.lead ? (
                          <Link to={`/crm/leads/${pid(r.lead.id)}`} className="text-brand-600 hover:underline">
                            {r.lead.contact?.name || formatPhone(r.lead.contact?.phone || '')}
                          </Link>
                        ) : <span className="text-gray-300">—</span>}
                        {r.lead && <Badge variant="gray" className="ml-2">{stageConfig[r.lead.stage as keyof typeof stageConfig]?.label ?? r.lead.stage}</Badge>}
                      </td>
                      <td className="text-xs text-gray-600">{r.lead?.source || '—'}{r.lead?.origin && <div className="text-gray-400">{r.lead.origin}</div>}</td>
                      <td className="text-xs text-gray-600">{r.lead?.category || '—'}</td>
                      <td className="whitespace-nowrap"><span className="mr-1.5">{EVENT_ICON[r.event] ?? '•'}</span>{EVENT_LABEL[r.event] ?? r.event.replace(/_/g, ' ')}</td>
                      <td className="text-sm text-gray-600 max-w-[360px] truncate" title={describe(r)}>{describe(r)}</td>
                      <td className="text-xs text-gray-400 whitespace-nowrap">{r.user ?? '—'}</td>
                      <td className="whitespace-nowrap">
                        {r.lead
                          ? <Link to={`/crm/leads/${pid(r.lead.id)}`} className="text-xs font-medium text-brand-600 hover:underline">View lead →</Link>
                          : <span className="text-xs text-gray-300" title="This lead has since been deleted">Deleted</span>}
                      </td>
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
