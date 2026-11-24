import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { notificationApi } from '@/api'
import { useNotificationCounts } from '@/lib/useNotificationCounts'
import { cn, fmt, getError } from '@/utils'

type Item = { id: number; type: string; category: string; title: string; message: string | null; data: Record<string, any> | null; created_at: string; read_at: string | null }
type Tab = 'all' | 'lead' | 'other'
const ICON: Record<string, string> = { lead: '🎯', team: '💬', hr: '🕒', campaign: '📢', billing: '💳', system: '⚙️', other: '🔔' }

/** Where a notification leads when clicked (the app uses the same `action` values). */
function targetFor(n: Item): string | null {
  const d = n.data ?? {}
  switch (d.action) {
    case 'open_lead': return d.lead_id ? `/crm/leads/${d.lead_id}` : '/crm/leads?mine=1'
    case 'open_leads': case 'lead_offer': case 'ai_handoff_offer': return '/crm/leads?mine=1'
    case 'open_team_chat': return '/team-chat'
    case 'open_wallet': return '/plan-purchase?section=wallet'
    case 'open_campaign': return '/wa-cloud/campaigns'
    default: return null
  }
}

/** Top-bar bell: this person's own notifications (leads first), with the team-chat unread count folded into the badge. */
export default function NotificationBell() {
  const navigate = useNavigate()
  const { counts, refresh } = useNotificationCounts()
  const [open, setOpen] = useState(false)
  const [tab, setTab] = useState<Tab>('all')
  const [items, setItems] = useState<Item[]>([])
  const [loading, setLoading] = useState(false)
  const box = useRef<HTMLDivElement>(null)

  const load = useCallback(() => {
    setLoading(true)
    notificationApi.list({ category: tab, per_page: 30 }).then(r => setItems(r.data.data ?? [])).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [tab])
  useEffect(() => { if (open) load() }, [open, load, counts.unread])   // reload when something new arrives while it is open
  useEffect(() => {
    if (!open) return
    const click = (e: MouseEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false) }
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', click); document.addEventListener('keydown', key)
    return () => { document.removeEventListener('mousedown', click); document.removeEventListener('keydown', key) }
  }, [open])

  const openItem = async (n: Item) => {
    if (!n.read_at) { setItems(l => l.map(x => x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x)); notificationApi.read([n.id]).then(refresh).catch(() => {}) }
    const to = targetFor(n)
    if (to) { setOpen(false); navigate(to) }
  }
  const markAll = async () => { try { await notificationApi.read(); setItems(l => l.map(x => ({ ...x, read_at: x.read_at ?? new Date().toISOString() }))); refresh() } catch (e) { toast.error(getError(e)) } }

  return (
    <div className="relative" ref={box}>
      <button onClick={() => setOpen(o => !o)} aria-label="Notifications" className="relative p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 transition-colors">
        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 11-6 0" /></svg>
        {counts.badge > 0 && <span className="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-semibold flex items-center justify-center">{counts.badge > 99 ? '99+' : counts.badge}</span>}
      </button>

      {open && (
        <div className="absolute right-0 mt-2 w-[380px] max-w-[92vw] bg-white border border-gray-200 rounded-xl shadow-xl z-50 flex flex-col max-h-[70vh]" role="dialog" aria-label="Notifications">
          <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
            <p className="font-semibold text-gray-900 text-sm">Notifications</p>
            <button onClick={markAll} disabled={counts.unread === 0} className="text-xs text-brand-600 hover:underline disabled:text-gray-300 disabled:no-underline">Mark all read</button>
          </div>
          <div className="flex gap-1 px-3 pt-2">
            {([['all', 'All', counts.unread], ['lead', 'Leads', counts.unread_leads], ['other', 'Other', counts.unread_other]] as [Tab, string, number][]).map(([id, label, n]) => (
              <button key={id} onClick={() => setTab(id)} className={cn('px-3 py-1 rounded-full text-xs', tab === id ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200')}>
                {label}{n > 0 && <span className={cn('ml-1.5 font-semibold', tab === id ? 'text-white' : 'text-red-500')}>{n}</span>}
              </button>
            ))}
          </div>
          {counts.team_chat_unread > 0 && (
            <button onClick={() => { setOpen(false); navigate('/team-chat') }} className="mx-3 mt-2 flex items-center gap-2 rounded-lg bg-indigo-50 px-3 py-2 text-left text-sm text-indigo-800 hover:bg-indigo-100">
              <span>💬</span><span className="flex-1">Team chat</span><span className="font-semibold">{counts.team_chat_unread} unread</span>
            </button>
          )}
          <div className="overflow-y-auto flex-1 py-1 mt-1">
            {loading && items.length === 0 ? <p className="text-center text-xs text-gray-400 py-8">Loading…</p>
              : items.length === 0 ? <p className="text-center text-sm text-gray-400 py-10">🔔 Nothing here yet</p>
              : items.map(n => (
                <button key={n.id} onClick={() => openItem(n)} className={cn('w-full text-left px-4 py-2.5 flex gap-3 hover:bg-gray-50', !n.read_at && 'bg-brand-50/40')}>
                  <span className="text-lg leading-6">{ICON[n.category] ?? '🔔'}</span>
                  <span className="min-w-0 flex-1">
                    <span className={cn('block text-sm truncate', n.read_at ? 'text-gray-700' : 'font-semibold text-gray-900')}>{n.title}</span>
                    {n.message && <span className="block text-xs text-gray-500 line-clamp-2">{n.message}</span>}
                    <span className="block text-[11px] text-gray-400 mt-0.5">{fmt.relative(n.created_at)}</span>
                  </span>
                  {!n.read_at && <span className="mt-2 w-2 h-2 rounded-full bg-brand-500 flex-shrink-0" />}
                </button>
              ))}
          </div>
        </div>
      )}
    </div>
  )
}
