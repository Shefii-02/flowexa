// Admin / higher staff: chat analytics (bot vs staff) + conversations marked "not satisfied" to study.
import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { Button, Badge } from '@/components/ui'
import { getError } from '@/utils'
import { liveChatApi } from './api'

const REASONS: Record<string, string> = {
  not_solved: 'Bot did not solve it',
  no_staff: 'No staff available',
  repeated: 'Repeated same issue',
  staff_flag: 'Flagged by staff',
}

export default function ChatReviewPanel() {
  const [days, setDays] = useState(30)
  const [stats, setStats] = useState<any>(null)
  const [reviewed, setReviewed] = useState(false)
  const [rows, setRows] = useState<any[]>([])
  const [notes, setNotes] = useState<Record<number, string>>({})
  const [forbidden, setForbidden] = useState(false)

  const load = useCallback(async () => {
    try {
      const [a, r] = await Promise.all([liveChatApi.analytics(days), liveChatApi.review(reviewed)])
      setStats(a.data); setRows(r.data.data ?? [])
    } catch (e: any) { if (e?.response?.status === 403) setForbidden(true) }
  }, [days, reviewed])
  useEffect(() => { void load() }, [load])

  const done = async (id: number) => {
    try { await liveChatApi.markReviewed(id, notes[id]); toast.success('Marked as reviewed'); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  if (forbidden) return null
  if (!stats) return null
  const s = stats.status
  const tiles: [string, number][] = [
    ['Conversations', stats.total], ['Solved by bot', stats.solved_by_bot], ['Handed to staff', stats.handed_to_staff],
    ['Pending', s.pending], ['Unread (waiting)', s.unread], ['Attended', s.attended], ['Awaiting review', stats.awaiting_review],
  ]

  return (
    <div className="card p-0 overflow-hidden">
      <div className="px-4 py-3 border-b border-gray-100 flex items-center gap-3 flex-wrap">
        <h2 className="font-semibold text-sm">Chat analytics &amp; review</h2>
        <select className="input w-32 ml-auto" value={days} onChange={e => setDays(Number(e.target.value))}>
          {[7, 30, 90].map(d => <option key={d} value={d}>Last {d} days</option>)}
        </select>
      </div>

      <div className="p-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
        {tiles.map(([label, n]) => (
          <div key={label} className="rounded-lg border border-gray-100 p-3">
            <div className="text-xl font-semibold">{n}</div>
            <div className="text-xs text-gray-500">{label}</div>
          </div>
        ))}
      </div>

      <div className="px-4 pb-4 grid md:grid-cols-2 gap-4">
        <div>
          <h3 className="text-xs font-semibold text-gray-500 mb-2">Not satisfied — by reason</h3>
          {Object.keys(stats.not_satisfied).length === 0 && <p className="text-sm text-gray-400">None 🎉</p>}
          {Object.entries(stats.not_satisfied).map(([k, n]) => (
            <div key={k} className="flex justify-between text-sm py-0.5"><span>{REASONS[k] ?? k}</span><b>{n as number}</b></div>
          ))}
          {stats.repeat_requests_merged > 0 &&
            <p className="text-xs text-gray-400 mt-2">{stats.repeat_requests_merged} repeat request(s) merged into an open case instead of alerting staff again.</p>}
        </div>
        <div>
          <h3 className="text-xs font-semibold text-gray-500 mb-2">Staff workload</h3>
          {stats.staff.length === 0 && <p className="text-sm text-gray-400">No staff-attended chats yet.</p>}
          {stats.staff.map((u: any) => (
            <div key={u.attended_by} className="flex justify-between text-sm py-0.5">
              <span>{u.name}</span>
              <span className="text-gray-500">{u.attended} attended · {u.closed} closed
                {u.avg_wait_seconds != null && ` · ${Math.round(u.avg_wait_seconds)}s avg pick-up`}</span>
            </div>
          ))}
        </div>
      </div>

      <div className="border-t border-gray-100 px-4 py-3 flex items-center gap-2">
        <h3 className="font-semibold text-sm">Marked not satisfied</h3>
        {(['To review', 'Reviewed'] as const).map((l, i) => (
          <button key={l} onClick={() => setReviewed(i === 1)}
            className={`px-3 py-1 rounded-full text-xs font-medium ${reviewed === (i === 1) ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'}`}>{l}</button>
        ))}
      </div>
      <div>
        {rows.length === 0 && <p className="px-4 pb-4 text-sm text-gray-400">Nothing {reviewed ? 'reviewed yet' : 'to review'}.</p>}
        {rows.map(r => (
          <div key={r.id} className="px-4 py-3 border-t border-gray-50 text-sm">
            <div className="flex items-center gap-2 flex-wrap">
              <b>{r.visitor_name || 'Visitor'}</b><span className="text-gray-500">{r.visitor_phone}</span>
              <Badge variant="red">{REASONS[r.flag_reason] ?? r.flag_reason}</Badge>
              <span className="text-xs text-gray-400">{r.staff_name ? `Attended by ${r.staff_name}` : 'No staff attended'}</span>
            </div>
            {r.collected?.unresolved_issue && <p className="text-gray-600 mt-1">“{r.collected.unresolved_issue}”</p>}
            {reviewed
              ? r.review_note && <p className="text-xs text-gray-500 mt-1">Note: {r.review_note}</p>
              : (
                <div className="flex gap-2 mt-2">
                  <input className="input flex-1" placeholder="Finding / improvement note (optional)" value={notes[r.id] ?? ''}
                    onChange={e => setNotes(n => ({ ...n, [r.id]: e.target.value }))} />
                  <Button size="sm" onClick={() => done(r.id)}>Mark reviewed</Button>
                </div>
              )}
          </div>
        ))}
      </div>
    </div>
  )
}
