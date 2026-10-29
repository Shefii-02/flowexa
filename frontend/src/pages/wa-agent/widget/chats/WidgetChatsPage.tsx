// Website chats workspace — /website-widget/chats. Served by backend-node (REST + socket events); up to 4
// conversations side by side, each with expand ⤢ and close ✕.
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { getError } from '@/utils'
import { PermissionGate } from '@/components/ui/PermissionGate'
import { nodeChatApi, type InboxTab } from '../api'
import InboxList from './InboxList'
import ChatPane from './ChatPane'
import { MAX_PANES, loadPanes, savePanes } from './util'

function Workspace() {
  const [open, setOpen] = useState<number[]>(loadPanes)
  const [expanded, setExpanded] = useState<number | null>(null)
  const [me, setMe] = useState({ muted: false, handling: 0, limit: 5 })
  const [socketUp, setSocketUp] = useState(false)
  const [forbidden, setForbidden] = useState(false)
  const [counts, setCounts] = useState<Record<InboxTab, number>>({ unread: 0, read: 0, closed: 0 })
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
          <h1 className="page-title">Website chats</h1>
          <p className="page-sub">Live conversations from your website widget — open up to {MAX_PANES} side by side.</p>
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
          <InboxList openIds={open} muted={me.muted} refreshKey={refreshKey} onOpen={openChat} onCounts={setCounts} onSocket={setSocketUp} onForbidden={() => setForbidden(true)} />
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
  return <PermissionGate permission="widget_chat.handle"><Workspace /></PermissionGate>
}
