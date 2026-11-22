// Website chats workspace — /website-widget/chats. Served by backend-node (REST + socket events); up to 4
// conversations side by side, each with expand ⤢ and close ✕.
import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import toast from 'react-hot-toast'
import { getError } from '@/utils'
import { PermissionGate } from '@/components/ui/PermissionGate'
import { nodeChatApi, widgetApi, type ChatWidget, type InboxTab } from '../api'
import InboxList from './InboxList'
import ChatPane from './ChatPane'
import { MAX_PANES, loadPanes, resolveWidget, savePanes, widgetSlug } from './util'

function Workspace() {
  // ?widget=… narrows the list to one widget's conversations (the "Conversations" link on the widgets page). It can be the widget's number,
  // its key (wgt_…) or its name as a slug — resolved here; while that is being looked up, or if nothing matches, we never fall back to "all".
  // The widget comes from the path (/website-widget/{name}/chats); the old ?widget= link still works and is rewritten to the path form below.
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const routeWidget = useParams().widget
  const widgetParam = (routeWidget ?? params.get('widget') ?? '').trim()
  const showAll = () => navigate('/website-widget/chats')
  const [widgetState, setWidgetState] = useState<'none' | 'loading' | 'found' | 'missing'>(widgetParam ? 'loading' : 'none')
  const [widget, setWidget] = useState<{ id: number; name: string } | null>(null)
  useEffect(() => {
    if (!widgetParam) { setWidgetState('none'); setWidget(null); return }
    let off = false
    setWidgetState('loading')
    widgetApi.list().then(r => {
      if (off) return
      const hit = resolveWidget(widgetParam, (r.data.widgets ?? r.data.data ?? r.data) as ChatWidget[])
      if (hit) {
        setWidget({ id: hit.id, name: hit.name }); setWidgetState('found')
        const slug = widgetSlug(hit.name)
        if (slug && (!routeWidget || routeWidget !== slug)) navigate(`/website-widget/${slug}/chats`, { replace: true })   // canonical, readable URL
      } else { setWidget(null); setWidgetState('missing') }
    }).catch(() => { if (!off) { setWidget(null); setWidgetState('missing') } })
    return () => { off = true }
  }, [widgetParam]) // eslint-disable-line react-hooks/exhaustive-deps
  const widgetId = widget?.id ?? 0
  const widgetName = widget?.name ?? ''
  const [open, setOpen] = useState<number[]>(loadPanes)
  const [expanded, setExpanded] = useState<number | null>(null)
  const [me, setMe] = useState({ muted: false, handling: 0, limit: 5 })
  const [socketUp, setSocketUp] = useState(false)
  const [forbidden, setForbidden] = useState(false)
  const [counts, setCounts] = useState<Record<InboxTab, number>>({ all: 0, unread: 0, read: 0, closed: 0 })
  const [refreshKey, setRefreshKey] = useState(0)
  const [mobileList, setMobileList] = useState(true)

  useEffect(() => { savePanes(open) }, [open])
  useEffect(() => { if ('Notification' in window && Notification.permission === 'default') void Notification.requestPermission() }, [])

  const loadMe = useCallback(() => { nodeChatApi.me().then(r => setMe(r.data)).catch(() => {}) }, [])
  useEffect(loadMe, [loadMe])

  // Anything that changes accept/end counts → refresh both the list and "x/5 chats".
  const changed = useCallback(() => { setRefreshKey(k => k + 1); loadMe() }, [loadMe])

  const openChat = (id: number) => {
    setOpen(prev => prev.includes(id) ? prev : prev.length >= MAX_PANES ? [...prev.slice(1), id] : [...prev, id]) // full → replace the oldest screen
    setExpanded(e => (e && e !== id ? null : e))
    setMobileList(false)
  }
  const closeChat = useCallback((id: number) => {
    setOpen(prev => prev.filter(x => x !== id))
    setExpanded(e => (e === id ? null : e))
  }, [])

  const toggleMute = async () => {
    try { const r = await nodeChatApi.setMuted(!me.muted); setMe(m => ({ ...m, muted: r.data.muted })) } catch (e) { toast.error(getError(e)) }
  }

  if (forbidden) return <div className="card p-6 text-sm text-gray-500">You don't have access to website live chats. Ask an admin for the “Handle Website Live Chats” permission.</div>

  const shown = expanded ? open.filter(id => id === expanded) : open

  return (
    <div className="flex flex-col h-[calc(100vh-7.5rem)] min-h-[520px] gap-3">
      <div className="flex items-center gap-3 flex-wrap">
        <Link to="/website-widget" className="icon-btn" title="Back to widgets">←</Link>
        <div className="flex-1 min-w-0">
          <h1 className="page-title">{widgetId ? `${widgetName || 'Widget'} — conversations` : widgetParam ? 'Website chats' : 'Website chats'}</h1>
          <p className="page-sub">{widgetState === 'missing'
            ? <>No widget matches “{widgetParam}”. <button className="underline" onClick={showAll}>Show all widgets</button></>
            : widgetId
            ? <>Only this widget's chats. <button className="underline" onClick={showAll}>Show all widgets</button></>
            : <>Live conversations from your website widget — open up to {MAX_PANES} side by side.</>}</p>
        </div>
        <span title={socketUp ? 'Realtime connected' : 'Reconnecting — refreshing every 30 s meanwhile'}
          className={`inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full border ${socketUp ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700'}`}>
          <span className={`w-2 h-2 rounded-full ${socketUp ? 'bg-emerald-500' : 'bg-amber-500 animate-pulse'}`} />{socketUp ? 'Live' : 'Reconnecting'}
        </span>
        <span className="text-xs text-gray-500" title="Chats you are handling / your limit">{me.handling}/{me.limit} chats</span>
        <button onClick={toggleMute} title="Mute stops new-chat alerts for you"
          className={`px-3 py-1 rounded-full text-xs border ${me.muted ? 'bg-gray-800 text-white border-gray-800' : 'border-gray-300 text-gray-600 hover:bg-gray-50'}`}>{me.muted ? '🔕 Muted' : '🔔 Alerts on'}</button>
        {open.length > 0 && <button onClick={() => { setOpen([]); setExpanded(null) }} className="text-xs text-gray-500 hover:text-rose-600">Close all</button>}
        <button onClick={() => setMobileList(true)} className="md:hidden text-xs px-3 py-1 rounded-full border border-gray-300">Chats{counts.unread > 0 ? ` (${counts.unread})` : ''}</button>
      </div>

      <div className="flex-1 min-h-0 flex gap-3">
        <div className={`${mobileList ? 'flex' : 'hidden'} md:flex w-full md:w-[330px] shrink-0 min-h-0`}>
          {widgetState === 'loading' ? <div className="flex-1 grid place-items-center text-sm text-gray-400 bg-white rounded-2xl border border-gray-200">Loading widget…</div>
            : widgetState === 'missing' ? <div className="flex-1 grid place-items-center text-center p-6 text-sm text-gray-500 bg-white rounded-2xl border border-gray-200"><div>This link points to a widget that does not exist (or was removed).<br /><button className="mt-2 underline text-brand-600" onClick={showAll}>Show chats from all widgets</button></div></div>
            : <InboxList widgetId={widgetId || undefined} openIds={open} muted={me.muted} refreshKey={refreshKey} onOpen={openChat} onCounts={setCounts} onSocket={setSocketUp} onForbidden={() => setForbidden(true)} />}
        </div>
        <div className={`${mobileList ? 'hidden' : 'flex'} md:flex flex-1 min-w-0 min-h-0 gap-3 overflow-x-auto`}>
          {shown.length === 0 ? (
            <div className="flex-1 grid place-items-center rounded-2xl border-2 border-dashed border-gray-200 text-center p-8">
              <div><div className="text-4xl mb-2">💬</div><p className="font-medium text-gray-700">Select a conversation</p>
                <p className="text-sm text-gray-400">Pick one from the list. Open more to chat with several visitors at once.</p></div>
            </div>
          ) : shown.map(id => (
            <ChatPane key={id} id={id} expanded={expanded === id} onClose={() => closeChat(id)}
              onToggleExpand={() => setExpanded(e => (e === id ? null : id))} onChanged={changed} />
          ))}
        </div>
      </div>
    </div>
  )
}

export default function WidgetChatsPage() {
  // A blank page here looked like "the list is not loading" — say plainly when the account just lacks the permission.
  return (
    <PermissionGate permission="widget_chat.handle" fallback={
      <div className="card p-6 text-sm text-gray-600" data-testid="widget-chats-denied">
        <p className="font-medium text-gray-800 mb-1">You don't have access to website live chats.</p>
        <p>Ask an admin to give your role the “Handle Website Live Chats” permission (Staff → Roles).</p>
        <Link to="/website-widget" className="inline-block mt-3 text-brand-600 underline">← Back to widgets</Link>
      </div>}>
      <Workspace />
    </PermissionGate>
  )
}
