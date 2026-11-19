// Shared bits for the "Leads by staff" pages: types, labels and small presentational pieces.
import { fmt } from '@/utils'

export interface StaffInsight {
  id: number; name: string; email: string; phone: string | null; department: string | null; role: string | null
  last_login_at: string | null; max_leads: number
  availability: { status: string; is_available: boolean; last_seen_at: string | null; open_leads: number }
  leads: { total: number; new: number; contacted: number; follow_up: number; enrolled: number; lost: number; active: number }
  conversion_rate: number; close_rate: number; high_priority_open: number
  assigned_today: number; assigned_7d: number; last_assigned_at: string | null
  routing: {
    by_method: Record<string, number>; sla_breached: number; transferred_away: number
    declined: number; no_response: number; avg_accept_minutes: number | null
  }
  top_sources: { name: string; count: number }[]
  top_categories: { name: string; count: number }[]
  recent_assignments?: { id: number; lead_id: number | null; status: string; method: string | null; mode: string; contact: string | null; source: string | null; category: string | null; created_at: string }[]
  recent_activity?: { id: number; event: string; payload: Record<string, any>; lead_id: number | null; contact: string | null; created_at: string }[]
}

export const METHOD_LABEL: Record<string, string> = {
  algorithm: 'Weighted algorithm', round_robin: 'Round robin', manual: 'Manual', transfer: 'Transfer',
  same_staff: 'Same staff', restore: 'Restored',
}

const STATUS_DOT: Record<string, string> = { online: 'bg-green-500', away: 'bg-amber-400', busy: 'bg-red-500', offline: 'bg-gray-300' }

export function StatusDot({ a }: { a: StaffInsight['availability'] }) {
  const label = a.status === 'online' && !a.is_available ? 'unavailable' : a.status
  return (
    <span className="inline-flex items-center gap-1.5 text-xs text-gray-600" title={a.last_seen_at ? `Last seen ${fmt.relative(a.last_seen_at)}` : 'Never seen'}>
      <span className={`h-2 w-2 rounded-full ${STATUS_DOT[a.status] ?? 'bg-gray-300'}`} />{label}
    </span>
  )
}

export function Chips({ items }: { items: { name: string; count: number }[] }) {
  if (!items.length) return <span className="text-xs text-gray-300">—</span>
  return (
    <div className="flex flex-wrap gap-1">
      {items.map((i) => <span key={i.name} className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600">{i.name} · {i.count}</span>)}
    </div>
  )
}

export function MethodChips({ by }: { by: Record<string, number> }) {
  return <Chips items={Object.entries(by).map(([k, c]) => ({ name: METHOD_LABEL[k] ?? k, count: c }))} />
}

export const when = (d: string | null | undefined) => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'
