// Paginated conversation list (Unread / Read / Closed). Loaded page by page; kept current by socket pushes.
import { useCallback, useEffect, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { getError } from '@/utils'
import { subscribeWidgetChat } from '@/socket/staffSocket'
import { nodeChatApi, type InboxTab, type NodeInboxRow } from '../api'
import { avatarColor, initials, timeAgo } from './util'

const TABS: { key: InboxTab; label: string }[] = [
  { key: 'unread', label: 'Unread' }, { key: 'read', label: 'Read' }, { key: 'closed', label: 'Closed' },
]
const PER_PAGE = 25
const FALLBACK_POLL_MS = 30_000 // only while the socket is down

interface Props {
  openIds: number[]
  muted: boolean
  refreshKey: number // bump to force a reload (e.g. after accepting in a pane)
  onOpen: (id: number) => void
  onCounts: (c: Record<InboxTab, number>) => void
  onSocket: (up: boolean) => void
  onForbidden: () => void
}

const badge = (r: NodeInboxRow) => {
  if (r.live_status === 'waiting') return <span className="pill bg-amber-100 text-amber-700">Waiting</span>
  if (r.live_status === 'live') return <span className="pill bg-emerald-100 text-emerald-700">Live</span>
  if (r.live_status === 'missed') return <span className="pill bg-rose-100 text-rose-700">{r.callback_confirmed_at ? 'Call back' : 'Missed'}</span>
  if (r.case_status === 'pending') return <span className="pill bg-amber-100 text-amber-700">Pending</span>
  if (r.case_status === 'attended') return <span className="pill bg-sky-100 text-sky-700">Attended</span>
  if (r.case_status === 'closed') return <span className="pill bg-gray-100 text-gray-600">Solved</span>
  return null
}

export default function InboxList({ openIds, muted, refreshKey, onOpen, onCounts, onSocket, onForbidden }: Props) {
  const [tab, setTab] = useState<InboxTab>('unread')
  const [q, setQ] = useState('')
  const [dq, setDq] = useState('')
  const [rows, setRows] = useState<NodeInboxRow[]>([])
  const [counts, setCounts] = useState<Record<InboxTab, number>>({ unread: 0, read: 0, closed: 0 })
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [socketUp, setSocketUp] = useState(false)
  const seenWaiting = useRef<Set<number> | null>(null)
  const req = useRef(0)
  const pageRef = useRef(1); pageRef.current = page
  const mutedRef = useRef(muted); mutedRef.current = muted

  useEffect(() => { const t = setTimeout(() => setDq(q.trim()), 350); return () => clearTimeout(t) }, [q])

  const alertNew = useCallback((list: NodeInboxRow[]) => {
    const first = seenWaiting.current === null
    seenWaiting.current ??= new Set()
    list.filter(c => c.live_status === 'waiting' && !seenWaiting.current!.has(c.id)).forEach(c => {
      seenWaiting.current!.add(c.id)
      if (first || mutedRef.current) return
      toast(`💬 ${c.visitor_name || 'Visitor'} ${c.visitor_phone ? `(${c.visitor_phone}) ` : ''}is waiting for assistance`, { duration: 8000 })
      if ('Notification' in window && Notification.permission === 'granted') new Notification('Live chat waiting', { body: `${c.visitor_name || 'Visitor'} ${c.visitor_phone ?? ''}` })
    })
  }, [])

  // (Re)load pages 1..n so a push refreshes what the agent has already scrolled through, not just the top.
  const reload = useCallback(async (keepPages = false) => {
    const my = ++req.current
    const upTo = keepPages ? Math.min(pageRef.current, 4) : 1
    try {
      const pages = await Promise.all(Array.from({ length: upTo }, (_, i) => nodeChatApi.inbox({ tab, q: dq, page: i + 1, per_page: PER_PAGE })))
      if (my !== req.current) return
      const all = pages.flatMap(p => p.data.data)
      const last = pages[pages.length - 1].data
      setRows(all); setCounts(last.counts); onCounts(last.counts); setHasMore(last.has_more); setPage(upTo)
      if (tab === 'unread') alertNew(all)
    } catch (e: any) {
      if (e?.response?.status === 403) onForbidden()
    } finally { if (my === req.current) setLoading(false) }
  }, [tab, dq, alertNew, onCounts, onForbidden])

  useEffect(() => { setLoading(true); setRows([]); setPage(1); void reload(false) }, [tab, dq]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (refreshKey) void reload(true) }, [refreshKey]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    let t: ReturnType<typeof setTimeout> | undefined
    let wasUp = false
    const unsub = subscribeWidgetChat(() => { clearTimeout(t); t = setTimeout(() => void reload(true), 400) }, up => {
      setSocketUp(up); onSocket(up)
      if (up && wasUp === false) void reload(true) // reconnect → resync
      wasUp = up
    })
    return () => { unsub(); clearTimeout(t) }
  }, [reload, onSocket])

  useEffect(() => {
    if (socketUp) return
    const t = setInterval(() => { if (!document.hidden) void reload(true) }, FALLBACK_POLL_MS)
    return () => clearInterval(t)
  }, [socketUp, reload])

  const loadMore = async () => {
    if (loadingMore || !hasMore) return
    setLoadingMore(true)
    try {
      const r = await nodeChatApi.inbox({ tab, q: dq, page: page + 1, per_page: PER_PAGE })
      setRows(prev => { const seen = new Set(prev.map(x => x.id)); return [...prev, ...r.data.data.filter(x => !seen.has(x.id))] })
      setPage(page + 1); setHasMore(r.data.has_more)
    } catch (e) { toast.error(getError(e)) } finally { setLoadingMore(false) }
  }

  // Auto-load the next page when the sentinel scrolls into view.
  const sentinel = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const el = sentinel.current; if (!el || !hasMore) return
    const io = new IntersectionObserver(es => { if (es[0].isIntersecting) void loadMore() }, { rootMargin: '120px' })
    io.observe(el); return () => io.disconnect()
  }) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <aside className="flex flex-col min-h-0 h-full w-full bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
      <div className="p-3 space-y-3 border-b border-gray-100">
        <div className="flex p-1 bg-gray-100 rounded-xl">
          {TABS.map(t => (
            <button key={t.key} onClick={() => setTab(t.key)}
              className={`flex-1 px-2 py-1.5 rounded-lg text-xs font-semibold transition ${tab === t.key ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'}`}>
              {t.label}{counts[t.key] > 0 && <span className={`ml-1.5 px-1.5 rounded-full text-[10px] ${t.key === 'unread' ? 'bg-rose-500 text-white' : 'bg-gray-200 text-gray-600'}`}>{counts[t.key]}</span>}
            </button>
          ))}
        </div>
        <input className="input w-full" placeholder="Search name / phone" value={q} onChange={e => setQ(e.target.value)} />
      </div>

      <div className="flex-1 min-h-0 overflow-y-auto">
        {loading && rows.length === 0 && [0, 1, 2, 3].map(i => (
          <div key={i} className="flex gap-3 px-3 py-3 animate-pulse"><div className="w-10 h-10 rounded-full bg-gray-200" /><div className="flex-1 space-y-2"><div className="h-3 bg-gray-200 rounded w-1/2" /><div className="h-3 bg-gray-100 rounded w-3/4" /></div></div>
        ))}
        {!loading && rows.length === 0 && <p className="p-6 text-sm text-gray-400 text-center">{dq ? 'No chats match your search.' : `No ${tab} chats.`}</p>}
        {rows.map(r => {
          const isOpen = openIds.includes(r.id)
          return (
            <button key={r.id} onClick={() => onOpen(r.id)}
              className={`w-full text-left flex gap-3 px-3 py-3 border-b border-gray-50 transition ${isOpen ? 'bg-brand-50' : 'hover:bg-gray-50'}`}>
              <div className={`relative w-10 h-10 shrink-0 rounded-full grid place-items-center text-white text-xs font-semibold ${avatarColor(r.id)}`}>
                {initials(r.visitor_name)}
                {r.is_unread && tab !== 'closed' && <span className="absolute -top-0.5 -right-0.5 w-3 h-3 rounded-full bg-rose-500 ring-2 ring-white" />}
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <span className={`text-sm truncate flex-1 ${r.is_unread ? 'font-semibold text-gray-900' : 'font-medium text-gray-700'}`}>{r.visitor_name || 'Visitor'}</span>
                  <span className="text-[10px] text-gray-400 shrink-0">{timeAgo(r.last_message_at)}</span>
                </div>
                <div className="text-[11px] text-gray-500 truncate">{r.visitor_phone || 'No number'} · {r.widget?.name}{r.staff_name && r.case_status === 'attended' ? ` · by ${r.staff_name}` : ''}</div>
                <div className="flex items-center gap-2 mt-0.5">
                  <span className="text-xs text-gray-400 truncate flex-1">{r.last_text}</span>
                  {badge(r)}
                </div>
              </div>
            </button>
          )
        })}
        <div ref={sentinel} className="h-px" />
        {hasMore && <button onClick={loadMore} disabled={loadingMore} className="w-full py-3 text-xs text-brand-600 hover:underline disabled:opacity-50">{loadingMore ? 'Loading…' : 'Load more'}</button>}
      </div>
    </aside>
  )
}
